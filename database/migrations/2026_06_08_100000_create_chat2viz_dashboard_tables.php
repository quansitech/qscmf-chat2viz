<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateChat2vizDashboardTables extends Migration
{
    /**
     * Schema fidelity notes:
     *
     * 1. Timestamp columns (created_at, updated_at) are database-managed.
     *    - created_at: DEFAULT CURRENT_TIMESTAMP (set on INSERT, never auto-updated)
     *    - updated_at: DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
     *
     * 2. Dual status design on dashboards:
     *    - status TINYINT: technical state (1=enabled, 0=disabled/soft-delete), universal
     *    - dashboard_status ENUM: business state (draft/published/archived), specific to dashboards
     *    These two dimensions are independent.
     *
     * 3. Conversations are tracked in qs_chat2viz_conversations (linked by dashboard_uid),
     *    not via a conversation_id column on dashboards.
     *
     * Cross-version: the qs_ prefix is applied by the host's framework grammar
     * (Laravel database.connections.*.prefix in v15, ThinkPHP DB_PREFIX in v13)
     * exactly once. Migrations and Eloquent models use bare names so the
     * framework prepends the project-level DB_PREFIX a single time. The raw-SQL
     * path (Table::physicalName) follows the same project-level prefix; see
     * src/Support/Table.php.
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
        Schema::create('chat2viz_dashboards', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键 ID');
            $table->string('uid', 36)->unique()->comment('UUID v4，URL 友好的短标识符');
            $table->string('title', 255)->default('')->comment('看板标题');
            $table->json('current_schema')->comment('当前正在编辑的 Dashboard Schema');
            $table->unsignedBigInteger('published_version_id')->nullable()->comment('指向已发布的版本快照');
            $table->unsignedTinyInteger('status')->default(1)->comment('技术状态：1=启用，0=禁用/软删除');
            $table->enum('dashboard_status', ['draft', 'published', 'archived'])->default('draft')->comment('业务状态：draft-草稿，published-已发布，archived-已归档');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人用户 ID');
            $table->timestamp('created_at')->useCurrent()->comment('创建时间');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');

            $table->index('status', 'idx_status');
            $table->index('created_by', 'idx_created_by');

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
        });

        Schema::create('chat2viz_dashboard_versions', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键 ID');
            $table->unsignedBigInteger('dashboard_id')->comment('所属看板 ID（关联 qs_chat2viz_dashboards.id）');
            $table->integer('version')->default(1)->comment('版本号，同一看板下从 1 递增');
            $table->json('schema')->comment('已发布的 Schema 快照（已清除数据）');
            $table->dateTime('published_at')->nullable()->comment('发布时间');
            $table->unsignedBigInteger('published_by')->nullable()->comment('发布人用户 ID');
            $table->timestamp('created_at')->useCurrent()->comment('创建时间');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');

            $table->index('dashboard_id', 'idx_dashboard_id');
            $table->unique(['dashboard_id', 'version'], 'uk_dashboard_version');
            $table->index('published_at', 'idx_published');

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
        });

        Schema::create('chat2viz_conversations', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键 ID');
            $table->string('dashboard_uid', 36)->comment('关联看板 UID（qs_chat2viz_dashboards.uid）');
            $table->string('title', 255)->default('')->comment('会话标题');
            $table->unsignedTinyInteger('status')->default(1)->comment('技术状态：1=启用，0=禁用/软删除');
            $table->timestamp('created_at')->useCurrent()->comment('创建时间');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');

            $table->index(['dashboard_uid', 'status', 'created_at'], 'idx_dashboard_uid_status');

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
        });

        Schema::create('chat2viz_conversation_messages', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键 ID');
            $table->unsignedBigInteger('conversation_id')->comment('会话 ID，关联 qs_chat2viz_conversations.id');
            $table->enum('role', ['user', 'assistant', 'system'])->comment('消息角色：user-用户提问，assistant-助手回复，system-系统提示');
            $table->longText('content')->comment('消息正文');
            $table->longText('reasoning_content')->nullable()->comment('推理过程内容');
            $table->json('tool_calls')->nullable()->comment('工具调用记录');
            $table->enum('message_status', ['streaming', 'complete', 'interrupted', 'failed'])
                ->default('complete')
                ->comment('消息状态：streaming-流式中，complete-完成，interrupted-中断，failed-失败');
            $table->json('metadata')->nullable()->comment('sql、g2_spec 等附加信息');
            $table->timestamp('created_at')->useCurrent()->comment('创建时间');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');

            // Index name carries the table segment so it cannot collide with the
            // same-named index on feedback_records under PostgreSQL (schema-scoped
            // global uniqueness, error 42P07).
            $table->index(['conversation_id', 'created_at'], 'idx_msg_conv_created');

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
        Schema::dropIfExists('chat2viz_conversation_messages');
        Schema::dropIfExists('chat2viz_conversations');
        Schema::dropIfExists('chat2viz_dashboard_versions');
        Schema::dropIfExists('chat2viz_dashboards');
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
