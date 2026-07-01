<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Create the feedback_records table for user feedback on AI answers.
 *
 * Column names MUST match the Python FeedbackReader FEEDBACK_COLUMNS
 * byte-for-byte (cross-repo contract, adversarial review CRITICAL-3):
 *   id, message_id, conversation_id, turn_index, question, answer_text,
 *   answer_widgets, thumbs, comment, implicit_signals, industry, created_at
 *
 * Design notes:
 * - Binary feedback only (thumbs up/down, no star ratings — adversarial review).
 * - turn_index tracks the conversation turn for degradation metrics.
 * - implicit_signals stored as JSON (regenerated, widget_deleted, sql_edited).
 * - answer_widgets stored as JSON array of chart specs.
 */
class CreateChat2vizFeedbackTables extends Migration
{
    public function beforeCmmUp()
    {
    }

    public function beforeCmmDown()
    {
    }

    public function up()
    {
        if (Schema::hasTable('qs_chat2viz_feedback_records')) {
            return;
        }

        Schema::create('qs_chat2viz_feedback_records', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键 ID');
            $table->string('message_id', 64)->default('')->comment('关联消息 ID');
            $table->string('conversation_id', 64)->default('')->comment('会话 ID（空字符串表示匿名）');
            $table->unsignedInteger('turn_index')->nullable()->comment('会话轮次（用于退化指标）');
            $table->text('question')->nullable()->comment('用户原始问题');
            $table->text('answer_text')->nullable()->comment('AI 回答文本');
            $table->json('answer_widgets')->nullable()->comment('AI 生成的图表配置 JSON');
            // Binary feedback — NO star ratings (adversarial review consensus)
            $table->enum('thumbs', ['up', 'down'])->nullable()->comment('点赞/点踩');
            $table->text('comment')->nullable()->comment('用户文字反馈');
            $table->json('implicit_signals')->nullable()->comment('隐式信号 JSON（regenerated/widget_deleted/sql_edited）');
            $table->string('industry', 32)->nullable()->comment('识别到的行业');
            $table->timestamp('created_at')->useCurrent()->comment('创建时间');

            $table->index(['thumbs', 'created_at'], 'idx_thumbs_created');
            $table->index(['conversation_id', 'created_at'], 'idx_conv_created');
            $table->index('message_id', 'idx_message_id');

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
        });
    }

    public function down()
    {
        Schema::dropIfExists('qs_chat2viz_feedback_records');
    }

    public function afterCmmUp()
    {
    }

    public function afterCmmDown()
    {
    }
}
