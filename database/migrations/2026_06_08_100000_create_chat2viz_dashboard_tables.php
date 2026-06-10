<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateChat2vizDashboardTables extends Migration
{
    /**
     * Schema fidelity notes (Layer 6 audit):
     *
     * 1. Timestamp columns (created_at, updated_at) are database-managed.
     *    - created_at: DEFAULT CURRENT_TIMESTAMP (set on INSERT, never auto-updated)
     *    - updated_at: DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
     *      (set on INSERT and automatically updated on every row modification)
     *    - Verified: actual DB columns match migration definition.
     *
     * 2. qs_chat2viz_conversation_messages.content
     *    - Defined as TEXT NOT NULL with no default.
     *    - Application layer must always provide content; empty string is valid.
     *    - Verified: actual DB column matches migration definition.
     *
     * 3. Legacy conversation_id=NULL records in qs_chat2viz_dashboards
     *    - The dashboards table allows conversation_id to be NULL (nullable column).
     *    - Old dashboards created before the conversation linkage feature may have
     *      conversation_id=NULL. These records cannot be retroactively linked to
     *      conversations because the original conversation data no longer exists.
     *    - This is an accepted historical artifact; no data recovery is needed.
     */

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
        Schema::create('qs_chat2viz_dashboards', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键 ID');
            $table->string('uid', 36)->unique()->comment('UUID v4，URL 友好的短标识符');
            $table->string('title', 255)->default('')->comment('看板标题');
            $table->json('current_schema')->comment('当前正在编辑的 Dashboard Schema');
            $table->unsignedBigInteger('published_version_id')->nullable()->comment('指向已发布的版本快照');
            $table->string('conversation_id', 64)->nullable()->comment('关联的会话 ID');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->comment('看板状态：draft-草稿，published-已发布，archived-已归档');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人用户 ID');
            $table->timestamp('created_at')->useCurrent()->comment('创建时间（数据库自动维护，DEFAULT CURRENT_TIMESTAMP）');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间（数据库自动维护，DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP）');

            $table->index('status', 'idx_status');
            $table->index('created_by', 'idx_created_by');

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
        });

        Schema::create('qs_chat2viz_dashboard_versions', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键 ID');
            $table->unsignedBigInteger('dashboard_id')->comment('所属看板 ID（外键关联 qs_chat2viz_dashboards.id，级联删除）');
            $table->integer('version')->default(1)->comment('版本号，同一看板下从 1 递增，唯一约束 (dashboard_id, version)');
            $table->json('schema')->comment('已发布的 Schema 快照（已清除数据）');
            $table->dateTime('published_at')->nullable()->comment('发布时间，未发布时为 NULL');
            $table->unsignedBigInteger('published_by')->nullable()->comment('发布人用户 ID');
            $table->timestamp('created_at')->useCurrent()->comment('创建时间（数据库自动维护，DEFAULT CURRENT_TIMESTAMP）');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间（数据库自动维护，DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP）');

            $table->foreign('dashboard_id')
                ->references('id')
                ->on('qs_chat2viz_dashboards')
                ->onDelete('cascade');

            $table->unique(['dashboard_id', 'version'], 'uk_dashboard_version');
            $table->index('published_at', 'idx_published');

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
        });

        Schema::create('qs_chat2viz_conversation_messages', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键 ID');
            $table->string('conversation_id', 64)->comment('会话 ID，关联一次完整的对话');
            $table->string('dashboard_uid', 64)->comment('关联的看板 UID（qs_chat2viz_dashboards.uid）');
            $table->enum('role', ['user', 'assistant', 'system'])->comment('消息角色：user-用户提问，assistant-助手回复，system-系统提示');
            $table->text('content')->comment('消息正文（TEXT NOT NULL，由应用层保证始终有值）');
            $table->json('metadata')->nullable()->comment('sql、g2_spec、tool_calls 等附加信息');
            $table->timestamp('created_at')->useCurrent()->comment('创建时间（数据库自动维护，DEFAULT CURRENT_TIMESTAMP）');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间（数据库自动维护，DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP）');

            $table->index(['conversation_id', 'created_at'], 'idx_conv_created');
            $table->index('dashboard_uid', 'idx_dashboard');

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('qs_chat2viz_conversation_messages');
        Schema::dropIfExists('qs_chat2viz_dashboard_versions');
        Schema::dropIfExists('qs_chat2viz_dashboards');
    }

    public function afterCmmUp()
    {
        //
    }

    public function afterCmmDown()
    {
        //
    }
}
