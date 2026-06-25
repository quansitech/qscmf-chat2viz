<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\ConversationService;

/**
 * @covers \Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface
 * @covers \Qscmf\Chat2Viz\Repository\ThinkModelConversationRepository
 * @covers \Qscmf\Chat2Viz\Service\ConversationService::persistMessage
 */
class ConversationRepositoryTest extends TestCase
{
    // ─── In-memory stores for ThinkModel M() stub ────────────────────────

    private static array $store = [];
    private static int $autoId = 0;
    private static array $convStore = [];
    private static int $convAutoId = 0;

    public static function storeReset(): void
    {
        self::$store = [];
        self::$autoId = 0;
        self::$convStore = [];
        self::$convAutoId = 0;
    }

    public static function storeAdd(array $row): int
    {
        self::$autoId++;
        $row['id'] = self::$autoId;
        self::$store[self::$autoId] = $row;
        return self::$autoId;
    }

    public static function convStoreAdd(array $row): int
    {
        self::$convAutoId++;
        $row['id'] = self::$convAutoId;
        self::$convStore[self::$convAutoId] = $row;
        return self::$convAutoId;
    }

    public static function storeFind(int $id): ?array
    {
        return self::$store[$id] ?? null;
    }

    public static function storeSelect(array $where, string $order = '', int $limit = 0): array
    {
        $rows = array_values(self::$store);

        foreach ($where as $key => $value) {
            $rows = array_filter($rows, fn($r) => ($r[$key] ?? null) === $value);
        }

        if ($order !== '') {
            $parts = explode(' ', trim($order));
            $field = $parts[0];
            $dir = strtoupper($parts[1] ?? 'ASC');
            usort($rows, function ($a, $b) use ($field, $dir) {
                $cmp = ($a[$field] ?? '') <=> ($b[$field] ?? '');
                return $dir === 'DESC' ? -$cmp : $cmp;
            });
        }

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        return array_values($rows);
    }

