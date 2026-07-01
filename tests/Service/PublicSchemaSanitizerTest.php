<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Service;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\PublicSchemaSanitizer;

/**
 * Unit tests for PublicSchemaSanitizer::stripSqlFromWidgets().
 *
 * fix-public-view-draft-exposure §3.3: the public view surface must not leak
 * the raw SQL string behind each widget. This is the security-critical
 * invariant — extracted from the controller into a pure function so it is
 * verifiable without booting the ThinkPHP controller stack.
 *
 * These tests run under the package's own phpunit.xml (bootstrap loads
 * framework stubs); no DB / HTTP / ThinkPHP runtime required.
 */
class PublicSchemaSanitizerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // §3.3 — sql is stripped from every widget
    // -------------------------------------------------------------------------

    public function testStripsSqlFromEveryWidget(): void
    {
        $schema = [
            'widgets' => [
                [
                    'id' => 'w1',
                    'title' => '销售排行',
                    'g2_spec' => ['type' => 'interval'],
                    'sql' => 'SELECT * FROM qs_user LIMIT 10',
                    'layout' => ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
                ],
                [
                    'id' => 'w2',
                    'title' => '月度趋势',
                    'g2_spec' => ['type' => 'line'],
                    'sql' => "SELECT DATE_FORMAT(t, '%Y-%m') AS m FROM qs_payment",
                    'layout' => ['x' => 12, 'y' => 0, 'w' => 12, 'h' => 6],
                ],
            ],
            'layout' => [],
            'variables' => [],
        ];

        $result = PublicSchemaSanitizer::stripSqlFromWidgets($schema);

        $this->assertArrayHasKey('widgets', $result);
        foreach ($result['widgets'] as $widget) {
            $this->assertArrayNotHasKey(
                'sql',
                $widget,
                'sql MUST be stripped from every widget before reaching the browser',
            );
        }
        // Non-sql fields are preserved (especially g2_spec — the chart spec).
        $this->assertSame('interval', $result['widgets'][0]['g2_spec']['type']);
        $this->assertSame('w1', $result['widgets'][0]['id']);
        $this->assertSame('销售排行', $result['widgets'][0]['title']);
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 12, 'h' => 6], $result['widgets'][0]['layout']);
    }

    // -------------------------------------------------------------------------
    // g2_spec (chart spec, NOT sql) is preserved
    // -------------------------------------------------------------------------

    public function testPreservesG2Spec(): void
    {
        $g2 = ['type' => 'interval', 'encode' => ['x' => 'name', 'y' => 'amount'], 'data' => [['name' => 'a']]];
        $schema = ['widgets' => [['id' => 'w1', 'g2_spec' => $g2, 'sql' => 'SELECT 1']]];

        $result = PublicSchemaSanitizer::stripSqlFromWidgets($schema);

        $this->assertSame($g2, $result['widgets'][0]['g2_spec']);
        $this->assertArrayNotHasKey('sql', $result['widgets'][0]);
    }

    // -------------------------------------------------------------------------
    // Non-destructive: input array is not mutated (defense against shared refs)
    // -------------------------------------------------------------------------

    public function testDoesNotMutateInput(): void
    {
        $schema = ['widgets' => [['id' => 'w1', 'sql' => 'SELECT 1']]];
        $original = $schema;

        PublicSchemaSanitizer::stripSqlFromWidgets($schema);

        $this->assertSame($original, $schema, 'input schema MUST NOT be mutated');
        $this->assertArrayHasKey('sql', $schema['widgets'][0]);
    }

    // -------------------------------------------------------------------------
    // Widgets without sql pass through unchanged
    // -------------------------------------------------------------------------

    public function testWidgetsWithoutSqlPassThrough(): void
    {
        $schema = ['widgets' => [['id' => 'w1', 'g2_spec' => ['type' => 'point']]]];

        $result = PublicSchemaSanitizer::stripSqlFromWidgets($schema);

        $this->assertSame(['type' => 'point'], $result['widgets'][0]['g2_spec']);
        $this->assertSame('w1', $result['widgets'][0]['id']);
    }

    // -------------------------------------------------------------------------
    // Edge cases: null / empty / malformed input
    // -------------------------------------------------------------------------

    public function testHandlesNullInput(): void
    {
        $this->assertSame([], PublicSchemaSanitizer::stripSqlFromWidgets(null));
    }

    public function testHandlesSchemaWithoutWidgetsKey(): void
    {
        $schema = ['layout' => [], 'variables' => []];
        $this->assertSame($schema, PublicSchemaSanitizer::stripSqlFromWidgets($schema));
    }

    public function testHandlesMalformedWidgetEntries(): void
    {
        // Defense: a corrupted persisted schema may have non-array widget entries.
        $schema = ['widgets' => [['id' => 'w1', 'sql' => 'SELECT 1'], 'corrupted-string', null, 42]];

        $result = PublicSchemaSanitizer::stripSqlFromWidgets($schema);

        $this->assertArrayNotHasKey('sql', $result['widgets'][0]);
        // Malformed entries are preserved as-is (sanitizer only touches sql keys).
        $this->assertCount(4, $result['widgets']);
    }

    // -------------------------------------------------------------------------
    // stripDashboardRowForPublicView — row-level leak vector (E2E-found)
    // -------------------------------------------------------------------------

    public function testStripDashboardRowRemovesCurrentSchema(): void
    {
        // fix-public-view-draft-exposure §1.2 regression: the dashboard row
        // carries `current_schema` (raw draft schema string with widget SQL).
        // The public view template only reads the published `schema`, so
        // current_schema is dead weight AND a leak vector.
        $row = [
            'uid' => 'pub-uid',
            'title' => '已发布',
            'dashboard_status' => 'published',
            'current_schema' => '{"widgets":[{"id":"w1","sql":"SELECT * FROM qs_user"}]}',
            'created_by' => 1,
        ];

        $result = PublicSchemaSanitizer::stripDashboardRowForPublicView($row);

        $this->assertArrayNotHasKey('current_schema', $result, 'current_schema MUST be stripped from the public row');
        // Other fields preserved.
        $this->assertSame('pub-uid', $result['uid']);
        $this->assertSame('已发布', $result['title']);
        $this->assertSame('published', $result['dashboard_status']);
        $this->assertSame(1, $result['created_by']);
    }

    public function testStripDashboardRowDoesNotMutateInput(): void
    {
        $row = ['uid' => 'x', 'current_schema' => '{"sql":"LEAK"}'];
        $original = $row;

        PublicSchemaSanitizer::stripDashboardRowForPublicView($row);

        $this->assertSame($original, $row, 'input row MUST NOT be mutated');
        $this->assertArrayHasKey('current_schema', $row);
    }

    public function testStripDashboardRowHandlesNullAndMissingKey(): void
    {
        $this->assertSame([], PublicSchemaSanitizer::stripDashboardRowForPublicView(null));
        // Row without current_schema passes through unchanged.
        $row = ['uid' => 'x', 'title' => 't'];
        $this->assertSame($row, PublicSchemaSanitizer::stripDashboardRowForPublicView($row));
    }
}
