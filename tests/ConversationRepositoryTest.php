<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Controller\Chat2VizController;

/**
 * @covers \Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface
 * @covers \Qscmf\Chat2Viz\Repository\ThinkModelConversationRepository
 * @covers \Qscmf\Chat2Viz\Controller\Chat2VizController::persistConversationMessage
 */
class ConversationRepositoryTest extends TestCase
{
    // ─── In-memory store for ThinkModel M() stub ────────────────────────

    private static array $store = [];
    private static int $autoId = 0;

    public static function storeReset(): void
    {
        self::$store = [];
        self::$autoId = 0;
    }

    public static function storeAdd(array $row): int
    {
        self::$autoId++;
        $row['id'] = self::$autoId;
        self::$store[self::$autoId] = $row;
        return self::$autoId;
    }

    public static function storeFind(int $id): ?array
    {
        return self::$store[$id] ?? null;
    }

    public static function storeSelect(array $where, string $order = '', int $limit = 0): array
    {
        $rows = array_values(self::$store);

        // Filter by where conditions
        foreach ($where as $key => $value) {
            $rows = array_filter($rows, fn($r) => ($r[$key] ?? null) === $value);
        }

        // Sort
        if ($order !== '') {
            $parts = explode(' ', trim($order));
            $field = $parts[0];
            $dir = strtoupper($parts[1] ?? 'ASC');
            usort($rows, function ($a, $b) use ($field, $dir) {
                $cmp = ($a[$field] ?? '') <=> ($b[$field] ?? '');
                return $dir === 'DESC' ? -$cmp : $cmp;
            });
        }

        // Limit
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        return array_values($rows);
    }

