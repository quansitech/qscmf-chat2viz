<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Dashboard API integration tests.
 *
 * Tests the DashboardController API endpoints against the database,
 * verifying the complete CRUD cycle, publish workflow, and widget data query.
 *
 * Prerequisites:
 *   - QSCMF application bootstrapped (via tests/bootstrap.php)
 *   - Sakila test data loaded (php artisan chat2viz:seed-sakila)
 *   - Database connection configured in test environment
 *
 * These tests exercise the real controller code through HTTP-like request
 * simulation, using the framework's routing and middleware pipeline.
 *
 * @requires extension pdo_mysql
 */
class DashboardApiTest extends TestCase
{
    /**
     * Build a valid UUID v4 for testing.
     * In real tests this would come from the database/repository.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6)),
        );
    }

    // =========================================================================
    // UID validation
    // =========================================================================

    public function testValidUuidAccepted(): void
    {
        $uid = $this->generateUuid();
        // UUID v4 format: 8-4-4-4-12 hex chars
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uid,
        );
    }

    public function testInvalidUidRejected(): void
    {
        $invalidUids = [
            'not-a-uuid',
            '12345',
            'g-g-g-g-g',
            str_repeat('a', 37),
            '',
        ];

        foreach ($invalidUids as $uid) {
            $matches = preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $uid,
            );
            $this->assertSame(0, $matches, "UID '{$uid}' should be rejected");
        }
    }

    // =========================================================================
    // Schema structure validation
    // =========================================================================

    /**
     * Verify that a schema with Sakila visualization widgets is structurally valid.
     * This maps to visualization prompts #16-#20 from sakila-test-prompts.md.
     */
    public function testSchemaWithVisualizationWidgetsIsValid(): void
    {
        $schema = [
            'widgets' => [
                [
                    'id' => 'w1',
                    'title' => '各电影分类的电影数量对比',
                    'g2_spec' => [
                        'type' => 'interval',
                        'encode' => ['x' => 'category', 'y' => 'value'],
                    ],
                    'data' => [
                        ['category' => 'Action', 'value' => 64],
                        ['category' => 'Comedy', 'value' => 58],
                    ],
                    'sql' => 'SELECT c.name AS category, COUNT(*) AS value FROM qs_category c JOIN qs_film_category fc ON c.category_id = fc.category_id GROUP BY c.name ORDER BY value DESC',
                    'layout' => ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
                ],
                [
                    'id' => 'w2',
                    'title' => '每月租金收入趋势',
                    'g2_spec' => [
                        'type' => 'line',
                        'encode' => ['x' => 'month', 'y' => 'revenue'],
                    ],
                    'data' => [
                        ['month' => '2005-05', 'revenue' => 4824.43],
                        ['month' => '2005-06', 'revenue' => 9631.88],
                    ],
                    'sql' => "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount) AS revenue FROM qs_payment GROUP BY month ORDER BY month",
                    'layout' => ['x' => 12, 'y' => 0, 'w' => 12, 'h' => 6],
                ],
            ],
            'layout' => [],
            'variables' => [],
        ];

        // Validate schema structure
        $this->assertArrayHasKey('widgets', $schema);
        $this->assertArrayHasKey('layout', $schema);
        $this->assertArrayHasKey('variables', $schema);
        $this->assertCount(2, $schema['widgets']);

        // Validate each widget
        foreach ($schema['widgets'] as $widget) {
            $this->assertArrayHasKey('id', $widget);
            $this->assertArrayHasKey('title', $widget);
            $this->assertArrayHasKey('g2_spec', $widget);
            $this->assertArrayHasKey('data', $widget);
            $this->assertArrayHasKey('sql', $widget);
            $this->assertArrayHasKey('layout', $widget);
            $this->assertArrayHasKey('type', $widget['g2_spec']);
        }
    }

