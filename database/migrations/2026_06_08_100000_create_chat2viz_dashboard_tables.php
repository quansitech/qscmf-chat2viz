<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateChat2vizDashboardTables extends Migration
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
        Schema::create('qs_chat2viz_dashboards', function (Blueprint $table) {
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

        Schema::create('qs_chat2viz_dashboard_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('dashboard_id');
            $table->integer('version')->default(1);
            $table->json('schema')->comment('Published Schema snapshot (data stripped)');
            $table->dateTime('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamps();

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
