<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Security\SqlValidator;

/**
 * Widget data consumption service (contract §3, unify-widget-executor).
 *
 * A single executor switch (CHAT2VIZ_QUERY_EXECUTOR) governs ALL widget data
 * paths — view page, draft preview, and admin preview — uniformly:
 *  - native: self-execute the resolved query via prepared statement.
 *  - python (default): forward form B (full dsl) over Socket to Python
 *    DSLExecutor; for the view page this is the form-A→form-B bridge (uid is
 *    resolved to a dsl first), for draft/admin preview the dsl (from
 *    current_schema) is forwarded directly.
 *
 * Both paths converge on normalizeResult() so the output shape is identical
 * (masked_columns masking, APCu cache on the view page, audit log). The v3
 * DSL shape (queries{} + widgets{} decoupled by query_id) is the primary
 * path; the legacy flat widgets[]+inline-sql shape (extractWidgetSql) is kept
 * only as the v2 draft fallback, which always executes locally in PHP (v2 has
 * no queries{} structure to assemble a form-B frame for Python).
 */
class WidgetDataService
{
    private DashboardRepositoryInterface $repo;

    /** @var callable */
    private $logger;

    /** @var object|null SocketTransport-like (has transact() Generator method) for the python forwarding bridge. */
    private $socketTransport;

    /**
     * @param object|null $socketTransport A Qscmf\SseCore\SocketTransport instance (or
     *        test double) with a transact(array): \Generator method. Required for
     *        python mode; null in native mode.
     */
    public function __construct(
        DashboardRepositoryInterface $repo,
        ?callable $logger = null,
        ?object $socketTransport = null
    ) {
        $this->repo = $repo;
        $this->logger = $logger ?? static function (string $tag, string $detail): void {
            \Think\Log::write(
                sprintf('[chat2viz:dashboard] %s | %s', $tag, $detail),
                \Think\Log::ERR
            );
        };
        $this->socketTransport = $socketTransport;
    }

    /**
     * Resolve which executor mode is active. Static (not runtime fallback),
     * per contract §3.4.
     */
    private function executorMode(): string
    {
        $env = getenv('CHAT2VIZ_QUERY_EXECUTOR');
        return $env === 'native' ? 'native' : 'python';
    }

    /**
     * Query public widget data (v3 DSL path).
     *
     * Forms A/B (contract §3.2):
     *  - $dsl present (form B): use it directly.
     *  - $dsl absent + $uid present (form A): resolve current_schema by uid.
     *  - both absent: HTTP 400 (DashboardException).
     *
     * Under python mode (default): resolve DSL → forward form B over Socket.
     * Under native mode: extractQuery + bindSlicerParams + execute locally.
     *
     * @param array|null $dsl Form B DSL AST (when the caller carries it).
     * @param array $slicer_values slicer_id → value bindings.
     * @throws DashboardException
     */
    public function queryPublicWidgetData(
        string $uid,
        string $widget_id,
        string $client_ip,
        ?array $dsl = null,
        array $slicer_values = []
    ): WidgetDataResult {
        // Form A/B precedence: dsl wins over uid; both absent → 400.
        $resolvedDsl = $dsl;
        $resolvedUid = $uid;
        if ($resolvedDsl === null) {
            if ($resolvedUid === '') {
                throw new DashboardException('缺少 dsl 与 uid（需至少提供其一）', 400);
            }
            // Form A: resolve current_schema by uid.
            $dashboard = $this->repo->findByUid($resolvedUid);
            if ($dashboard === null) {
                throw new DashboardException('仪表盘不存在');
            }
            $schema_raw = $this->repo->getPublishedSchema($resolvedUid);
            if ($schema_raw === null) {
                throw new DashboardException('仪表盘未发布');
            }
            $resolvedDsl = is_string($schema_raw) ? json_decode($schema_raw, true) : $schema_raw;
            if (!is_array($resolvedDsl)) {
                throw new DashboardException('仪表盘数据异常');
            }
        } else {
            // Form B with dsl: uid may be empty (MCP/CLI). Validate the DSL has
            // the v3 top-level shape.
            if (!isset($resolvedDsl['version']) || !str_starts_with((string)$resolvedDsl['version'], '3.')) {
                throw new DashboardException('DSL 版本不兼容（需 3.x）', 400);
            }
        }

        // Unified dispatch: executeWidgetQuery handles python/native split
        // and runs normalizeResult. View page uses APCu cache (use_cache=true).
        return $this->executeWidgetQuery($resolvedDsl, $widget_id, $slicer_values, [
            'uid' => $resolvedUid,
            'client_ip' => $client_ip,
            'use_cache' => true,
        ]);
    }