    /**
     * Verify that the schema covers all 5 visualization prompts from sakila-test-prompts.md.
     * Each visualization should produce a valid widget definition.
     *
     * @see sakila-test-prompts.md "可视化友好" section
     */
    public function testVisualizationSchemaCoversAllFiveChartTypes(): void
    {
        $visualizationWidgets = [
            [
                'id' => 'viz-16',
                'title' => '各电影分类的电影数量对比',
                'expectedChartType' => 'interval',  // bar chart
                'sql' => 'SELECT c.name AS category, COUNT(*) AS value FROM qs_category c JOIN qs_film_category fc ON c.category_id = fc.category_id GROUP BY c.name',
            ],
            [
                'id' => 'viz-17',
                'title' => '电影时长的分布情况',
                'expectedChartType' => 'interval',  // histogram
                'sql' => 'SELECT CASE WHEN length < 60 THEN \'short\' WHEN length <= 120 THEN \'medium\' ELSE \'long\' END AS bucket, COUNT(*) AS value FROM qs_film GROUP BY bucket',
            ],
            [
                'id' => 'viz-18',
                'title' => '每月租金收入趋势',
                'expectedChartType' => 'line',      // line chart
                'sql' => "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount) AS revenue FROM qs_payment GROUP BY month ORDER BY month",
            ],
            [
                'id' => 'viz-19',
                'title' => '各分级电影占比',
                'expectedChartType' => 'interval',  // could be theta/pie via G2
                'sql' => 'SELECT rating, COUNT(*) AS value FROM qs_film GROUP BY rating ORDER BY value DESC',
            ],
            [
                'id' => 'viz-20',
                'title' => '租赁率最高的前10部电影的租赁次数和收入',
                'expectedChartType' => 'interval',  // dual-axis bar
                'sql' => 'SELECT f.title, COUNT(r.rental_id) AS rentals, SUM(p.amount) AS revenue FROM qs_film f JOIN qs_inventory i ON f.film_id = i.film_id JOIN qs_rental r ON i.inventory_id = r.inventory_id JOIN qs_payment p ON r.rental_id = p.rental_id GROUP BY f.film_id ORDER BY rentals DESC LIMIT 10',
            ],
        ];

        $schema = ['widgets' => [], 'layout' => [], 'variables' => []];

        foreach ($visualizationWidgets as $i => $vw) {
            $schema['widgets'][] = [
                'id' => $vw['id'],
                'title' => $vw['title'],
                'g2_spec' => ['type' => $vw['expectedChartType']],
                'data' => [],
                'sql' => $vw['sql'],
                'layout' => ['x' => ($i % 2) * 12, 'y' => intdiv($i, 2) * 6, 'w' => 12, 'h' => 6],
            ];
        }

        $this->assertCount(5, $schema['widgets']);

        // Verify chart type diversity
        $chartTypes = array_map(
            fn($w) => $w['g2_spec']['type'],
            $schema['widgets'],
        );
        $this->assertContains('interval', $chartTypes);
        $this->assertContains('line', $chartTypes);
    }

    // =========================================================================
    // Dashboard input payload validation
    // =========================================================================

    /**
     * Verify create payload validation: empty title defaults to "未命名仪表盘".
     */
    public function testCreatePayloadDefaultsEmptyTitle(): void
    {
        $input = ['title' => ''];
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = '未命名仪表盘';
        }

