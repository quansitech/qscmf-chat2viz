<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Exception\DashboardNotFoundException;
use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Service\DashboardService;

/**
 * Verifies DashboardService::delete() — the permanent-delete path.
 *
 *   - throws DashboardNotFoundException when missing
 *   - throws DashboardException when not the owner
 *   - delegates to repo->delete() once ownership passes
 *   - does NOT call repo->delete() when ownership fails
 */
class DashboardDeleteTest extends TestCase
{
    private function makeRepo(?array $found, object $spy): DashboardRepositoryInterface
    {
        return new class($found, $spy) implements DashboardRepositoryInterface {
            public function __construct(private ?array $found, private object $spy) {}
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return $this->found; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { $this->spy->called = true; return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };
    }

    private function spy(): object
    {
        return new class { public bool $called = false; };
    }

    public function testDeleteThrowsNotFoundWhenDashboardMissing(): void
    {
        $service = new DashboardService($this->makeRepo(null, $this->spy()));
        $this->expectException(DashboardNotFoundException::class);
        $service->delete('non-existent-uid', 42);
    }

    public function testDeleteRejectsNonOwner(): void
    {
        $service = new DashboardService($this->makeRepo(['created_by' => 42], $this->spy()));
        $this->expectException(DashboardException::class);
        $service->delete('some-uid', 99);
    }

    public function testDeleteRejectsNullUser(): void
    {
        $service = new DashboardService($this->makeRepo(['created_by' => 42], $this->spy()));
        $this->expectException(DashboardException::class);
        $service->delete('some-uid', null);
    }

    public function testDeleteDelegatesToRepoForOwner(): void
    {
        $spy = $this->spy();
        $service = new DashboardService($this->makeRepo(['created_by' => 42], $spy));
        $this->assertTrue($service->delete('some-uid', 42));
        $this->assertTrue($spy->called, 'repo->delete() must be invoked for the owner');
    }

    public function testDeleteDoesNotCallRepoWhenOwnershipFails(): void
    {
        $spy = $this->spy();
        $service = new DashboardService($this->makeRepo(['created_by' => 42], $spy));
        try {
            $service->delete('some-uid', 99);
            $this->fail('Expected DashboardException was not thrown');
        } catch (DashboardException $e) {}
        $this->assertFalse($spy->called, 'repo->delete() must NOT run before ownership passes');
    }
}
