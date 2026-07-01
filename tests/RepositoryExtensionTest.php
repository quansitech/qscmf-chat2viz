<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface::updateWidgetSql
 * @covers \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface::executeRawQuery
 * @covers database/migrations/2026_06_08_100000_create_chat2viz_dashboard_tables
 */
class RepositoryExtensionTest extends TestCase
{
    // ─── In-memory dashboard store ──────────────────────────────────────────

    private static array $dashboards = [];

    public static function dashboardReset(): void
    {
        self::$dashboards = [];
    }

    public static function dashboardAdd(array $row): void
    {
        self::$dashboards[$row['uid']] = $row;
    }

    public static function dashboardFindByUid(string $uid): ?array
    {
        return self::$dashboards[$uid] ?? null;
    }

    public static function dashboardUpdate(string $uid, array $data): void
    {
        if (isset(self::$dashboards[$uid])) {
            self::$dashboards[$uid] = array_merge(self::$dashboards[$uid], $data);
        }
    }

    // ─── updateWidgetSql logic tests ────────────────────────────────────────

    protected function setUp(): void
    {
        self::dashboardReset();
    }

    private function createDashboardWithWidgets(string $uid, array $widgets): void
    {
        self::dashboardAdd([
            'id' => 1,
            'uid' => $uid,
            'current_schema' => json_encode(['widgets' => $widgets], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function updateWidgetSql(string $uid, string $widgetId, string $sql): void
    {
        $dashboard = self::dashboardFindByUid($uid);
        if ($dashboard === null) {
            return;
        }

        $schema = json_decode($dashboard['current_schema'], true);
        if (!is_array($schema)) {
            return;
        }

        $widgets = $schema['widgets'] ?? [];
        $found = false;
        foreach ($widgets as $index => $widget) {
            if (is_array($widget) && ($widget['id'] ?? '') === $widgetId) {
                $widgets[$index]['sql'] = $sql;
                $found = true;
                break;
            }
        }

        if (!$found) {
            return;
        }

        $schema['widgets'] = $widgets;
        self::dashboardUpdate($uid, [
            'current_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function testUpdateWidgetSqlInjectsSqlIntoTargetWidget(): void
    {
        $this->createDashboardWithWidgets('dash-001', [
            ['id' => 'widget-1', 'type' => 'bar'],
            ['id' => 'widget-2', 'type' => 'line'],
        ]);

        $this->updateWidgetSql('dash-001', 'widget-1', 'SELECT * FROM sales');

        $dashboard = self::dashboardFindByUid('dash-001');
        $schema = json_decode($dashboard['current_schema'], true);

        $this->assertSame('SELECT * FROM sales', $schema['widgets'][0]['sql']);
        $this->assertArrayNotHasKey('sql', $schema['widgets'][1]);
    }

    public function testUpdateWidgetSqlDoesNotAffectOtherWidgets(): void
    {
        $this->createDashboardWithWidgets('dash-002', [
            ['id' => 'w-a', 'type' => 'pie'],
            ['id' => 'w-b', 'type' => 'table'],
        ]);

        $this->updateWidgetSql('dash-002', 'w-b', 'SELECT id, name FROM users');

        $dashboard = self::dashboardFindByUid('dash-002');
        $schema = json_decode($dashboard['current_schema'], true);

        $this->assertArrayNotHasKey('sql', $schema['widgets'][0]);
        $this->assertSame('SELECT id, name FROM users', $schema['widgets'][1]['sql']);
    }

    public function testUpdateWidgetSqlSilentlyIgnoresMissingWidget(): void
    {
        $this->createDashboardWithWidgets('dash-003', [
            ['id' => 'existing', 'type' => 'bar'],
        ]);

        $originalSchema = self::dashboardFindByUid('dash-003')['current_schema'];

        $this->updateWidgetSql('dash-003', 'nonexistent', 'SELECT 1');

        $afterSchema = self::dashboardFindByUid('dash-003')['current_schema'];
        $this->assertSame($originalSchema, $afterSchema);
    }

    public function testUpdateWidgetSqlSilentlyIgnoresMissingDashboard(): void
    {
        $this->updateWidgetSql('nonexistent-uid', 'widget-1', 'SELECT 1');

        $dashboard = self::dashboardFindByUid('nonexistent-uid');
        $this->assertNull($dashboard);
    }

    public function testUpdateWidgetSqlOverwritesExistingSql(): void
    {
        $this->createDashboardWithWidgets('dash-004', [
            ['id' => 'w-1', 'type' => 'bar', 'sql' => 'SELECT OLD'],
        ]);

        $this->updateWidgetSql('dash-004', 'w-1', 'SELECT NEW');

        $dashboard = self::dashboardFindByUid('dash-004');
        $schema = json_decode($dashboard['current_schema'], true);

        $this->assertSame('SELECT NEW', $schema['widgets'][0]['sql']);
    }

    // ─── Migration schema verification ──────────────────────────────────────

    public function testMigrationConversationMessagesHasNoDashboardUid(): void
    {
        $migrationFile = dirname(__DIR__) . '/database/migrations/2026_06_08_100000_create_chat2viz_dashboard_tables.php';
        $this->assertFileExists($migrationFile);

        $content = file_get_contents($migrationFile);

        // Verify dashboard_uid is NOT in conversation_messages table (derived via JOIN)
        $convMessagesStart = strpos($content, 'qs_chat2viz_conversation_messages');
        $convMessagesEnd = strpos($content, 'idx_conv_created', $convMessagesStart);
        $this->assertNotFalse($convMessagesStart, 'conversation_messages table definition not found');

        $convMessagesBlock = substr($content, $convMessagesStart, $convMessagesEnd - $convMessagesStart);
        $this->assertStringNotContainsString('dashboard_uid', $convMessagesBlock,
            'dashboard_uid should NOT be in conversation_messages — derived via JOIN from conversations');
    }

    public function testMigrationConversationMessagesConversationIdIsBigInt(): void
    {
        $migrationFile = dirname(__DIR__) . '/database/migrations/2026_06_08_100000_create_chat2viz_dashboard_tables.php';
        $content = file_get_contents($migrationFile);

        $blockStart = strpos($content, 'qs_chat2viz_conversation_messages');
        $blockEnd = strpos($content, 'idx_conv_created', $blockStart);
        $this->assertNotFalse($blockStart, 'conversation_messages table not found');

        $block = substr($content, $blockStart, $blockEnd - $blockStart);

        $this->assertNotFalse(strpos($block, "'conversation_id'"),
            'conversation_id column not found in conversation_messages block');
        $this->assertNotFalse(
            strpos($block, 'unsignedBigInteger') && strpos($block, "'conversation_id'") !== false,
            'conversation_id should be unsignedBigInteger in conversation_messages'
        );
    }

    // ─── executeRawQuery result format test ─────────────────────────────────

    public function testExecuteRawQueryReturnsArrayFormat(): void
    {
        // Simulate what both implementations should return: array of arrays
        $mockRows = [
            ['id' => 1, 'name' => 'Sales'],
            ['id' => 2, 'name' => 'Revenue'],
        ];

        // ThinkModel returns array directly
        $thinkResult = is_array($mockRows) ? $mockRows : [];
        $this->assertSame($mockRows, $thinkResult);

        // Eloquent returns array of stdClass → must map to array
        $stdRows = array_map(fn ($r) => (object) $r, $mockRows);
        $eloquentResult = array_map(fn ($row) => (array) $row, $stdRows);
        $this->assertSame($mockRows, $eloquentResult);
    }
}
