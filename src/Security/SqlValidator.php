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

        // 4. Reject UNION-based data exfiltration
        if (preg_match('/\bUNION\b/i', $normalized)) {
            throw new \InvalidArgumentException('UNION queries are not allowed');
        }

        // 5. Reject dangerous SELECT variants: INTO OUTFILE/DUMPFILE, FOR UPDATE, LOCK IN SHARE MODE
        if (preg_match('/\b(INTO\s+(OUTFILE|DUMPFILE)|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b/i', $normalized)) {
            throw new \InvalidArgumentException('Disallowed keyword in query');
        }

        // 6. Reject dangerous MySQL functions that can be used for data exfiltration or DoS
        if (preg_match('/\b(LOAD\s+DATA|GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK|LOAD_FILE|BENCHMARK|SLEEP|EXTRACTVALUE|UPDATEXML)\s*\(/i', $normalized)) {
            throw new \InvalidArgumentException('Disallowed function in query');
        }

        // 7. Reject INFORMATION_SCHEMA access (schema/table enumeration, privilege escalation)
        if (preg_match('/\bINFORMATION_SCHEMA\b/i', $normalized)) {
            throw new \InvalidArgumentException('INFORMATION_SCHEMA access is not allowed');
        }

        // 8. Reject subqueries in FROM clause (derived tables) to prevent data exfiltration
        //    e.g. SELECT * FROM (SELECT password FROM admin_users) AS t
        if (preg_match('/\bFROM\s*\(/i', $normalized)) {
            throw new \InvalidArgumentException('Subqueries in FROM clause are not allowed');
        }
    }

    /**
     * Append a LIMIT clause if none exists.
     *
     * @param int $maxRows Maximum rows to return when no LIMIT is present
     * @return string The SQL with a guaranteed LIMIT clause
     */
    public static function enforceLimit(string $sql, int $maxRows = 1000): string
    {
        if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            return rtrim($sql, '; ') . " LIMIT {$maxRows}";
        }
        return $sql;
    }
}