    /**
     * Forward form B (dsl + widget_id + slicer_values) to Python DSLExecutor
     * over the Socket transport (contract §3.4). Transparent to the frontend
     * (which sends form A) and to DSLExecutor (which only ever receives a full
     * dsl).
     *
     * Returns BARE rows — post-processing (masked_columns, caching, audit) is
     * done by normalizeResult() via executeWidgetQuery(). This keeps python and
     * native paths producing identical output shapes.
     *
     * Defensive: validates every queries[*].raw_sql via SqlValidator before
     * forwarding, so even if the Python side doesn't validate, an attacker-
     * supplied DSL carrying a non-SELECT query is rejected at the bridge.
     *
     * @return array<array<string,mixed>> Bare rows from Python.
     * @throws DashboardException On transport missing (503) or python error (502).
     */
    private function executeViaPython(
        array $dsl,
        string $widget_id,
        array $slicer_values
    ): array {
        $transport = $this->socketTransport;
        if ($transport === null || !method_exists($transport, 'transact')) {
            throw new DashboardException('Python 执行器不可用（未配置 Socket 传输）', 503);
        }

        // Defensive SqlValidator pass on each query (defense-in-depth; the
        // python DSLExecutor is also expected to validate, but the bridge is
        // the last PHP-side checkpoint before the raw SQL leaves the process).
        $queries = $dsl['queries'] ?? [];
        if (is_array($queries)) {
            foreach ($queries as $q) {
                if (is_array($q) && isset($q['raw_sql']) && is_string($q['raw_sql'])) {
                    SqlValidator::validateSelectOnly($q['raw_sql']);
                }
            }
        }

        $frame = [
            'id' => bin2hex(random_bytes(16)),
            'method' => 'get_widget_data',
            'params' => [
                'widget_id' => $widget_id,
                'slicer_values' => $slicer_values,
                'dsl' => $dsl,
            ],
            'auth' => ['api_key' => (string) ($_ENV['CHAT2VIZ_API_KEY'] ?? getenv('CHAT2VIZ_API_KEY') ?? '')],

        ];

        $responseData = null;
        foreach ($transport->transact($frame) as $responseFrame) {
            if (!is_array($responseFrame)) continue;
            // Python socket returns the full frame: {id, type:"result", data:{success:true, result:{rows:[...]}}}
            // Unwrap: frame.data.result.rows → rows
            $data = $responseFrame['data'] ?? null;
            if (is_array($data)) {
                // Success path: data.success=true, data.result={rows,columns,total}
                if (isset($data['result']) && is_array($data['result'])) {
                    $responseData = $data['result'];
                    break;
                }
                // Error path: data.error={code, message}
                if (isset($data['error'])) {
                    $responseData = ['error' => true, 'error_msg' => $data['error']['message'] ?? $data['error']];
                    break;
                }
            }
            // Fallback: some responses put rows/error at top level (legacy compat)
            if (isset($responseFrame['rows']) || isset($responseFrame['error'])) {
                $responseData = $responseFrame;
                break;
            }
        }

        if (!is_array($responseData)) {
            throw new DashboardException('Python 执行器未返回有效响应', 502);
        }
        if (isset($responseData['error'])) {
            throw new DashboardException(
                'Python 执行器报错：' . (string) ($responseData['error_msg'] ?? $responseData['error']),
                502
            );
        }
        $rows = $responseData['rows'] ?? [];
        if (!is_array($rows)) {
            throw new DashboardException('Python 执行器返回数据异常', 502);
        }

        return $rows;
    }

