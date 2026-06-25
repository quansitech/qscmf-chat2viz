<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * conversation-one-to-one-and-first-msg-init
 *
 * Enforces a strict 1:1 relationship between a dashboard and its conversation
 * by adding a UNIQUE constraint on conversations.dashboard_uid.
 *
 * Prior to this migration, multiple conversations could exist per dashboard
 * (the old active/archived multi-session design). That capability was never
 * exposed in the UI and caused conversation_id fragmentation bugs. The product
 * is now 1:1 — one dashboard = one continuous conversation.
 *
 * The old composite index idx_dashboard_uid_status (dashboard_uid, status,
 * created_at) is redundant once dashboard_uid is UNIQUE (the unique index
 * already covers lookups; status is no longer filtered post-1:1). It is dropped
 * to avoid a redundant index.
 *
 * PREREQUISITE: historical conversation/message data MUST be cleared before
 * running this migration, otherwise the UNIQUE constraint will fail on duplicate
 * dashboard_uid rows.
 */
class ConversationOneToOneUniqueDashboardUid extends Migration
{
    public function beforeCmmUp()
    {
        //
    }

    public function beforeCmmDown()
    {
        //
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Drop the old composite index (dashboard_uid + status + created_at).
        // Status filtering was removed in the 1:1 refactor; the UNIQUE index
        // below fully covers dashboard_uid lookups.
        $hasOldIndex = DB::selectOne(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'qs_chat2viz_conversations'
               AND INDEX_NAME = 'idx_dashboard_uid_status'
             LIMIT 1"
        );
        if ($hasOldIndex) {
            DB::statement('ALTER TABLE qs_chat2viz_conversations DROP INDEX idx_dashboard_uid_status');
        }

        // Add UNIQUE constraint — the core of the 1:1 guarantee.
        $hasUnique = DB::selectOne(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'qs_chat2viz_conversations'
               AND INDEX_NAME = 'uk_dashboard_uid'
             LIMIT 1"
        );
        if (!$hasUnique) {
            DB::statement('ALTER TABLE qs_chat2viz_conversations ADD UNIQUE KEY uk_dashboard_uid (dashboard_uid)');
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $hasUnique = DB::selectOne(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'qs_chat2viz_conversations'
               AND INDEX_NAME = 'uk_dashboard_uid'
             LIMIT 1"
        );
        if ($hasUnique) {
            DB::statement('ALTER TABLE qs_chat2viz_conversations DROP INDEX uk_dashboard_uid');
        }

        $hasOldIndex = DB::selectOne(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'qs_chat2viz_conversations'
               AND INDEX_NAME = 'idx_dashboard_uid_status'
             LIMIT 1"
        );
        if (!$hasOldIndex) {
            DB::statement('ALTER TABLE qs_chat2viz_conversations ADD INDEX idx_dashboard_uid_status (dashboard_uid, status, created_at)');
        }
    }
}
