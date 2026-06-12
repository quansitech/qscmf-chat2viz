<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

use Qscmf\Chat2Viz\Adapter\AdapterFactory;
use Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface;
use Qscmf\Chat2Viz\Repository\MessageRepositoryInterface;
use Qscmf\Chat2Viz\Sse\StreamAccumulator;

class ConversationService
{
    private ConversationRepositoryInterface $convRepo;
    private MessageRepositoryInterface $msgRepo;

    /** @var callable */
    private $logger;

    public function __construct(
        ConversationRepositoryInterface $convRepo,
        MessageRepositoryInterface $msgRepo,
        ?callable $logger = null
    ) {
        $this->convRepo = $convRepo;
        $this->msgRepo = $msgRepo;
        $this->logger = $logger ?? static function (string $tag, string $detail): void {
            \Think\Log::write(sprintf('[chat2viz] %s | %s', $tag, $detail), \Think\Log::ERR);
        };
    }

    public function createConversation(string $dashboard_uid, string $title = ''): array
    {
        return $this->convRepo->createConversation($dashboard_uid, $title);
    }

    public function findActiveByDashboardUid(string $dashboard_uid): ?array
    {
        return $this->convRepo->findActiveByDashboardUid($dashboard_uid);
    }

    public function findByDashboardUid(string $dashboard_uid): array
    {
        return $this->convRepo->findByDashboardUid($dashboard_uid);
    }

    public function archive(int $conversation_id): bool
    {
        return $this->convRepo->archive($conversation_id);
    }

    public function persistMessage(
        string $conversation_id,
        string $role,
        string $content
    ): void {
        try {
            $this->msgRepo->createMessage($conversation_id, $role, $content);
        } catch (\Throwable $e) {
            ($this->logger)('persist message failed', $e->getMessage());
        }
    }

    public function preallocateAssistantMessage(
        string $conversation_id
    ): ?int {
        try {
            return $this->msgRepo->createPreallocatedAssistantMessage(
                $conversation_id
            );
        } catch (\Throwable $e) {
            ($this->logger)('preallocate assistant message failed', $e->getMessage());
            return null;
        }
    }

    public function ensureConversation(string $conversation_id, string $dashboard_uid): string
    {
        if ($dashboard_uid === '') {
            return $conversation_id;
        }

        try {
            $active = $this->convRepo->findActiveByDashboardUid($dashboard_uid);

            if ($active !== null) {
                return (string) $active['id'];
            }

            $title = mb_substr($conversation_id, 0, 32);
            $conv = $this->convRepo->createConversation($dashboard_uid, $title);
            return (string) $conv['id'];
        } catch (\Throwable $e) {
            ($this->logger)('ensure conversation failed', $e->getMessage());
            return $conversation_id;
        }
    }

    public function finalizeStream(
        StreamAccumulator $accumulator,
        string $conversation_id,
        ?int $message_id,
        string $status
    ): void {
        try {
            $accumulated = $accumulator->flush($conversation_id);
            $accumulator->cleanup($conversation_id);
        } catch (\Throwable $e) {
            ($this->logger)('accumulator flush/cleanup failed', $e->getMessage());
            $accumulated = [
                'content'           => '',
                'metadata'          => [],
                'reasoning_content' => '',
                'tool_calls'        => [],
            ];
        }

        if ($message_id === null) {
            if ($accumulated['content'] !== '' && $status === 'complete') {
                $this->persistMessage($conversation_id, 'assistant', $accumulated['content']);
            }
            return;
        }

        try {
            $this->msgRepo->updateMessageWithMetadata(
                $message_id,
                $accumulated['content'],
                !empty($accumulated['metadata']) ? $accumulated['metadata'] : null,
                $accumulated['reasoning_content'] !== '' ? $accumulated['reasoning_content'] : null,
                !empty($accumulated['tool_calls']) ? $accumulated['tool_calls'] : null,
                $status
            );
        } catch (\Throwable $e) {
            ($this->logger)('finalize stream DB update failed', $e->getMessage());

            try {
                $this->msgRepo->updateMessageWithMetadata(
                    $message_id, null, null, null, null, $status
                );
            } catch (\Throwable $e2) {
                ($this->logger)('finalize stream status-only update failed', $e2->getMessage());
            }
        }
    }
}