    /**
     * Unified output post-processing (the "middleware" that makes python and
     * native paths produce identical WidgetDataResult shapes). Applied to bare
     * rows from either path, in order:
     *  1. applyMaskedColumns — mask declared columns (security; python path
     *     previously skipped this). Idempotent if Python already masked.
     *  2. APCu cache — read on hit (returns fromCache), write on miss. Only
     *     when $context['use_cache'] is true (view page). Draft/admin preview
     *     pass use_cache=false to avoid polluting the cache with unpublished
     *     data.
     *  3. auditWidgetQuery — record the query for both paths.
     *  4. wrap into WidgetDataResult (python path tags sql as '(python:forwarded)').
     *
     * @param array<array<string,mixed>> $rows Bare rows from the executor.
     * @param array<string,mixed>|null $query The resolved QuerySpec (for masked_columns + sql).
     * @param array{uid:string, client_ip:string, use_cache:bool, sql:string, is_python:bool} $context
     */
    private function normalizeResult(array $rows, ?array $query, array $context): WidgetDataResult
    {
        // 1. Masking (applies to both paths; idempotent for python-already-masked).
        $rows = $this->applyMaskedColumns($rows, $query ?? []);

        $uid = $context['uid'];
        $widget_id = $context['widget_id'];
        $client_ip = $context['client_ip'];
        $sql = $context['sql'];
        $use_cache = $context['use_cache'];

        // 2. Cache (view page only). Draft/admin preview never caches.
        if ($use_cache && function_exists('apcu_fetch')) {
            $cache_key = $context['cache_key'] ?? null;
            if ($cache_key !== null) {
                $cached = apcu_fetch($cache_key);
                if ($cached !== false) {
                    $masked = $this->applyMaskedColumns($cached, $query ?? []);
                    $this->auditWidgetQuery($uid, $widget_id, $sql, count($masked), $client_ip);
                    return WidgetDataResult::fromCache($masked, $sql);
                }
            }
        }

        // 3. Audit (both paths).
        $this->auditWidgetQuery($uid, $widget_id, $sql, count($rows), $client_ip);

        // 4. Wrap. Write-through cache for the view page.
        if ($use_cache && function_exists('apcu_store') && ($context['cache_key'] ?? null) !== null) {
            apcu_store($context['cache_key'], $rows, 600);
        }

        return WidgetDataResult::fromRawQuery($rows, $sql);
    }

    /**
     * Unified executor dispatch — the SINGLE entry point that decides python
     * vs native and runs normalizeResult. Both queryPublicWidgetData and
     * queryDraftWidgetData (v3 path) route through here so the executor mode
     * switch applies uniformly to all three widget-data paths (contract:
     * unify-widget-executor).
     *
     * @param array<string,mixed> $dsl Resolved v3 DSL (form B).
     * @param array{uid:string, client_ip:string, use_cache:bool} $context
     */
    private function executeWidgetQuery(
        array $dsl,
        string $widget_id,
        array $slicer_values,
        array $context
    ): WidgetDataResult {
        $query = $this->extractQuery($dsl, $widget_id);
        if ($query === null) {
            throw new DashboardException('组件不存在或未配置数据查询');
        }

        $rawSql = $query['raw_sql'] ?? '';
        $bindParams = $this->resolveBindParams($query, $slicer_values);

        if ($this->executorMode() === 'python') {
            $rows = $this->executeViaPython($dsl, $widget_id, $slicer_values);
            return $this->normalizeResult($rows, $query, [
                'uid' => $context['uid'],
                'widget_id' => $widget_id,
                'client_ip' => $context['client_ip'],
                'use_cache' => $context['use_cache'],
                'sql' => '(python:forwarded)',
                'is_python' => true,
            ]);
        }

        // Native mode: validate + enforce limit, then execute locally.
        SqlValidator::validateSelectOnly($rawSql);
        $rawSql = SqlValidator::enforceLimit($rawSql, 1000);

        $cache_key = null;
        if ($context['use_cache']) {
            $version_id = $dsl['version'] ?? 'none';
            $paramHash = md5(json_encode($bindParams, JSON_UNESCAPED_UNICODE));
            $cache_key = 'chat2viz:widget:' . md5($context['uid'] . ':' . $version_id . ':' . $widget_id . ':' . $rawSql . ':' . $paramHash);
        }

        $rows = $this->repo->executeBoundQuery($rawSql, $bindParams);
        return $this->normalizeResult($rows, $query, [
            'uid' => $context['uid'],
            'widget_id' => $widget_id,
            'client_ip' => $context['client_ip'],
            'use_cache' => $context['use_cache'],
            'sql' => $rawSql,
            'cache_key' => $cache_key,
            'is_python' => false,
        ]);
    }

