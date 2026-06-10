<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateChat2vizDashboardTables extends Migration
{
    /**
     * Schema fidelity notes (Layer 6 audit):
     *
     * 1. chat2viz_dashboard_versions.updated_at
     *    - Defined by $table->timestamps(), maps to timestamp NULL in MySQL.
     *    - Verified: actual DB column matches migration definition.
     *
     * 2. chat2viz_conversation_messages.content
     *    - Defined as TEXT NOT NULL with no default.
     *    - Application layer must always provide content; empty string is valid.
     *    - Verified: actual DB column matches migration definition.
     *
     * 3. Legacy conversation_id=NULL records in chat2viz_dashboards
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
        Schema::create('chat2viz_dashboards', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uid', 36)->unique()->comment('UUID v4, URL friendly short identifier');
            $table->string('title', 255)->default('');
            $table->json('current_schema')->comment('Current editing Dashboard Schema');
            $table->unsignedBigInteger('published_version_id')->nullable()->comment('Points to published version snapshot');
            $table->string('conversation_id', 64)->nullable()->comment('Associated conversation ID');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_status');
            $table->index('created_by', 'idx_created_by');

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
        });

        Schema::create('chat2viz_dashboard_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('dashboard_id');
            $table->integer('version')->default(1);
            $table->json('schema')->comment('Published Schema snapshot (data stripped)');
            $table->dateTime('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamps();

            $table->foreign('dashboard_id')
                ->references('id')
                ->on('chat2viz_dashboards')
                ->onDelete('cascade');

            $table->unique(['dashboard_id', 'version'], 'uk_dashboard_version');
            $table->index('published_at', 'idx_published');

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
        });

        Schema::create('chat2viz_conversation_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('conversation_id', 64);
            $table->string('dashboard_uid', 64);
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('content');
            $table->json('metadata')->nullable()->comment('sql, g2_spec, tool_calls etc.');
            $table->timestamp('created_at')->default(\Illuminate\Support\Facades\DB::raw('CURRENT_TIMESTAMP'));

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
        Schema::dropIfExists('chat2viz_conversation_messages');
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
