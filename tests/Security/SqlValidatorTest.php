<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Security;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Security\SqlValidator;

/**
 * Security-focused unit tests for SqlValidator.
 *
 * Policy (per SqlValidator rule 4):
 *   - UNION alone is ALLOWED (legitimate SQL set operation; NL2SQL uses it
 *     for multi-metric aggregation).
 *   - UNION combined with dangerous targets (INFORMATION_SCHEMA, INTO OUTFILE,
 *     LOAD_FILE, BENCHMARK, SLEEP) is REJECTED.
 *
 * Table-level access control is enforced at the SQL execution layer (DB user
 * permissions + per-widget schema allowlist), not by SqlValidator.
 *
 * Covers comment-bypass attacks, dangerous function injection,
 * nested comment edge cases, and legitimate query pass-through.
 */
class SqlValidatorTest extends TestCase
{
    // =========================================================================
    // Comment-bypass attacks -- bypass attempts are NEUTRALIZED, not rejected
    //
    // The validator strips block/line comments BEFORE keyword analysis, so
    // UN/**/ION, UN/*comment*/ION, --dummy\nUNION all collapse into a plain
    // UNION query. Under the current policy (UNION allowed), these bypass
    // attempts pass validation. The defense-in-depth against exfiltration is
    // at the SQL execution layer (DB user permissions, widget schema
    // allowlist), not at the keyword-validator layer.
    // =========================================================================

    /**
     * Block comment between UN and ION collapses to a plain UNION query.
     * Validator allows UNION alone, so no exception is thrown.
     */
    public function testNeutralizesUnionWithBlockCommentBypass(): void
    {
        SqlValidator::validateSelectOnly("SELECT id FROM qs_film UN" . "/**/" . "ION SELECT password FROM qs_staff");
        $this->assertTrue(true);
    }

    /**
     * Block comment between IN and TO OUTFILE: even after stripping,
     * the residual INTO OUTFILE keyword is still rejected by rule 5.
     */
    public function testRejectsIntoOutfileWithBlockCommentBypass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed keyword');