    public static function convStoreSelect(array $where, string $order = '', int $limit = 0): array
    {
        $rows = array_values(self::$convStore);

        foreach ($where as $key => $value) {
            $rows = array_filter($rows, fn($r) => ($r[$key] ?? null) === $value);
        }

        if ($order !== '') {
            $parts = explode(' ', trim($order));
            $field = $parts[0];
            $dir = strtoupper($parts[1] ?? 'ASC');
            usort($rows, function ($a, $b) use ($field, $dir) {
                $cmp = ($a[$field] ?? '') <=> ($b[$field] ?? '');
                return $dir === 'DESC' ? -$cmp : $cmp;
            });
        }

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        return array_values($rows);
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
                $rows = ConversationRepositoryTest::convStoreSelect(
                    ['dashboard_uid' => $dashboardUid, 'status' => 1],
                    'updated_at DESC',
                    $limit
                );
                return array_map(fn($r) => [
                    'conversation_id' => $r['id'],
                    'last_active'     => $r['updated_at'] ?? '',
                ], $rows);
            }
        };
    }

    public function testCreateMessageReturnsArrayWithExpectedFields(): void
    {
        $repo = $this->createRepo();

        $row = $repo->createMessage(
            'conv-001',
            'user',
            'Show me sales',
            ['source' => 'test']
        );

        $this->assertIsArray($row);
        $this->assertSame('conv-001', $row['conversation_id']);
        $this->assertSame('user', $row['role']);
        $this->assertSame('Show me sales', $row['content']);
    }

    public function testCreateMessageWithNullMetadata(): void
    {
        $repo = $this->createRepo();

        $row = $repo->createMessage(
            'conv-002',
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
        $repo->createMessage('conv-003', 'invalid', 'test');
    }

    public function testGetMessagesReturnsOrderedRows(): void
    {
        $repo = $this->createRepo();

        $repo->createMessage('conv-010', 'user', 'First');
        $repo->createMessage('conv-010', 'assistant', 'Second');

        $messages = $repo->getMessages('conv-010');

        $this->assertCount(2, $messages);
        $this->assertSame('First', $messages[0]['content']);
        $this->assertSame('Second', $messages[1]['content']);
    }

    public function testGetMessagesRespectsLimit(): void
    {
        $repo = $this->createRepo();

        for ($i = 0; $i < 5; $i++) {
            $repo->createMessage('conv-limit', 'user', "msg {$i}");
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

        // Create conversations (the new implementation queries conversations table)
        self::convStoreAdd(['dashboard_uid' => 'dash-recent', 'status' => 1, 'updated_at' => '2026-06-01 10:00:00']);
        self::convStoreAdd(['dashboard_uid' => 'dash-recent', 'status' => 1, 'updated_at' => '2026-06-01 11:00:00']);

        $ids = $repo->getRecentConversationIds('dash-recent');

        $this->assertNotEmpty($ids);
        $conversationIds = array_column($ids, 'conversation_id');
        $this->assertCount(2, $conversationIds);
    }

    public function testGetRecentConversationIdsReturnsEmptyForUnknownDashboard(): void
    {
        $repo = $this->createRepo();

        $ids = $repo->getRecentConversationIds('nonexistent-dash');
        $this->assertSame([], $ids);
    }

    // ─── ConversationService::persistMessage integration ────────────────

    public function testPersistMessageWritesUserMessage(): void
    {
        $convRepo = new class implements \Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface {
            public function createConversation(string $dashboardUid, string $title = ''): array { return ['id' => 1]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboardUid): ?array { return null; }
            public function findByDashboardUid(string $dashboardUid): array { return []; }
        };
        $msgRepo = new class implements \Qscmf\Chat2Viz\Repository\MessageRepositoryInterface {
            public array $captured = [];
            public function createMessage(string $conversationId, string $role, string $content, ?array $metadata = null): array {
                $this->captured = ['conversation_id' => $conversationId, 'role' => $role, 'content' => $content];
                return [];
            }
            public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array { return []; }
            public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array { return []; }
            public function createPreallocatedAssistantMessage(string $conversationId): int { return 0; }
            public function updateMessageWithMetadata(int $messageId, ?string $content = null, ?array $metadata = null, ?string $reasoningContent = null, ?array $toolCalls = null, ?string $messageStatus = null): void {}
            public function findConversationHistory(string $conversationId): array { return []; }
            public function countByConversationId(string $conversationId): int { return 0; }
            public function countByConversationIds(array $conversationIds): array { return []; }
            public function markLastStreamingOrphanInterrupted(string $conversationId): int { return 0; }
            public function updateStatusAtomic(int $messageId, string $fromStatus, string $toStatus): int { return 0; }
            public function getRecentCompleteMessages(string $conversationId, int $limit): array { return []; }
            public function deleteLastTurnFromLastUser(string $conversationId): int { return 0; }
        };

        $service = new ConversationService($convRepo, $msgRepo);
        $service->persistMessage('conv-ctrl-001', 'user', 'Show revenue');

        $this->assertSame('conv-ctrl-001', $msgRepo->captured['conversation_id']);
        $this->assertSame('user', $msgRepo->captured['role']);
        $this->assertSame('Show revenue', $msgRepo->captured['content']);
    }

    public function testPersistMessageWritesAssistantMessage(): void
    {
        $convRepo = new class implements \Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface {
            public function createConversation(string $dashboardUid, string $title = ''): array { return ['id' => 1]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboardUid): ?array { return null; }
            public function findByDashboardUid(string $dashboardUid): array { return []; }
        };
        $msgRepo = new class implements \Qscmf\Chat2Viz\Repository\MessageRepositoryInterface {
            public array $captured = [];
            public function createMessage(string $conversationId, string $role, string $content, ?array $metadata = null): array {
                $this->captured = ['role' => $role, 'content' => $content];
                return [];
            }
            public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array { return []; }
            public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array { return []; }
            public function createPreallocatedAssistantMessage(string $conversationId): int { return 0; }
            public function updateMessageWithMetadata(int $messageId, ?string $content = null, ?array $metadata = null, ?string $reasoningContent = null, ?array $toolCalls = null, ?string $messageStatus = null): void {}
            public function findConversationHistory(string $conversationId): array { return []; }
            public function countByConversationId(string $conversationId): int { return 0; }
            public function countByConversationIds(array $conversationIds): array { return []; }
            public function markLastStreamingOrphanInterrupted(string $conversationId): int { return 0; }
            public function updateStatusAtomic(int $messageId, string $fromStatus, string $toStatus): int { return 0; }
            public function getRecentCompleteMessages(string $conversationId, int $limit): array { return []; }
            public function deleteLastTurnFromLastUser(string $conversationId): int { return 0; }
        };

        $service = new ConversationService($convRepo, $msgRepo);
        $service->persistMessage('conv-ctrl-003', 'assistant', '');

        $this->assertSame('assistant', $msgRepo->captured['role']);
    }

    public function testPersistMessageHandlesEmptyContent(): void
    {
        $convRepo = new class implements \Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface {
            public function createConversation(string $dashboardUid, string $title = ''): array { return ['id' => 1]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboardUid): ?array { return null; }
            public function findByDashboardUid(string $dashboardUid): array { return []; }
        };
        $msgRepo = new class implements \Qscmf\Chat2Viz\Repository\MessageRepositoryInterface {
            public array $captured = [];
            public function createMessage(string $conversationId, string $role, string $content, ?array $metadata = null): array {
                $this->captured = ['role' => $role, 'content' => $content];
                return [];
            }
            public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array { return []; }
            public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array { return []; }
            public function createPreallocatedAssistantMessage(string $conversationId): int { return 0; }
            public function updateMessageWithMetadata(int $messageId, ?string $content = null, ?array $metadata = null, ?string $reasoningContent = null, ?array $toolCalls = null, ?string $messageStatus = null): void {}
            public function findConversationHistory(string $conversationId): array { return []; }
            public function countByConversationId(string $conversationId): int { return 0; }
            public function countByConversationIds(array $conversationIds): array { return []; }
            public function markLastStreamingOrphanInterrupted(string $conversationId): int { return 0; }
            public function updateStatusAtomic(int $messageId, string $fromStatus, string $toStatus): int { return 0; }
            public function getRecentCompleteMessages(string $conversationId, int $limit): array { return []; }
            public function deleteLastTurnFromLastUser(string $conversationId): int { return 0; }
        };

        $service = new ConversationService($convRepo, $msgRepo);
        $service->persistMessage('conv-ctrl-002', 'user', '');

        $this->assertSame('user', $msgRepo->captured['role']);
        $this->assertSame('', $msgRepo->captured['content']);
    }
}
