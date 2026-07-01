<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Service;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\PublicViewStatusGuard;

/**
 * Unit tests for PublicViewStatusGuard::isViewableOnPublicRoute().
 *
 * fix-public-view-draft-exposure §3.1 / §3.2: the public view surface must
 * reject draft / archived / unknown-status dashboards with an error page
 * identical to "UID not found" (anti-enumeration). This pure-function test
 * verifies the status gate without booting the ThinkPHP controller stack.
 */
class PublicViewStatusGuardTest extends TestCase
{
    // -------------------------------------------------------------------------
    // §3.1 — draft rejected
    // -------------------------------------------------------------------------

    public function testDraftNotViewable(): void
    {
        $this->assertFalse(PublicViewStatusGuard::isViewableOnPublicRoute([
            'uid' => 'draft-uid',
            'dashboard_status' => 'draft',
        ]));
    }

    // -------------------------------------------------------------------------
    // §3.2 — archived rejected
    // -------------------------------------------------------------------------

    public function testArchivedNotViewable(): void
    {
        $this->assertFalse(PublicViewStatusGuard::isViewableOnPublicRoute([
            'uid' => 'archived-uid',
            'dashboard_status' => 'archived',
        ]));
    }

    // -------------------------------------------------------------------------
    // published — viewable (positive control)
    // -------------------------------------------------------------------------

    public function testPublishedViewable(): void
    {
        $this->assertTrue(PublicViewStatusGuard::isViewableOnPublicRoute([
            'uid' => 'pub-uid',
            'dashboard_status' => 'published',
        ]));
    }

    // -------------------------------------------------------------------------
    // null (UID not found) — not viewable, same as draft (anti-enumeration)
    // -------------------------------------------------------------------------

    public function testNullDashboardNotViewable(): void
    {
        $this->assertFalse(PublicViewStatusGuard::isViewableOnPublicRoute(null));
    }

    // -------------------------------------------------------------------------
    // Missing status key — defaults to not-viewable (defense)
    // -------------------------------------------------------------------------

    public function testMissingStatusKeyNotViewable(): void
    {
        $this->assertFalse(PublicViewStatusGuard::isViewableOnPublicRoute([
            'uid' => 'no-status-uid',
        ]));
    }

    // -------------------------------------------------------------------------
    // Unknown status value — not viewable
    // -------------------------------------------------------------------------

    public function testUnknownStatusNotViewable(): void
    {
        $this->assertFalse(PublicViewStatusGuard::isViewableOnPublicRoute([
            'uid' => 'weird-uid',
            'dashboard_status' => 'pending',
        ]));
    }
}
