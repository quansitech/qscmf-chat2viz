<?php

namespace Qscmf\Chat2Viz\Security;

/**
 * SQL business-rule validation for widget data queries.
 *
 * SQL injection prevention is handled at the framework layer (PDO prepared
 * statements with ATTR_EMULATE_PREPARES=false). This class enforces business
 * constraints: SELECT-only queries and LIMIT protection.
 */
class SqlValidator
{
    /**
     * Ensure the query is a SELECT statement.
     *
     * This is a business constraint (only read operations allowed for widget
     * data), not a security measure.
     *
     * @throws \InvalidArgumentException if the query is not a SELECT
     */
    public static function validateSelectOnly(string $sql): void
    {
        // 0. Strip trailing semicolons/whitespace — legitimate SQL often ends with ';'
        //    (e.g. "SELECT ... LIMIT 1000;").  A genuine multi-statement attack
        //    ("SELECT 1; DELETE FROM t") still has a ';' in the middle after rtrim.
        $sql = rtrim($sql, " \t\n\r;");

        // 1. Globally strip ALL block comments (/* ... */) and line comments (-- ...)
        //    before any keyword analysis. This prevents bypass via UN/**/ION or
        //    IN/**/TO OUTFILE injection patterns.
        //    Loop to handle nested block comments (e.g. /* outer /* inner */ still */).
        do {
            $prev = $sql;
            $stripped = preg_replace('#/\*.*?\*/#s', '', $sql);
            $sql = $stripped ?? $sql;
        } while ($sql !== $prev);
        $stripped = $sql;
        $stripped = preg_replace('/--[^\n]*\n?/', ' ', $stripped ?? $sql);

        $normalized = preg_replace('/\s+/', ' ', strtoupper(trim($stripped ?? $sql)));

        // 2. Verify SELECT-only (after comment removal)
        if (!str_starts_with($normalized, 'SELECT')) {
            throw new \InvalidArgumentException('Only SELECT queries are allowed');
        }

        // 3. Reject multi-statement input (e.g. "SELECT 1; DELETE FROM users")
        if (strpos($normalized, ';') !== false) {
            throw new \InvalidArgumentException('Multi-statement queries are not allowed');
        }

        // 4. Allow UNION (legitimate for multi-metric aggregation) but reject
        //    UNION-based data exfiltration targeting sensitive system schemas.
        //    The real attack vector is `UNION SELECT ... FROM information_schema`
        //    or `UNION SELECT ... INTO OUTFILE`, not the UNION keyword itself.
        if (preg_match('/\bUNION\b/i', $normalized)) {
            // Block UNION combined with dangerous targets (already checked above
            // for INFORMATION_SCHEMA and INTO OUTFILE, but re-check here to be
            // explicit and provide a precise error message).
            if (self::referencesSystemSchema($normalized)
                || preg_match('/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $normalized)
                || preg_match('/\b(LOAD_FILE|BENCHMARK|SLEEP)\s*\(/i', $normalized)) {
                throw new \InvalidArgumentException('UNION with dangerous function/schema is not allowed');
            }
            // UNION is otherwise permitted — it's a standard SQL set operation.
        }

        // 5. Reject dangerous SELECT variants: INTO OUTFILE/DUMPFILE, FOR UPDATE, LOCK IN SHARE MODE
        if (preg_match('/\b(INTO\s+(OUTFILE|DUMPFILE)|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b/i', $normalized)) {
            throw new \InvalidArgumentException('Disallowed keyword in query');
        }

        // 6. Reject dangerous MySQL functions that can be used for data exfiltration or DoS
        if (preg_match('/\b(LOAD\s+DATA|GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK|LOAD_FILE|BENCHMARK|SLEEP|EXTRACTVALUE|UPDATEXML)\s*\(/i', $normalized)) {
            throw new \InvalidArgumentException('Disallowed function in query');
        }

        // 7. Reject system-schema access (schema/table enumeration, privilege
        //    escalation, credential exfiltration). INFORMATION_SCHEMA + mysql.*
        //    (user/password-hash tables) + sys.* + performance_schema.* are all
        //    blocked unconditionally — these are never legitimate in a widget
        //    data query.
        if (self::referencesSystemSchema($normalized)) {
            throw new \InvalidArgumentException('System schema access is not allowed');
        }

        // 8. Allow subqueries in FROM clause (derived tables) — LLMs use them
        //    for pivoting and multi-metric aggregation (e.g. SELECT ... FROM
        //    (SELECT metric, value FROM ...) AS t). The real exfiltration risk
        //    (information_schema, other DBs) is already blocked by rule 7.
        //    System-schema inside a subquery is explicitly caught here too.
        if (preg_match('/\bFROM\s*\(/i', $normalized)
            && self::referencesSystemSchema($normalized)) {
            throw new \InvalidArgumentException('System schema in subquery is not allowed');
        }
    }

    /**
     * Detect references to sensitive system schemas in a normalized SQL string.
     *
     * Matches: INFORMATION_SCHEMA, mysql.*, sys.*, performance_schema.*
     * (word-boundary + optional dot qualifier, case-insensitive).
     */
    private static function referencesSystemSchema(string $normalized): bool
    {
        return (bool) preg_match(
            '/\b(INFORMATION_SCHEMA|MYSQL|SYS|PERFORMANCE_SCHEMA)\b\s*\./i',
            $normalized
        );
    }

    /**
     * Ensure a LIMIT clause is present and capped at $maxRows.
     *
     * - No LIMIT → append `LIMIT $maxRows`.
     * - `LIMIT n` where n > $maxRows → replace n with $maxRows.
     * - `LIMIT offset, count` where count > $maxRows → cap count.
     * - LIMIT already ≤ $maxRows → unchanged.
     *
     * @param int $maxRows Maximum rows to return (default 1000)
     * @return string The SQL with a guaranteed, capped LIMIT clause
     */
    public static function enforceLimit(string $sql, int $maxRows = 1000): string
    {
        // Two-arg form: `LIMIT offset, count` — cap the count.
        if (preg_match('/\bLIMIT\s+(\d+)\s*,\s*(\d+)\s*$/i', $sql, $m)) {
            $count = (int) $m[2];
            if ($count > $maxRows) {
                return preg_replace(
                    '/\bLIMIT\s+\d+\s*,\s*\d+\s*$/i',
                    "LIMIT {$m[1]}, {$maxRows}",
                    $sql
                ) ?? $sql;
            }
            return $sql;
        }

        // One-arg form: `LIMIT n`.
        if (preg_match('/\bLIMIT\s+(\d+)\s*$/i', $sql, $m)) {
            $n = (int) $m[1];
            if ($n > $maxRows) {
                return preg_replace(
                    '/\bLIMIT\s+\d+\s*$/i',
                    "LIMIT {$maxRows}",
                    $sql
                ) ?? $sql;
            }
            return $sql;
        }

        // No LIMIT → append.
        return rtrim($sql, '; ') . " LIMIT {$maxRows}";
    }
}
