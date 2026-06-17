<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Renderer\SmartyRenderer;
use Qscmf\Chat2Viz\Renderer\InertiaRenderer;

/**
 * Verifies the CHAT2VIZ_SHOW_SQL feature flag.
 *
 * Default (unset) → show_sql is FALSE (the 查询语句 panel is hidden by default).
 * "true"/"1"/"on" → TRUE; "0"/"" → FALSE.
 *
 * Both renderers expose show_sql via a public static showSql() reader, which is
 * exercised directly here (no controller/Smarty boot required). The value is
 * threaded into __PAGE_DATA__ by the templates and gates the frontend Collapse
 * panels (ChatPanel, WidgetCard, DashboardView).
 *
 * env() is provided by the host framework autoload (see tests/bootstrap.php);
 * tests drive it with putenv().
 */
final class ShowSqlConfigTest extends TestCase
{
    private function withEnv(?string $value, callable $fn): void
    {
        if ($value === null) {
            putenv('CHAT2VIZ_SHOW_SQL'); // clear
        } else {
            putenv('CHAT2VIZ_SHOW_SQL=' . $value);
        }
        try {
            $fn();
        } finally {
            putenv('CHAT2VIZ_SHOW_SQL'); // always clear
        }
    }

    public function testSmartyShowSqlDefaultsToFalseWhenUnset(): void
    {
        $this->withEnv(null, fn () => $this->assertFalse(SmartyRenderer::showSql()));
    }

    public function testSmartyShowSqlTrueWhenEnvIsTrue(): void
    {
        $this->withEnv('true', fn () => $this->assertTrue(SmartyRenderer::showSql()));
    }

    public function testSmartyShowSqlTrueWhenEnvIsOne(): void
    {
        $this->withEnv('1', fn () => $this->assertTrue(SmartyRenderer::showSql()));
    }

    public function testSmartyShowSqlTrueWhenEnvIsOn(): void
    {
        $this->withEnv('on', fn () => $this->assertTrue(SmartyRenderer::showSql()));
    }

    public function testSmartyShowSqlFalseWhenEnvIsZero(): void
    {
        $this->withEnv('0', fn () => $this->assertFalse(SmartyRenderer::showSql()));
    }

    public function testSmartyShowSqlFalseWhenEnvIsEmptyString(): void
    {
        $this->withEnv('', fn () => $this->assertFalse(SmartyRenderer::showSql()));
    }

    public function testInertiaShowSqlMirrorsSmarty(): void
    {
        // Same flag, same default.
        $this->withEnv(null, function () {
            $this->assertFalse(InertiaRenderer::showSql());
        });
        $this->withEnv('true', function () {
            $this->assertTrue(InertiaRenderer::showSql());
        });
    }
}