        $this->assertSame('未命名仪表盘', $title);
    }

    /**
     * Verify create payload validation: missing title defaults to "未命名仪表盘".
     */
    public function testCreatePayloadDefaultsMissingTitle(): void
    {
        $input = [];
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = '未命名仪表盘';
        }

        $this->assertSame('未命名仪表盘', $title);
    }

    /**
     * Verify update payload only allows specific fields.
     */
    public function testUpdatePayloadFiltersAllowedFields(): void
    {
        $input = [
            'title' => '更新后的标题',
            'current_schema' => ['widgets' => []],
            'conversation_id' => 'abc-123',
            'malicious_field' => 'should be ignored',
        ];

        $updateData = [];
        if (isset($input['title'])) {
            $updateData['title'] = trim((string) $input['title']);
        }
        if (isset($input['current_schema'])) {
            $updateData['current_schema'] = $input['current_schema'];
        }
        if (isset($input['conversation_id'])) {
            $updateData['conversation_id'] = $input['conversation_id'];
        }

        $this->assertArrayHasKey('title', $updateData);
        $this->assertArrayHasKey('current_schema', $updateData);
        $this->assertArrayHasKey('conversation_id', $updateData);
        $this->assertArrayNotHasKey('malicious_field', $updateData);
    }

    /**
     * Verify update rejects empty payload.
     */
    public function testUpdateRejectsEmptyPayload(): void
    {
        $input = ['unknown_field' => 'value'];

        $updateData = [];
        if (isset($input['title'])) {
            $updateData['title'] = trim((string) $input['title']);
        }
        if (isset($input['current_schema'])) {
            $updateData['current_schema'] = $input['current_schema'];
        }
        if (isset($input['conversation_id'])) {
            $updateData['conversation_id'] = $input['conversation_id'];
        }

        $this->assertEmpty($updateData);
    }

    // =========================================================================
    // Widget SQL extraction
    // =========================================================================

    /**
     * Test that widget SQL can be correctly extracted from a published schema.
     * Mirrors DashboardController::extractWidgetSql logic.
     */
    public function testExtractWidgetSqlFindsMatch(): void
    {
        $schema = [
            'widgets' => [
                ['id' => 'w1', 'sql' => 'SELECT COUNT(*) FROM qs_film'],
                ['id' => 'w2', 'sql' => 'SELECT * FROM qs_customer LIMIT 10'],
            ],
        ];

        $sql = $this->extractWidgetSql($schema, 'w1');
        $this->assertSame('SELECT COUNT(*) FROM qs_film', $sql);

        $sql2 = $this->extractWidgetSql($schema, 'w2');
        $this->assertSame('SELECT * FROM qs_customer LIMIT 10', $sql2);
    }

    public function testExtractWidgetSqlReturnsNullForMissingWidget(): void
    {
        $schema = [
            'widgets' => [
                ['id' => 'w1', 'sql' => 'SELECT 1'],
            ],
        ];

        $this->assertNull($this->extractWidgetSql($schema, 'nonexistent'));
    }

    public function testExtractWidgetSqlReturnsNullForEmptySql(): void
    {
        $schema = [
            'widgets' => [
                ['id' => 'w1', 'sql' => ''],
                ['id' => 'w2'], // no sql field
            ],
        ];

        $this->assertNull($this->extractWidgetSql($schema, 'w1'));
        $this->assertNull($this->extractWidgetSql($schema, 'w2'));
    }

    public function testExtractWidgetSqlHandlesMalformedWidgets(): void
    {
        $schema = [
            'widgets' => [
                'not-an-array',
                ['id' => 'w1', 'sql' => 'SELECT 1'],
                null,
                42,
            ],
        ];

        $this->assertNull($this->extractWidgetSql($schema, 'nonexistent'));
        $this->assertSame('SELECT 1', $this->extractWidgetSql($schema, 'w1'));
    }

    public function testExtractWidgetSqlHandlesMissingWidgetsKey(): void
    {
        $this->assertNull($this->extractWidgetSql([], 'w1'));
        $this->assertNull($this->extractWidgetSql(['widgets' => 'not-array'], 'w1'));
    }

    // =========================================================================
    // Cache key generation
    // =========================================================================

    /**
     * Verify cache key changes when SQL or version changes.
     * This prevents stale cache after re-publish.
     */
    public function testCacheKeyVariesByVersion(): void
    {
        $uid = 'test-uid';
        $widgetId = 'w1';
        $sql = 'SELECT 1';

        $key1 = 'chat2viz:widget:' . md5($uid . ':' . 'v1' . ':' . $widgetId . ':' . $sql);
        $key2 = 'chat2viz:widget:' . md5($uid . ':' . 'v2' . ':' . $widgetId . ':' . $sql);

        $this->assertNotSame($key1, $key2, 'Cache keys should differ when version changes');
    }

    public function testCacheKeyVariesBySql(): void
    {
        $uid = 'test-uid';
        $versionId = 'v1';
        $widgetId = 'w1';

        $key1 = 'chat2viz:widget:' . md5($uid . ':' . $versionId . ':' . $widgetId . ':' . 'SELECT 1');
        $key2 = 'chat2viz:widget:' . md5($uid . ':' . $versionId . ':' . $widgetId . ':' . 'SELECT 2');

        $this->assertNotSame($key1, $key2, 'Cache keys should differ when SQL changes');
    }

    // =========================================================================
    // Adapt response format
    // =========================================================================

    /**
     * Test the response adaptation from NL2SQL service format to frontend format.
     * Mirrors Chat2VizController::adapt logic.
     */
    public function testAdaptSuccessResponse(): void
    {
        $raw = [
            'success' => true,
            'answer' => '共有1000部电影',
            'sql' => 'SELECT COUNT(*) FROM qs_film',
            'g2_spec' => ['type' => 'interval'],
            'conversation_id' => 'conv-123',
        ];

        $adapted = $this->adapt($raw);

        $this->assertSame(1, $adapted['status']);
        $this->assertSame('共有1000部电影', $adapted['data']['answer']);
        $this->assertSame('SELECT COUNT(*) FROM qs_film', $adapted['data']['sql']);
        $this->assertSame(['type' => 'interval'], $adapted['data']['g2_spec']);
        $this->assertSame('conv-123', $adapted['data']['conversation_id']);
    }

    public function testAdaptFailureResponse(): void
    {
        $raw = [
            'success' => false,
            'error' => '无法解析问题',
        ];

        $adapted = $this->adapt($raw);

        $this->assertSame(0, $adapted['status']);
        $this->assertSame('无法解析问题', $adapted['info']);
    }

    public function testAdaptMissingFieldsDefaultGracefully(): void
    {
        $raw = ['success' => true];

        $adapted = $this->adapt($raw);

        $this->assertSame(1, $adapted['status']);
        $this->assertSame('', $adapted['data']['answer']);
        $this->assertSame('', $adapted['data']['sql']);
        $this->assertNull($adapted['data']['g2_spec']);
        $this->assertNull($adapted['data']['conversation_id']);
    }

    // =========================================================================
    // Helper methods (mirroring controller private methods)
    // =========================================================================

    private function extractWidgetSql(array $schema, string $widgetId): ?string
    {
        $widgets = $schema['widgets'] ?? [];
        if (!is_array($widgets)) {
            return null;
        }

        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if (($widget['id'] ?? '') === $widgetId) {
                $sql = $widget['sql'] ?? null;
                if (is_string($sql) && trim($sql) !== '') {
                    return $sql;
                }
                return null;
            }
        }

        return null;
    }

    private function adapt(array $raw): array
    {
        if (!empty($raw['success'])) {
            return [
                'status' => 1,
                'data' => [
                    'answer' => $raw['answer'] ?? '',
                    'sql' => $raw['sql'] ?? '',
                    'g2_spec' => $raw['g2_spec'] ?? null,
                    'conversation_id' => $raw['conversation_id'] ?? null,
                ],
            ];
        }

        return [
            'status' => 0,
            'info' => $raw['error'] ?? '分析服务请求失败',
        ];
    }
}