    /**
     * Resolve slicer options (contract §3.3).
     *
     * Looks up the slicer's options_query_id, executes it (no params), and
     * returns the rows. The column-mapping rule (first→value, second→label,
     * absent→label=value) is applied by the frontend.
     *
     * @return array<array<string,mixed>>
     */
    public function querySlicerOptions(
        string $slicer_id,
        ?array $dsl = null,
        string $uid = ''
    ): array {
        $resolvedDsl = $dsl;
        if ($resolvedDsl === null) {
            if ($uid === '') {
                return [];
            }
            $schema_raw = $this->repo->getPublishedSchema($uid);
            if ($schema_raw === null) {
                return [];
            }
            $resolvedDsl = is_string($schema_raw) ? json_decode($schema_raw, true) : $schema_raw;
        }
        if (!is_array($resolvedDsl)) {
            return [];
        }

        $slicers = $resolvedDsl['slicers'] ?? [];
        if (!is_array($slicers)) {
            return [];
        }

        $options_query_id = null;
        foreach ($slicers as $s) {
            if (is_array($s) && ($s['slicer_id'] ?? '') === $slicer_id) {
                $options_query_id = $s['options_query_id'] ?? null;
                break;
            }
        }
        if (!is_string($options_query_id) || $options_query_id === '') {
            return [];
        }

        $queries = $resolvedDsl['queries'] ?? [];
        if (!is_array($queries) || !isset($queries[$options_query_id])) {
            return [];
        }
        $query = $queries[$options_query_id];
        $sql = $query['raw_sql'] ?? null;
        if (!is_string($sql) || $sql === '') {
            return [];
        }

        SqlValidator::validateSelectOnly($sql);
        $sql = SqlValidator::enforceLimit($sql, 1000);

        return $this->repo->executeBoundQuery($sql, []);
    }

    /**
     * Extract a v3 QuerySpec for a widget from the DSL.
     *
     * Looks up widgets[widget_id].query_id, then queries[query_id]. Returns
     * the full query array (raw_sql + params + masked_columns) or null.
     *
     * @return array<string,mixed>|null
     */
    public function extractQuery(array $dsl, string $widget_id): ?array
    {
        $widgets = $dsl['widgets'] ?? [];
        if (!is_array($widgets)) {
            return null;
        }
        $widget = $widgets[$widget_id] ?? null;
        if (!is_array($widget)) {
            return null;
        }
        $query_id = $widget['query_id'] ?? null;
        if (!is_string($query_id) || $query_id === '') {
            return null;
        }
        $queries = $dsl['queries'] ?? [];
        if (!is_array($queries) || !isset($queries[$query_id])) {
            return null;
        }
        return $queries[$query_id];
    }

    /**
     * Resolve bind parameters for a query from slicer_values + declared defaults.
     *
     * Returns a map of `:name` → value suitable for executeBoundQuery (PDO
     * prepared binding). This REPLACES the legacy bindSlicerParams string-
     * interpolation approach (which was an SQL-injection surface). The raw_sql
     * is passed unchanged to executeBoundQuery; values are bound, not interpolated.
     *
     * @param array<string,mixed> $query The QuerySpec (raw_sql + params[])
     * @param array<string,mixed> $slicer_values slicer_id → value
     * @return array<string, mixed> `:name` → bind value
     */
    public function resolveBindParams(array $query, array $slicer_values): array
    {
        $params = $query['params'] ?? [];
        $bind = [];
        if (is_array($params)) {
            foreach ($params as $p) {
                if (!is_array($p)) continue;
                $name = $p['name'] ?? null;
                if (!is_string($name)) continue;
                // slicer_values is keyed by param name (the slicer's
                // target_query_params maps query_id → param_name).
                $value = $slicer_values[$name] ?? ($p['default'] ?? null);
                $bind[':' . $name] = $value;
            }
        }
        return $bind;
    }

    /**
     * @deprecated Use resolveBindParams() + executeBoundQuery() instead. This
     * legacy method is kept only for the draft preview path's v3 fallback and
     * existing tests; it delegates to resolveBindParams and is safe because the
     * draft path has no user-supplied slicer values.
     */
    public function bindSlicerParams(array $query, array $slicer_values): string
    {
        // Render the SQL with inline values for the draft preview path (which
        // carries no user input). NOT used for the public consumption path.
        $sql = $query['raw_sql'] ?? '';
        $bind = $this->resolveBindParams($query, $slicer_values);
        return preg_replace_callback(
            '/(?<![:\w]):([A-Za-z_]\w*)/',
            static function (array $m) use ($bind): string {
                $key = ':' . $m[1];
                $value = $bind[$key] ?? null;
                if ($value === null) return 'NULL';
                if (is_int($value) || is_float($value)) return (string)$value;
                $escaped = str_replace(["\\", "'"], ["\\\\", "''"], (string)$value);
                return "'" . $escaped . "'";
            },
            $sql
        ) ?? $sql;
    }

