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
        $normalized = preg_replace('/\s+/', ' ', strtoupper(trim($sql)));

        // Strip leading block comments and line comments before checking statement type
        $normalized = preg_replace('#^(/\*.*?\*/|--[^\n]*\n|\s)*#s', '', $normalized);

        if (!str_starts_with($normalized, 'SELECT')) {
            throw new \InvalidArgumentException('Only SELECT queries are allowed');
        }

        // Reject multi-statement input (e.g. "SELECT 1; DELETE FROM users")
        if (strpos($normalized, ';') !== false) {
            throw new \InvalidArgumentException('Multi-statement queries are not allowed');
        }

        // Reject UNION-based data exfiltration
        if (preg_match('/\bUNION\b/i', $normalized)) {
            throw new \InvalidArgumentException('UNION queries are not allowed');
        }

        // Reject dangerous SELECT variants: INTO OUTFILE/DUMPFILE, FOR UPDATE, LOCK IN SHARE MODE
        if (preg_match('/\b(INTO\s+(OUTFILE|DUMPFILE)|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b/i', $normalized)) {
            throw new \InvalidArgumentException('Disallowed keyword in query');
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
