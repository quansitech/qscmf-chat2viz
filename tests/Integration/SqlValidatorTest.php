<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Security\SqlValidator;

/**
 * SqlValidator integration tests using Sakila SQL patterns.
 *
 * Tests business-rule validation (SELECT-only, LIMIT enforcement) against
 * real SQL patterns that the NL2SQL service would generate from the 20
 * sakila-test-prompts.md questions.
 *
 * @see sakila-test-prompts.md
 */
class SqlValidatorTest extends TestCase
{
    // =========================================================================
    // SELECT-only validation
    // =========================================================================

    /**
     * Simple prompt #1: "总共有多少部电影" -> COUNT aggregate
     */
    public function testAllowsCountAggregate(): void
    {
        $sql = 'SELECT COUNT(*) AS total FROM qs_film';

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true); // No exception means pass
    }

    /**
     * Simple prompt #3: "列出所有电影分级及其对应的电影数量" -> GROUP BY
     */
    public function testAllowsGroupBy(): void
    {
        $sql = 'SELECT rating, COUNT(*) AS film_count FROM qs_film GROUP BY rating ORDER BY film_count DESC';

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true);
    }

    /**
     * Simple prompt #4: "租赁价格最贵的10部电影" -> ORDER BY + LIMIT
     */
    public function testAllowsOrderByLimit(): void
    {
        $sql = 'SELECT title, rental_rate FROM qs_film ORDER BY rental_rate DESC LIMIT 10';

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true);
    }

    /**
     * Medium prompt #6: "出演电影最多的前5位演员" -> JOIN + GROUP BY + ORDER BY
     */
    public function testAllowsMultiTableJoin(): void
    {
        $sql = <<<SQL
            SELECT a.first_name, a.last_name, COUNT(fa.film_id) AS film_count
            FROM qs_actor a
            JOIN qs_film_actor fa ON a.actor_id = fa.actor_id
            GROUP BY a.actor_id, a.first_name, a.last_name
            ORDER BY film_count DESC
            LIMIT 5
            SQL;

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true);
    }

    /**
     * Medium prompt #7: "每个电影分类各有多少部电影" -> three-table JOIN
     */
    public function testAllowsThreeTableJoin(): void
    {
        $sql = <<<SQL
            SELECT c.name AS category, COUNT(fc.film_id) AS film_count
            FROM qs_category c
            JOIN qs_film_category fc ON c.category_id = fc.category_id
            JOIN qs_film f ON fc.film_id = f.film_id
            GROUP BY c.category_id, c.name
            ORDER BY film_count DESC
            SQL;

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true);
    }

    /**
     * Medium prompt #9: "2005年6月每天的租金收入总额" -> date function + SUM
     */
    public function testAllowsDateFunctionsAndSum(): void
    {
        $sql = <<<SQL
            SELECT DATE(p.payment_date) AS day, SUM(p.amount) AS daily_revenue
            FROM qs_payment p
            WHERE p.payment_date >= '2005-06-01' AND p.payment_date < '2005-07-01'
            GROUP BY DATE(p.payment_date)
            ORDER BY day
            SQL;

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true);
    }

    /**
     * Medium prompt #10: "哪些电影还没有被租借过" -> LEFT JOIN pattern
     */
    public function testAllowsLeftJoin(): void
    {
        $sql = <<<SQL
            SELECT f.title
            FROM qs_film f
            LEFT JOIN qs_inventory i ON f.film_id = i.film_id
            LEFT JOIN qs_rental r ON i.inventory_id = r.inventory_id
            WHERE r.rental_id IS NULL
            SQL;

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true);
    }

    /**
     * Complex prompt #11: "每个国家的客户数量" -> 4-table join chain
     */
    public function testAllowsFourTableJoinChain(): void
    {
        $sql = <<<SQL
            SELECT co.country, COUNT(c.customer_id) AS customer_count
            FROM qs_country co
            JOIN qs_city ci ON co.country_id = ci.country_id
            JOIN qs_address a ON ci.city_id = a.city_id
            JOIN qs_customer c ON a.address_id = c.address_id
            GROUP BY co.country_id, co.country
            ORDER BY customer_count DESC
            SQL;

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true);
    }

    /**
     * Complex prompt #14: "哪些演员既演过Action又演过Comedy" -> HAVING COUNT
     */
    public function testAllowsHavingCount(): void
    {
        $sql = <<<SQL
            SELECT a.first_name, a.last_name
            FROM qs_actor a
            JOIN qs_film_actor fa ON a.actor_id = fa.actor_id
            JOIN qs_film_category fc ON fa.film_id = fc.film_id
            JOIN qs_category c ON fc.category_id = c.category_id
            WHERE c.name IN ('Action', 'Comedy')
            GROUP BY a.actor_id, a.first_name, a.last_name
            HAVING COUNT(DISTINCT c.name) = 2
            SQL;

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true);
    }

    /**
     * Complex prompt #15: "2005年5月至8月，每月新增租赁数和总收入"
     */
    public function testAllowsMonthGroupingWithDualAggregate(): void
    {
        $sql = <<<SQL
            SELECT DATE_FORMAT(r.rental_date, '%Y-%m') AS month,
                   COUNT(*) AS rental_count,
                   SUM(p.amount) AS total_revenue
            FROM qs_rental r
            JOIN qs_payment p ON r.rental_id = p.rental_id
            WHERE r.rental_date BETWEEN '2005-05-01' AND '2005-08-31'
            GROUP BY DATE_FORMAT(r.rental_date, '%Y-%m')
            ORDER BY month
            SQL;

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true);
    }

    /**
     * Visualization prompt #20: "租赁率最高的前10部电影的租赁次数和收入"
     */
    public function testAllowsDualMetricQuery(): void
    {
        $sql = <<<SQL
            SELECT f.title,
                   COUNT(r.rental_id) AS rental_count,
                   SUM(p.amount) AS total_revenue
            FROM qs_film f
            JOIN qs_inventory i ON f.film_id = i.film_id
            JOIN qs_rental r ON i.inventory_id = r.inventory_id
            JOIN qs_payment p ON r.rental_id = p.rental_id
            GROUP BY f.film_id, f.title
            ORDER BY rental_count DESC
            LIMIT 10
            SQL;

        SqlValidator::validateSelectOnly($sql);
        $this->assertTrue(true);
    }

    // =========================================================================
    // Rejection cases
    // =========================================================================

    public function testRejectsDropTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SELECT');

        SqlValidator::validateSelectOnly('DROP TABLE qs_film');
    }

    public function testRejectsInsert(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlValidator::validateSelectOnly("INSERT INTO qs_film (title) VALUES ('hacked')");
    }

    public function testRejectsUpdate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlValidator::validateSelectOnly("UPDATE qs_film SET title = 'hacked' WHERE 1=1");
    }

    public function testRejectsDelete(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlValidator::validateSelectOnly('DELETE FROM qs_film');
    }

    public function testRejectsMultiStatement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Multi-statement');

        SqlValidator::validateSelectOnly('SELECT 1; DELETE FROM qs_film');
    }

    public function testRejectsUnionExfiltration(): void
    {
        // UNION + INFORMATION_SCHEMA is rejected (rule 4 second clause);
        // UNION alone would be allowed.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UNION with dangerous');

        SqlValidator::validateSelectOnly("SELECT id FROM qs_film UNION SELECT table_name FROM information_schema.tables");
    }

    public function testAllowsUnionAlone(): void
    {
        // UNION alone is permitted — NL2SQL uses it for multi-metric aggregation.
        SqlValidator::validateSelectOnly("SELECT title FROM qs_film UNION SELECT title FROM qs_film_category");
        $this->assertTrue(true);
    }

    public function testRejectsIntoOutfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed keyword');

        SqlValidator::validateSelectOnly("SELECT * FROM qs_film INTO OUTFILE '/tmp/dump.csv'");
    }

    public function testRejectsForUpdate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed keyword');

        SqlValidator::validateSelectOnly('SELECT * FROM qs_film FOR UPDATE');
    }

    public function testRejectsLockInShareMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed keyword');

        SqlValidator::validateSelectOnly('SELECT * FROM qs_film LOCK IN SHARE MODE');
    }

    public function testRejectsLeadingCommentThenDrop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SELECT');

        SqlValidator::validateSelectOnly('/* innocent comment */ DROP TABLE qs_film');
    }

    // =========================================================================
    // LIMIT enforcement
    // =========================================================================

    public function testEnforceLimitAppendsWhenMissing(): void
    {
        $sql = 'SELECT * FROM qs_film';
        $result = SqlValidator::enforceLimit($sql, 1000);

        $this->assertStringContainsString('LIMIT 1000', $result);
        $this->assertStringContainsString('SELECT * FROM qs_film', $result);
    }

    public function testEnforceLimitPreservesExisting(): void
    {
        $sql = 'SELECT * FROM qs_film LIMIT 10';
        $result = SqlValidator::enforceLimit($sql, 1000);

        $this->assertSame($sql, $result);
    }

    public function testEnforceLimitCustomMax(): void
    {
        $sql = 'SELECT * FROM qs_film';
        $result = SqlValidator::enforceLimit($sql, 50);

        $this->assertStringContainsString('LIMIT 50', $result);
    }

    /**
     * Visualization prompt #17: "电影时长的分布情况" -> needs LIMIT appended
     */
    public function testEnforceLimitOnHistogramQuery(): void
    {
        $sql = <<<SQL
            SELECT
                CASE
                    WHEN length < 60 THEN 'short'
                    WHEN length BETWEEN 60 AND 120 THEN 'medium'
                    ELSE 'long'
                END AS duration_bucket,
                COUNT(*) AS film_count
            FROM qs_film
            GROUP BY duration_bucket
            ORDER BY duration_bucket
            SQL;

        $result = SqlValidator::enforceLimit($sql, 1000);
        $this->assertStringContainsString('LIMIT 1000', $result);
    }

    public function testEnforceLimitHandlesTrailingSemicolon(): void
    {
        $sql = 'SELECT * FROM qs_film;';
        $result = SqlValidator::enforceLimit($sql, 100);

        $this->assertStringContainsString('LIMIT 100', $result);
        $this->assertStringNotContainsString(';', rtrim($result, ';'));
    }
}
