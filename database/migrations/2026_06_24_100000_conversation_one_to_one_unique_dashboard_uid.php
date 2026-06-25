<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * 强制 conversations 与 dashboard 的 1:1 关系：
 *   在 qs_chat2viz_conversations.dashboard_uid 上加唯一约束。
 *
 * 历史会话/消息数据必须在迁移前清空，否则唯一约束会因重复 dashboard_uid 失败。
 */
class ConversationOneToOneUniqueDashboardUid extends Migration
{
    public function beforeCmmUp() {}
    public function beforeCmmDown() {}

    public function up()
    {
        Schema::table('qs_chat2viz_conversations', function (Blueprint $table) {
            $table->unique('dashboard_uid', 'uk_dashboard_uid');
        });
    }

    public function down()
    {
        Schema::table('qs_chat2viz_conversations', function (Blueprint $table) {
            $table->dropUnique('uk_dashboard_uid');
        });
    }
}