<?php

namespace Qscmf\Chat2Viz\Support;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Model\Conversation;
use Qscmf\Chat2Viz\Model\ConversationMessage;
use Qscmf\Chat2Viz\Model\Dashboard;
use Qscmf\Chat2Viz\Model\DashboardVersion;

/**
 * @covers \Qscmf\Chat2Viz\Model\Dashboard::$table
 * @covers \Qscmf\Chat2Viz\Model\DashboardVersion::$table
 * @covers \Qscmf\Chat2Viz\Model\Conversation::$table
 * @covers \Qscmf\Chat2Viz\Model\ConversationMessage::$table
 *
 * Each Eloquent model declares a BARE name as $table — the framework grammar
 * adds the host's DB_PREFIX exactly once. No getTable() override, no
 * Table::name() routing. The same code runs on v13 (ThinkModel) and v15
 * (Eloquent); the host's DB_PREFIX is the single source of truth.
 */
class ModelTableTest extends TestCase
{
    public function testModelTablesAreBareNames(): void
    {
        self::assertSame('chat2viz_dashboards', (new Dashboard())->getTable());
        self::assertSame('chat2viz_dashboard_versions', (new DashboardVersion())->getTable());
        self::assertSame('chat2viz_conversations', (new Conversation())->getTable());
        self::assertSame('chat2viz_conversation_messages', (new ConversationMessage())->getTable());
    }
}
