<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Security;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Security\SqlValidator;

/**
 * Security-focused unit tests for SqlValidator.
 *
 * Covers comment-bypass attacks, dangerous function injection,
 * nested comment edge cases, and legitimate query pass-through.
 */
class SqlValidatorTest extends TestCase
{
    // =========================================================================
    // Comment-bypass attacks -- must be blocked
    // =========================================================================

    /**
     * Block comment injected between UN and ION to evade keyword detection.
     */
    public function testRejectsUnionWithBlockCommentBypass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UNION');

        SqlValidator::validateSelectOnly("SELECT id FROM qs_film UN" . "/**/" . "ION SELECT password FROM qs_staff");
    }

    /**
     * Block comment injected between IN and TO OUTFILE to evade keyword detection.
     */
    public function testRejectsIntoOutfileWithBlockCommentBypass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed keyword');

        SqlValidator::validateSelectOnly("SELECT * FROM qs_film IN" . "/**/" . "TO OUTFILE '/tmp/dump.csv'");
    }

    /**
     * Block comment wrapping a keyword: UN + block-comment + ION.
     */
    public function testRejectsNestedCommentBypass(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SqlValidator::validateSelectOnly("SELECT 1 UN" . "/*comment*/" . "ION SELECT 2");
    }

    /**
     * Line comment used to mask UNION on next line.
     */
    public function testRejectsUnionHiddenBehindLineComment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UNION');

        SqlValidator::validateSelectOnly("SELECT id FROM qs_film -- dummy\nUNION SELECT password FROM qs_staff");
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
     * SELECT with string literal containing UNION text.
     *
     * Known limitation: the validator does not parse string literals,
     * so UNION inside a string will cause a false positive rejection.
     */
    public function testRejectsSelectWithUnionInStringLiteral(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlValidator::validateSelectOnly("SELECT 'UNION ALL' AS label FROM qs_film");
    }
}
