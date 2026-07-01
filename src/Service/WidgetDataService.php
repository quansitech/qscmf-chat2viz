<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Security\SqlValidator;

class WidgetDataService
{
    private DashboardRepositoryInterface $repo;

    /** @var callable */
    private $logger;

    public function __construct(
        DashboardRepositoryInterface $repo,
        ?callable $logger = null
    ) {
        $this->repo = $repo;
        $this->logger = $logger ?? static function (string $tag, string $detail): void {
            \Think\Log::write(
                sprintf('[chat2viz:dashboard] %s | %s', $tag, $detail),
                \Think\Log::ERR
            );
        };
    }

    /**
     * Query public widget data from published schema.
     *
     * Flow: rate limit → get published schema → extract SQL → validate →
     *       APCu cache → execute → cache → audit → return
     *
     * @throws DashboardException When schema not found or validation fails
     * @throws \InvalidArgumentException When SQL validation fails
     */
    public function queryPublicWidgetData(
        string $uid,
        string $widget_id,
        string $client_ip
    ): WidgetDataResult {
        // 1. Get published schema
        $dashboard = $this->repo->findByUid($uid);
        if ($dashboard === null) {
            throw new DashboardException('仪表盘不存在');
        }

        $schema = $this->repo->getPublishedSchema($uid);
        if ($schema === null) {
            throw new DashboardException('仪表盘未发布');
        }

        // 2. Extract SQL
        $sql = $this->extractWidgetSql($schema, $widget_id);
        if ($sql === null) {
            throw new DashboardException('组件不存在或未配置数据查询');
        }

        // 3. Validate SQL
        SqlValidator::validateSelectOnly($sql);
        $sql = SqlValidator::enforceLimit($sql, 1000);

        // 4. APCu cache check
        $version_id = $dashboard['published_version_id'] ?? 'none';
        $cache_key = 'chat2viz:widget:' . md5($uid . ':' . $version_id . ':' . $widget_id . ':' . $sql);

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cache_key);
            if ($cached !== false) {
                $this->auditWidgetQuery($uid, $widget_id, $sql, count($cached), $client_ip);
                return WidgetDataResult::fromCache($cached, $sql);
            }
        }

        // 5. Execute query
        $rows = $this->repo->executeRawQuery($sql);

        // 6. Store in APCu cache
        if (function_exists('apcu_store')) {
            apcu_store($cache_key, $rows, 600);
        }

        // 7. Audit
        $this->auditWidgetQuery($uid, $widget_id, $sql, count($rows), $client_ip);

        return WidgetDataResult::fromRawQuery($rows, $sql);
    }

    /**
     * Query draft widget data from current_schema (edit page).
     *
     * @throws DashboardException When schema not found or validation fails
     * @throws \InvalidArgumentException When SQL validation fails
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
     *
     * @return bool true if allowed, false if rate limited
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
     * Extract the SQL query from a widget in the schema.
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
