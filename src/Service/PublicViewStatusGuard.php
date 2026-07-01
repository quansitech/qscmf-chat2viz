<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

/**
 * Guards the public (anonymous) view surface against non-published dashboards.
 *
 * fix-public-view-draft-exposure §1.1: only `published` dashboards are served
 * on the public route. draft / archived / unknown status MUST fall to the
 * SAME error as "UID does not exist" so an anonymous visitor cannot enumerate
 * which UIDs are drafts vs gone.
 *
 * Extracted as a pure function so the status-check invariant is unit-testable
 * without booting the ThinkPHP controller stack. The controller calls this and
 * forwards to `$this->error('仪表盘不存在')` when it returns false.
 */
final class PublicViewStatusGuard
{
    /**
     * Whether a dashboard row is allowed on the public view surface.
     *
     * @param array<string, mixed>|null $dashboard Dashboard row (must contain
     *   `dashboard_status`); null (UID not found) is treated as not-viewable.
     * @return bool True only when status is exactly 'published'.
     */
    public static function isViewableOnPublicRoute(?array $dashboard): bool
    {
        if (!is_array($dashboard)) {
            return false;
        }
        $status = $dashboard['dashboard_status'] ?? null;
        return $status === 'published';
    }
}
