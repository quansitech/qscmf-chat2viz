<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\DashboardService;
use Qscmf\Chat2Viz\Service\WidgetDataService;
use Qscmf\Chat2Viz\Service\WidgetDataResult;
use Qscmf\Chat2Viz\Service\ConversationService;
use Qscmf\Chat2Viz\Service\EventRouter;
use Qscmf\Chat2Viz\Validator\DashboardValidator;
use Qscmf\Chat2Viz\Exception\DashboardException;

/**
 * @covers \Qscmf\Chat2Viz\Service\DashboardService
 * @covers \Qscmf\Chat2Viz\Service\WidgetDataService
 * @covers \Qscmf\Chat2Viz\Service\WidgetDataResult
 * @covers \Qscmf\Chat2Viz\Service\ConversationService
 * @covers \Qscmf\Chat2Viz\Service\EventRouter
 */
class ServiceTest extends TestCase
{
    // ─── DashboardService tests ─────────────────────────────────────────────

    public function testDashboardServiceCheckOwnershipReturnsTrueForOwner(): void
    {
        $repo = new class implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return null; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };

        $service = new DashboardService($repo);

        $this->assertTrue($service->checkOwnership(['created_by' => 42], 42));
    }

    public function testDashboardServiceCheckOwnershipReturnsFalseForMismatch(): void
    {
        $repo = new class implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return null; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };

        $service = new DashboardService($repo);

        $this->assertFalse($service->checkOwnership(['created_by' => 42], 99));
        $this->assertFalse($service->checkOwnership(['created_by' => 42], null));
        $this->assertFalse($service->checkOwnership([], 42));
    }

    public function testDashboardServiceCreateUsesDefaultTitle(): void
    {
        $captured = null;
        $repo = new class($captured) implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public array|null $captured;
            public function __construct(?array &$captured) { $this->captured = &$captured; }
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return null; }
            public function create(array $data): array { $this->captured = $data; return $data; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };

        $service = new DashboardService($repo);
        $result = $service->create([], null);

        $this->assertSame('未命名仪表盘', $repo->captured['title']);
    }

    public function testDashboardServiceCreateRejectsLongTitle(): void
    {
        $repo = new class implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return null; }
            public function create(array $data): array { return $data; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };

        $service = new DashboardService($repo);
        $this->expectException(DashboardException::class);
        $service->create(['title' => str_repeat('x', 256)], null);
    }

    // ─── WidgetDataResult tests ─────────────────────────────────────────────

    public function testWidgetDataResultFromRawQuery(): void
    {
        $result = WidgetDataResult::fromRawQuery(
            ['rows' => [['id' => 1]]],
            'SELECT * FROM test',
            150
        );

        $this->assertSame(['rows' => [['id' => 1]]], $result->rows);
        $this->assertSame('SELECT * FROM test', $result->sql);
        $this->assertFalse($result->cached);
        $this->assertSame(150, $result->executionTimeMs);
    }

    public function testWidgetDataResultFromCache(): void
    {
        $result = WidgetDataResult::fromCache(
            [['id' => 1]],
            'SELECT 1'
        );

        $this->assertTrue($result->cached);
        $this->assertNull($result->executionTimeMs);
    }

    // ─── WidgetDataService::extractWidgetSql tests ──────────────────────────

    public function testExtractWidgetSqlFindsMatchingWidget(): void
    {
        $repo = new class implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return null; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };

        $service = new WidgetDataService($repo);

        $schema = [
            'widgets' => [
                ['id' => 'w1', 'type' => 'bar'],
                ['id' => 'w2', 'type' => 'line', 'sql' => 'SELECT * FROM sales'],
            ],
        ];

        $this->assertSame('SELECT * FROM sales', $service->extractWidgetSql($schema, 'w2'));
    }

    public function testExtractWidgetSqlReturnsNullForMissing(): void
    {
        $repo = new class implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return null; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };

        $service = new WidgetDataService($repo);
        $this->assertNull($service->extractWidgetSql(['widgets' => []], 'nonexistent'));
        $this->assertNull($service->extractWidgetSql([], 'w1'));
    }

    // ─── ConversationService::ensureConversation tests ──────────────────────
    // conversation-one-to-one: ensureConversation does a 1:1 upsert by
    // dashboard_uid. Pure-integer id → returned as-is (already a BIGINT).
    // Non-empty dashboard_uid → find/create conversation, return BIGINT id.
    // Empty uid + non-numeric id → returns '' (signals first-message init).

    public function testEnsureConversationReturnsEmptyWhenNoUidAndNonNumericId(): void
    {
        // New-dashboard first turn: hex client id + empty dashboard_uid.
        // ensureConversation returns '' so the caller triggers
        // initializeOnFirstMessage (which creates all three tables in a tx).
        $convRepo = new class implements \Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface {
            public function createConversation(string $dashboardUid, string $title = ''): array { return ['id' => 1]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboardUid): ?array { return null; }
            public function findByDashboardUid(string $dashboardUid): array { return []; }
        };
        $msgRepo = $this->createMsgRepoStub();
        $service = new ConversationService($convRepo, $msgRepo);

        $this->assertSame('', $service->ensureConversation('d0bac540fd7b69ca76814693e5816761', ''));
    }

    public function testEnsureConversationReusesPriorBigIntId(): void
    {
        // Subsequent turns pass back the BIGINT id (e.g. '65'). It's a pure
        // integer → returned as-is (no DB lookup needed, no sentinel).
        $convRepo = new class implements \Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface {
            public function createConversation(string $dashboardUid, string $title = ''): array { return ['id' => 99]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboardUid): ?array { return null; }
            public function findByDashboardUid(string $dashboardUid): array { return []; }
        };
        $msgRepo = $this->createMsgRepoStub();
        $service = new ConversationService($convRepo, $msgRepo);

        $this->assertSame('65', $service->ensureConversation('65', ''));
    }

    public function testEnsureConversationReusesExistingByDashboardUid(): void
    {
        // Dashboard has an existing conversation → return its BIGINT id.
        $convRepo = new class implements \Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface {
            public function createConversation(string $dashboardUid, string $title = ''): array { return ['id' => 99]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboardUid): ?array {
                return ['id' => 42, 'dashboard_uid' => $dashboardUid];
            }
            public function findByDashboardUid(string $dashboardUid): array { return []; }
        };
        $msgRepo = $this->createMsgRepoStub();
        $service = new ConversationService($convRepo, $msgRepo);

        $this->assertSame('42', $service->ensureConversation('new-id', 'dash-001'));
    }

    public function testEnsureConversationCreatesWhenDashboardUidGivenButNoExisting(): void
    {
        // 1:1 upsert: dashboard_uid provided, no existing conversation → create one.
        $convRepo = new class implements \Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface {
            public function createConversation(string $dashboardUid, string $title = ''): array { return ['id' => 88]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboardUid): ?array { return null; }
            public function findByDashboardUid(string $dashboardUid): array { return []; }
        };
        $msgRepo = $this->createMsgRepoStub();
        $service = new ConversationService($convRepo, $msgRepo);

        $this->assertSame('88', $service->ensureConversation('init-id', 'new-dashboard-uid'));
    }

    /** Helper: minimal MessageRepositoryInterface stub for ensureConversation tests. */
    private function createMsgRepoStub(): \Qscmf\Chat2Viz\Repository\MessageRepositoryInterface
    {
        return new class implements \Qscmf\Chat2Viz\Repository\MessageRepositoryInterface {
            public function createMessage(string $conversationId, string $role, string $content, ?array $metadata = null): array { return []; }
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
    }

    // 注: 原本这里有 testEventRouterBackfill* / testEventRouterSkipsBackfill*
    // 两个测试, 直接调用已被删除的 EventRouter::backfillWidgetSql() 方法。
    // declarative-frontend-adapter 重构后, 回填逻辑改为 handleDashboardReplace
    // 内部遍历 DASHBOARD_REPLACE.widgets, 对非空 sql 调 repo->updateWidgetSql
    // (源码见 EventRouter::backfillWidgetSqlFromWidgets)。该契约现在由
    // EventRouterAnswerTest::testDashboardReplaceBackfillsWidgetSqlIntoCurrentSchema
    // 守护(整树回填, 而非旧的单 widget 事件), 这两个旧测试已废弃并删除。

    /**
     * J5 (§5): publish dialog title MUST flow through to the repository so the
     * published view's <title>/<h1> reflect the user-supplied title. Regression
     * guard for the api_publish → repo->publish(title) wiring.
     */
    public function testDashboardServicePublishForwardsTitleToRepository(): void
    {
        $captured = [];
        $repo = new class($captured) implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public array $captured;
            public function __construct(array &$c) { $this->captured = &$c; }
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return ['uid' => $uid, 'id' => 1, 'created_by' => 7]; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array {
                $this->captured = ['uid' => $uid, 'publishedBy' => $publishedBy, 'title' => $title];
                return ['version' => 1];
            }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };

        $service = new DashboardService($repo);
        $service->publish('uid-xyz', 7, 'QA-Round3-系统测试仪表盘');

        $this->assertSame('uid-xyz', $captured['uid']);
        $this->assertSame(7, $captured['publishedBy']);
        $this->assertSame('QA-Round3-系统测试仪表盘', $captured['title']);
    }

    /**
     * J5: empty title MUST NOT clobber the stored title. The repo receives an
     * empty string and decides to leave the row unchanged.
     */
    public function testDashboardServicePublishEmptyTitleIsForwardedAsEmpty(): void
    {
        $captured = [];
        $repo = new class($captured) implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public array $captured;
            public function __construct(array &$c) { $this->captured = &$c; }
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return ['uid' => $uid, 'id' => 1, 'created_by' => 7]; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array {
                $this->captured['title'] = $title;
                return ['version' => 1];
            }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };

        $service = new DashboardService($repo);
        $service->publish('uid-xyz', 7);

        $this->assertSame('', $captured['title']);
    }
}