        SqlValidator::validateSelectOnly("SELECT * FROM qs_film IN" . "/**/" . "TO OUTFILE '/tmp/dump.csv'");
    }

    /**
     * Block comment wrapping UN/ION collapses to a plain UNION query.
     * Allowed under current policy.
     */
    public function testNeutralizesNestedCommentBypass(): void
    {
        SqlValidator::validateSelectOnly("SELECT 1 UN" . "/*comment*/" . "ION SELECT 2");
        $this->assertTrue(true);
    }

    /**
     * Line comment used to mask UNION on next line is stripped before
     * keyword analysis. The residual UNION query is allowed.
     */
    public function testNeutralizesUnionHiddenBehindLineComment(): void
    {
        SqlValidator::validateSelectOnly("SELECT id FROM qs_film -- dummy\nUNION SELECT password FROM qs_staff");
        $this->assertTrue(true);
    }

    /**
     * MySQL conditional comment wrapping UNION keyword is stripped,
     * leaving a harmless query (no UNION). The validator passes because
     * the attack vector is neutralized by comment removal.
     */
    public function testStripsMysqlConditionalCommentContainingUnion(): void
    {
        // After stripping /*!50000 UNION*/, the query becomes:
        // "SELECT id FROM qs_film  SELECT password FROM qs_staff"
        // This is a MySQL syntax error at runtime but harmless.
        SqlValidator::validateSelectOnly(
            "SELECT id FROM qs_film /*!50000 UNION*/ SELECT password FROM qs_staff"
        );
        $this->assertTrue(true);
    }

    // =========================================================================
    // UNION + dangerous targets -- must be blocked (rule 4 second clause)
    // =========================================================================

    /**
     * UNION targeting INFORMATION_SCHEMA enables schema/table enumeration.
     */
    public function testRejectsUnionWithInformationSchema(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UNION with dangerous');

        SqlValidator::validateSelectOnly(
            "SELECT id FROM qs_film UNION SELECT table_name FROM information_schema.tables"
        );
    }

    /**
     * UNION with INTO OUTFILE enables file system exfiltration.
     */
    public function testRejectsUnionWithIntoOutfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UNION with dangerous');

        SqlValidator::validateSelectOnly(
            "SELECT id FROM qs_film UNION SELECT password FROM qs_staff INTO OUTFILE '/tmp/dump.csv'"
        );
    }

    /**
     * UNION with LOAD_FILE enables sensitive file reading.
     */
    public function testRejectsUnionWithLoadFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UNION with dangerous');

        SqlValidator::validateSelectOnly(
            "SELECT id FROM qs_film UNION SELECT LOAD_FILE('/etc/passwd')"
        );
    }

    // =========================================================================
    // Dangerous function injection -- must be blocked
    // =========================================================================

    /**
     * LOAD_FILE reading sensitive files.
     */
    public function testRejectsLoadFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed function');

        SqlValidator::validateSelectOnly("SELECT LOAD_FILE('/etc/passwd') FROM qs_film");
    }

    /**
     * BENCHMARK used for DoS via CPU exhaustion.
     */
    public function testRejectsBenchmark(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed function');

        SqlValidator::validateSelectOnly("SELECT BENCHMARK(10000000, SHA1('x')) FROM qs_film");
    }

    /**
     * SLEEP used for time-based blind injection.
     */
    public function testRejectsSleep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed function');

        SqlValidator::validateSelectOnly("SELECT SLEEP(5) FROM qs_film");
    }

    /**
     * EXTRACTVALUE used for error-based data exfiltration.
     */
    public function testRejectsExtractvalue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed function');

        SqlValidator::validateSelectOnly("SELECT EXTRACTVALUE(1, CONCAT(0x7e, (SELECT version()))) FROM qs_film");
    }

    /**
     * UPDATEXML used for error-based data exfiltration.
     */
    public function testRejectsUpdatexml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed function');

        SqlValidator::validateSelectOnly("SELECT UPDATEXML(1, CONCAT(0x7e, (SELECT version())), 1) FROM qs_film");
    }

    /**
     * Dangerous function with block comment between name and parenthesis.
     */
    public function testRejectsLoadFileWithCommentBeforeParenthesis(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed function');

        SqlValidator::validateSelectOnly("SELECT LOAD_FILE" . "/*comment*/" . "('/etc/passwd') FROM qs_film");
    }

    // =========================================================================
    // Legitimate queries -- must pass
    // =========================================================================

    /**
     * Normal SELECT with WHERE clause.
     */
    public function testAllowsNormalSelect(): void
    {
        SqlValidator::validateSelectOnly('SELECT name FROM users WHERE id = 1');
        $this->assertTrue(true);
    }

    /**
     * SELECT with aggregate functions (COUNT, SUM, AVG).
     */
    public function testAllowsAggregateFunctions(): void
    {
        SqlValidator::validateSelectOnly('SELECT COUNT(*) AS total, AVG(amount) AS avg_amount FROM qs_payment');
        $this->assertTrue(true);
    }

    /**
     * SELECT with JOINs and subquery.
     */
    public function testAllowsJoinWithSubquery(): void
    {
        SqlValidator::validateSelectOnly(
            "SELECT f.title FROM qs_film f WHERE f.film_id IN (SELECT fa.film_id FROM qs_film_actor fa)"
        );
        $this->assertTrue(true);
    }

    /**
     * SELECT with legitimate block comment (documenting a complex query).
     */
    public function testAllowsSelectWithHarmlessBlockComment(): void
    {
        SqlValidator::validateSelectOnly(
            "SELECT /* get film count */ COUNT(*) AS total FROM qs_film"
        );
        $this->assertTrue(true);
    }

    /**
     * SELECT with string literal containing UNION text. Under the current
     * policy (UNION allowed), this is no longer a false positive — it's
     * simply a legitimate query whose label happens to spell "UNION ALL".
     */
    public function testAllowsSelectWithUnionInStringLiteral(): void
    {
        SqlValidator::validateSelectOnly("SELECT 'UNION ALL' AS label FROM qs_film");
        $this->assertTrue(true);
    }

    /**
     * UNION alone (no dangerous target) is allowed — NL2SQL uses it
     * legitimately for multi-metric aggregation.
     */
    public function testAllowsUnionAlone(): void
    {
        SqlValidator::validateSelectOnly(
            "SELECT title FROM qs_film UNION SELECT title FROM qs_film_category"
        );
        $this->assertTrue(true);
    }

    // =========================================================================
    // System-schema access — must be blocked (rule 7, code-review H5)
    // =========================================================================

    public function testRejectsMysqlSchemaAccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('System schema');
        SqlValidator::validateSelectOnly("SELECT user, host FROM mysql.user");
    }

    public function testRejectsSysSchemaAccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlValidator::validateSelectOnly("SELECT * FROM sys.schema_table_statistics");
    }

    public function testRejectsPerformanceSchemaAccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlValidator::validateSelectOnly("SELECT * FROM performance_schema.threads");
    }

    public function testRejectsUnionWithMysqlSchema(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlValidator::validateSelectOnly(
            "SELECT id FROM qs_film UNION SELECT password FROM mysql.user"
        );
    }

    public function testRejectsSystemSchemaInSubquery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlValidator::validateSelectOnly(
            "SELECT t.* FROM (SELECT user FROM mysql.user) AS t"
        );
    }

    // =========================================================================
    // enforceLimit — cap existing LIMIT (code-review H3/MEDIUM)
    // =========================================================================

    public function testEnforceLimitAppendsWhenAbsent(): void
    {
        $out = SqlValidator::enforceLimit('SELECT * FROM t', 1000);
        $this->assertStringEndsWith('LIMIT 1000', $out);
    }

    public function testEnforceLimitCapsOversizedSingleArg(): void
    {
        $out = SqlValidator::enforceLimit('SELECT * FROM t LIMIT 999999', 1000);
        $this->assertSame('SELECT * FROM t LIMIT 1000', $out);
    }

    public function testEnforceLimitPreservesSmallLimit(): void
    {
        $out = SqlValidator::enforceLimit('SELECT * FROM t LIMIT 100', 1000);
        $this->assertSame('SELECT * FROM t LIMIT 100', $out);
    }

    public function testEnforceLimitCapsTwoArgFormCount(): void
    {
        $out = SqlValidator::enforceLimit('SELECT * FROM t LIMIT 0, 999999', 1000);
        $this->assertSame('SELECT * FROM t LIMIT 0, 1000', $out);
    }

    public function testEnforceLimitPreservesSmallTwoArg(): void
    {
        $out = SqlValidator::enforceLimit('SELECT * FROM t LIMIT 10, 50', 1000);
        $this->assertSame('SELECT * FROM t LIMIT 10, 50', $out);
    }
}
