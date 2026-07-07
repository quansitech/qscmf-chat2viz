<?php

namespace Qscmf\Chat2Viz\Support;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Qscmf\Chat2Viz\Support\Table
 *
 * Pins the contract: physical table name = ENV('DB_PREFIX', '') . $bareName.
 * No host detection, no connection probe, no hardcoded qs_ — purely project
 * config driven. ENV() is the project convention; it reads $_SERVER / $_ENV
 * (Laravel dotenv) and falls back to getenv() (ThinkPHP ENV() helper).
 */
class TableTest extends TestCase
{
    protected function tearDown(): void
    {
        // Unset so tests do not leak state. Both $_ENV (read by Laravel env())
        // and process env (read by getenv() in some ENV() impls) are scrubbed.
        putenv('DB_PREFIX');
        unset($_ENV['DB_PREFIX'], $_SERVER['DB_PREFIX']);
    }

    /**
     * Set DB_PREFIX across all three env-lookup sources so the project-style
     * ENV() helper resolves it the same way in every host (Laravel dotenv,
     * ThinkPHP ENV(), getenv()).
     */
    private function setDbPrefix(?string $value): void
    {
        if ($value === null) {
            putenv('DB_PREFIX');
            unset($_ENV['DB_PREFIX'], $_SERVER['DB_PREFIX']);
        } else {
            putenv('DB_PREFIX=' . $value);
            $_ENV['DB_PREFIX'] = $value;
            $_SERVER['DB_PREFIX'] = $value;
        }
    }

    public function testNameUsesDbPrefixWhenSet(): void
    {
        $this->setDbPrefix('qs_');
        self::assertSame('qs_chat2viz_dashboards', Table::name('chat2viz_dashboards'));
    }

    public function testNameReturnsBareWhenPrefixEmpty(): void
    {
        $this->setDbPrefix('');
        self::assertSame('chat2viz_dashboards', Table::name('chat2viz_dashboards'));
    }

    public function testNameReturnsBareWhenPrefixMissing(): void
    {
        $this->setDbPrefix(null);
        self::assertSame('chat2viz_dashboards', Table::name('chat2viz_dashboards'));
    }

    public function testPhysicalNameSameAsName(): void
    {
        $this->setDbPrefix('qs_');
        self::assertSame('qs_actor', Table::physicalName('actor'));
        self::assertSame(
            Table::name('chat2viz_dashboards'),
            Table::physicalName('chat2viz_dashboards')
        );
    }

    public function testNameHandlesNonStandardPrefix(): void
    {
        // Prefix is project-configurable; package code does not hardcode qs_.
        $this->setDbPrefix('t_');
        self::assertSame('t_chat2viz_dashboards', Table::name('chat2viz_dashboards'));
    }
}