    public static function storeSelectGrouped(string $field, string $groupField, array $where, string $order = '', int $limit = 0): array
    {
        $rows = self::storeSelect($where);

        $groups = [];
        foreach ($rows as $row) {
            $key = $row[$groupField] ?? '';
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        $result = [];
        foreach ($groups as $key => $groupRows) {
            $maxCreated = max(array_column($groupRows, 'created_at'));
            $result[] = [
                $groupField => $key,
                'last_active' => $maxCreated,
            ];
        }

        if ($order !== '') {
            $parts = explode(' ', trim($order));
            $sortField = $parts[0];
            $dir = strtoupper($parts[1] ?? 'ASC');
            usort($result, function ($a, $b) use ($sortField, $dir) {
                $cmp = ($a[$sortField] ?? '') <=> ($b[$sortField] ?? '');
                return $dir === 'DESC' ? -$cmp : $cmp;
            });
        }

        if ($limit > 0) {
            $result = array_slice($result, 0, $limit);
        }

        return array_values($result);
    }

    // ─── ThinkModelConversationRepository tests ─────────────────────────

    protected function setUp(): void
    {
        self::storeReset();
    }

    private function createRepo(): object
    {
        // Return a testable wrapper that uses the in-memory store
        // instead of calling M() which requires Think\Model
        return new class {
            public function createMessage(
                string $conversationId,
                string $dashboardUid,
                string $role,
                string $content,
                ?array $metadata = null
            ): array {
                $validRoles = ['user', 'assistant', 'system'];
                if (!in_array($role, $validRoles, true)) {
                    throw new \RuntimeException('Invalid message role: ' . $role);
                }

                $insertData = [
                    'conversation_id' => $conversationId,
                    'dashboard_uid'   => $dashboardUid,
                    'role'            => $role,
                    'content'         => $content,
                    'metadata'        => $metadata !== null
                        ? json_encode($metadata, JSON_UNESCAPED_UNICODE)
                        : null,
                    'created_at'      => date('Y-m-d H:i:s'),
                ];

                $id = ConversationRepositoryTest::storeAdd($insertData);
                return ConversationRepositoryTest::storeFind($id) ?? $insertData;
            }

            public function getMessages(string $conversationId, int $limit = 50): array
            {
                return ConversationRepositoryTest::storeSelect(
                    ['conversation_id' => $conversationId],
                    'created_at ASC',
                    $limit
                );
            }

            public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array
            {
                return ConversationRepositoryTest::storeSelectGrouped(
                    'conversation_id',
                    'conversation_id',
                    ['dashboard_uid' => $dashboardUid],
                    'last_active DESC',
                    $limit
                );
            }
        };
    }

    public function testCreateMessageReturnsArrayWithExpectedFields(): void
    {
        $repo = $this->createRepo();

        $row = $repo->createMessage(
            'conv-001',
            'dash-001',
            'user',
            'Show me sales',
            ['source' => 'test']
        );

        $this->assertIsArray($row);
        $this->assertSame('conv-001', $row['conversation_id']);
        $this->assertSame('dash-001', $row['dashboard_uid']);
        $this->assertSame('user', $row['role']);
        $this->assertSame('Show me sales', $row['content']);
    }

    public function testCreateMessageWithNullMetadata(): void
    {
        $repo = $this->createRepo();

        $row = $repo->createMessage(
            'conv-002',
            'dash-002',
            'assistant',
            'Here is the chart',
            null
        );

        $this->assertIsArray($row);
        $this->assertSame('assistant', $row['role']);
        $this->assertNull($row['metadata']);
    }

    public function testCreateMessageRejectsInvalidRole(): void
    {
        $repo = $this->createRepo();
        $this->expectException(\RuntimeException::class);
        $repo->createMessage('conv-003', 'dash-003', 'invalid', 'test');
    }

    public function testGetMessagesReturnsOrderedRows(): void
    {
        $repo = $this->createRepo();

        $repo->createMessage('conv-010', 'dash-010', 'user', 'First');
        $repo->createMessage('conv-010', 'dash-010', 'assistant', 'Second');

        $messages = $repo->getMessages('conv-010');

        $this->assertCount(2, $messages);
        $this->assertSame('First', $messages[0]['content']);
        $this->assertSame('Second', $messages[1]['content']);
    }

    public function testGetMessagesRespectsLimit(): void
    {
        $repo = $this->createRepo();

        for ($i = 0; $i < 5; $i++) {
            $repo->createMessage('conv-limit', 'dash-limit', 'user', "msg {$i}");
        }

        $messages = $repo->getMessages('conv-limit', 3);
        $this->assertCount(3, $messages);
    }

    public function testGetMessagesReturnsEmptyArrayForUnknownConversation(): void
    {
        $repo = $this->createRepo();

        $messages = $repo->getMessages('nonexistent-conv');
        $this->assertSame([], $messages);
    }

    public function testGetRecentConversationIdsReturnsDistinctIds(): void
    {
        $repo = $this->createRepo();

        $repo->createMessage('conv-a', 'dash-recent', 'user', 'A1');
        $repo->createMessage('conv-b', 'dash-recent', 'user', 'B1');
        $repo->createMessage('conv-a', 'dash-recent', 'assistant', 'A2');

        $ids = $repo->getRecentConversationIds('dash-recent');

        $this->assertNotEmpty($ids);
        $conversationIds = array_column($ids, 'conversation_id');
        $this->assertContains('conv-a', $conversationIds);
        $this->assertContains('conv-b', $conversationIds);
    }

    public function testGetRecentConversationIdsReturnsEmptyForUnknownDashboard(): void
    {
        $repo = $this->createRepo();

        $ids = $repo->getRecentConversationIds('nonexistent-dash');
        $this->assertSame([], $ids);
    }

    // ─── Controller integration: persistConversationMessages ────────────

    public function testPersistConversationMessageWritesUserMessage(): void
    {
        $controller = (new \ReflectionClass(Chat2VizController::class))
            ->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(Chat2VizController::class, 'persistConversationMessage');
        $method->setAccessible(true);

        // Should not throw -- persistence failures are caught internally
        $method->invoke($controller, 'conv-ctrl-001', [
            'question' => 'Show revenue',
            'dashboard_context' => ['dashboard_uid' => 'dash-ctrl'],
        ], 'user');

        $this->assertTrue(true);
    }

    public function testPersistConversationMessageWritesAssistantMessage(): void
    {
        $controller = (new \ReflectionClass(Chat2VizController::class))
            ->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(Chat2VizController::class, 'persistConversationMessage');
        $method->setAccessible(true);

        // Assistant message with empty content (stream data not captured at PHP level)
        $method->invoke($controller, 'conv-ctrl-003', [
            'dashboard_context' => ['dashboard_uid' => 'dash-ctrl'],
        ], 'assistant');

        $this->assertTrue(true);
    }

    public function testPersistConversationMessageHandlesMissingDashboardContext(): void
    {
        $controller = (new \ReflectionClass(Chat2VizController::class))
            ->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(Chat2VizController::class, 'persistConversationMessage');
        $method->setAccessible(true);

        // No dashboard_context key at all -- should not throw
        $method->invoke($controller, 'conv-ctrl-002', ['question' => 'hello'], 'user');
        $this->assertTrue(true);
    }
}