    /**
     * Apply masked_columns masking to the result rows (contract §6.3).
     *
     * Each column listed in masked_columns is replaced with a masked value
     * (the middle portion replaced with `****`). This is the native-mode
     * masking; under python mode the DSLExecutor applies masking itself.
     *
     * Alias-aware: builds a logical-name → result-key map from the raw_sql
     * SELECT list so that `SELECT phone AS p` with `masked_columns: ["phone"]`
     * correctly masks the `p` result key. Falls back to direct key match when
     * alias resolution is inconclusive (defensive).
     *
     * @param array<array<string,mixed>> $rows
     * @param array<string,mixed> $query
     * @return array<array<string,mixed>>
     */
    public function applyMaskedColumns(array $rows, array $query): array
    {
        $masked = $query['masked_columns'] ?? [];
        if (!is_array($masked) || count($masked) === 0) {
            return $rows;
        }

        // Build the set of result keys to mask. Start with the declared names,
        // then expand via alias resolution from the raw_sql SELECT list.
        $maskedSet = array_flip($masked);
        $rawSql = is_string($query['raw_sql'] ?? null) ? $query['raw_sql'] : '';
        $aliasMap = $this->resolveSelectAliases($rawSql);
        foreach ($masked as $col) {
            // If the declared column appears as a SELECT alias source, the
            // result key is the alias target — add it to the mask set.
            if (isset($aliasMap[$col])) {
                $maskedSet[$aliasMap[$col]] = true;
            }
        }

        foreach ($rows as &$row) {
            if (!is_array($row)) continue;
            foreach ($row as $col => $val) {
                if (!isset($maskedSet[$col])) continue;
                if (!is_string($val) || $val === '') continue;
                $row[$col] = $this->maskValue($val);
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Parse the SELECT list of a SQL query and build a map of
     * source-column-name → result-key (alias or bare column name).
     *
     * Handles `col`, `table.col`, `col AS alias`, `col alias` forms. Returns
     * `['source_col' => 'result_key']`. When no alias, source == result key.
     * Defensive: returns an empty map on parse failure (callers fall back to
     * direct key matching).
     *
     * @return array<string,string>
     */
    private function resolveSelectAliases(string $sql): array
    {
        // Extract the SELECT list (between SELECT and FROM, case-insensitive).
        if (!preg_match('/\bSELECT\b\s+(.*?)\s+\bFROM\b/is', $sql, $m)) {
            return [];
        }
        $selectList = $m[1];
        $map = [];
        // Split on top-level commas (no subquery-awareness; sufficient for
        // the flat SELECT lists the contract produces).
        $cols = preg_split('/\s*,\s*/', $selectList) ?: [];
        foreach ($cols as $colExpr) {
            $colExpr = trim($colExpr);
            if ($colExpr === '' || $colExpr === '*') continue;
            // `expr AS alias` or `expr alias` (alias is a bare identifier).
            if (preg_match('/^.*?\bAS\s+([A-Za-z_]\w*)\s*$/i', $colExpr, $a)) {
                $alias = $a[1];
                $source = $this->extractBareColumn($colExpr);
                if ($source !== null) $map[$source] = $alias;
            } elseif (preg_match('/^([A-Za-z_]\w*\.)([A-Za-z_]\w*)\s+([A-Za-z_]\w*)\s*$/', $colExpr, $a)) {
                // `table.col alias` (implicit alias, no AS).
                $map[$a[2]] = $a[3];
            } elseif (preg_match('/^([A-Za-z_]\w*)\s*$/', $colExpr, $a)) {
                // bare column name → result key is itself.
                $map[$a[1]] = $a[1];
            } elseif (preg_match('/^([A-Za-z_]\w*)\.([A-Za-z_]\w*)\s*$/', $colExpr, $a)) {
                // `table.col` → result key is `col`.
                $map[$a[2]] = $a[2];
            }
        }
        return $map;
    }

    /**
     * Extract the bare column name from a `table.col` or `col` expression
     * (the leftmost identifier before any AS/alias).
     */
    private function extractBareColumn(string $expr): ?string
    {
        if (preg_match('/([A-Za-z_]\w*)\s*(?:\.\s*([A-Za-z_]\w*))?\s*(?:\bAS\b|\s)/i', $expr, $m)) {
            // If qualified (table.col), return col; else return the single name.
            return $m[2] ?? $m[1];
        }
        return null;
    }

    private function maskValue(string $val): string
    {
        $len = strlen($val);
        if ($len <= 2) return str_repeat('*', $len);
        if ($len <= 6) return substr($val, 0, 1) . str_repeat('*', $len - 2) . substr($val, -1);
        // Keep first 3 + last 4, mask the middle (phone-style).
        return substr($val, 0, 3) . '****' . substr($val, -4);
    }

    /**
     * Query draft widget data from current_schema (edit page preview).
     *
     * Supports both the v3 DSL shape (queries/widgets decoupled) and the
     * legacy v2 flat widgets[]+sql shape (defensive for un-migrated drafts).
     *
     * @throws DashboardException
     */
    public function queryDraftWidgetData(string $uid, string $widget_id): WidgetDataResult
    {
        $dashboard = $this->repo->findByUid($uid);
        if ($dashboard === null) {
            throw new DashboardException('仪表盘不存在');
        }

        $schema_raw = $dashboard['current_schema'] ?? null;
        $schema = is_string($schema_raw) ? json_decode($schema_raw, true) : $schema_raw;
        if (!is_array($schema)) {
            throw new DashboardException('仪表盘数据异常');
        }

        // v3 path: route through the unified executor dispatch so the draft
        // preview follows CHAT2VIZ_QUERY_EXECUTOR (python or native) just like
        // the view page (unify-widget-executor). use_cache=false — draft data
        // is unpublished and must not pollute the view-page APCu cache.
        $query = $this->extractQuery($schema, $widget_id);
        if ($query !== null) {
            return $this->executeWidgetQuery($schema, $widget_id, [], [
                'uid' => $uid,
                'client_ip' => '-',
                'use_cache' => false,
            ]);
        }

        // Legacy v2 fallback (flat widgets[]+sql).
        $sql = $this->extractWidgetSql($schema, $widget_id);
        if ($sql === null) {
            throw new DashboardException('组件不存在或未配置数据查询');
        }

        SqlValidator::validateSelectOnly($sql);
        $sql = SqlValidator::enforceLimit($sql, 1000);

        $rows = $this->repo->executeRawQuery($sql);

        return WidgetDataResult::fromRawQuery($rows, $sql);
    }

    /**
     * Check IP-based rate limit for public endpoints.
     */
    public function checkRateLimit(string $client_ip): bool
    {
        $apcu_available = function_exists('apcu_fetch') && function_exists('apcu_store');
        $max_requests = $apcu_available ? 60 : 30;
        $window = 60;

        if ($apcu_available) {
            $key = 'chat2viz:ratelimit:' . md5($client_ip);
            apcu_add($key, 0, $window);
            $count = apcu_inc($key, 1);
            if ($count === false) {
                apcu_store($key, 1, $window);
                $count = 1;
            }
            if ($count > $max_requests) {
                return false;
            }
        } else {
            $tmp_dir = sys_get_temp_dir();
            $counter_file = $tmp_dir . '/chat2viz_rl_' . md5($client_ip . ':chat2viz:' . __FILE__);
            $now = time();

            $fp = @fopen($counter_file, 'c+');
            if ($fp === false) {
                return true;
            }
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $data = @json_decode($raw, true);
            if (!is_array($data) || ($now - ($data['start'] ?? 0)) >= $window) {
                $data = ['count' => 1, 'start' => $now];
            } else {
                if (($data['count'] ?? 0) >= $max_requests) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return false;
                }
                $data['count']++;
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return true;
    }

    /**
     * Write an audit log entry for widget data queries.
     */
    public function auditWidgetQuery(
        string $uid,
        string $widget_id,
        string $sql,
        int $row_count,
        string $client_ip = '-'
    ): void {
        $entry = json_encode([
            'action'        => 'widget_data_query',
            'dashboard_uid' => $uid,
            'widget_id'     => $widget_id,
            'sql_hash'      => md5($sql),
            'row_count'     => $row_count,
            'ip'            => $client_ip,
            'time'          => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        \Think\Log::write($entry, 'INFO');
    }

    /**
     * Extract the SQL query from a legacy v2 widget in the schema.
     *
     * @return string|null The SQL string, or null if widget not found or has no SQL
     */
    public function extractWidgetSql(array $schema, string $widget_id): ?string
    {
        $widgets = $schema['widgets'] ?? [];
        if (!is_array($widgets)) {
            return null;
        }

        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if (($widget['id'] ?? '') === $widget_id) {
                $sql = $widget['sql'] ?? null;
                if (is_string($sql) && trim($sql) !== '') {
                    return $sql;
                }
                return null;
            }
        }

        return null;
    }
}
