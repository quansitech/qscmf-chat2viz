<?php
declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Service;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\PreviewSchemaResolver;

/**
 * fix-draft-view-restore: the admin preview action renders a read-only view
 * using current_schema (live draft). PreviewSchemaResolver centralizes the
 * decode so it is unit-testable without the controller stack (global I()/C()
 * are unavailable under PHPUnit — mirrors PublicViewStatusGuardTest).
 *
 * @covers \Qscmf\Chat2Viz\Service\PreviewSchemaResolver::resolveFromCurrentSchema
 */
class PreviewSchemaResolverTest extends TestCase
{
    // draft row with a JSON-string current_schema → decoded to the widgets map.
    public function test_resolvesJsonStringCurrentSchema(): void
    {
        $dashboard = [
            'uid' => 'dash-1', 'dashboard_status' => 'draft',
            'current_schema' => json_encode([
                'layout' => [['i' => 'w1', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6]],
                'widgets' => ['w1' => ['widget_id' => 'w1', 'sql' => 'SELECT 1']],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $schema = PreviewSchemaResolver::resolveFromCurrentSchema($dashboard);

        $this->assertArrayHasKey('widgets', $schema);
        $this->assertSame('SELECT 1', $schema['widgets']['w1']['sql']);
        $this->assertSame('w1', $schema['layout'][0]['i']);
    }

    // current_schema already an array (some ORM paths) → passed through.
    public function test_resolvesArrayCurrentSchema(): void
    {
        $dashboard = ['current_schema' => ['widgets' => ['w1' => ['sql' => 'SELECT 1']]]];
        $schema = PreviewSchemaResolver::resolveFromCurrentSchema($dashboard);
        $this->assertSame('SELECT 1', $schema['widgets']['w1']['sql']);
    }

    // empty / invalid / missing → [] (preview never fatals on malformed rows).
    public function test_returnsEmptyForEmptyInvalidOrMissing(): void
    {
        foreach ([
            null,
            [],
            ['current_schema' => ''],
            ['current_schema' => null],
            ['current_schema' => 'not-json{'],
            ['current_schema' => '123'],          // valid JSON scalar, not array
            ['current_schema' => json_encode('string-value')],
        ] as $label => $row) {
            $this->assertSame(
                [],
                PreviewSchemaResolver::resolveFromCurrentSchema($row),
                "must return [] for input #" . $label . ': ' . json_encode($row)
            );
        }
    }

    // works for any status (admin preview serves draft/published/archived alike).
    public function test_statusAgnostic(): void
    {
        foreach (['draft', 'published', 'archived'] as $status) {
            $dashboard = [
                'dashboard_status' => $status,
                'current_schema' => json_encode(['widgets' => ['w1' => ['sql' => 'SELECT 1']]]),
            ];
            $schema = PreviewSchemaResolver::resolveFromCurrentSchema($dashboard);
            $this->assertSame('SELECT 1', $schema['widgets']['w1']['sql'], "$status must resolve identically");
        }
    }

    // round-trips a realistic multi-widget dashboard schema verbatim.
    public function test_roundTripsRealisticMultiWidgetSchema(): void
    {
        $full = [
            'layout' => [
                ['i' => 'w1', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
                ['i' => 'w2', 'x' => 12, 'y' => 0, 'w' => 12, 'h' => 6],
            ],
            'widgets' => [
                'w1' => ['widget_id' => 'w1', 'title' => 'A', 'sql' => 'SELECT 1', 'g2_spec' => ['type' => 'interval']],
                'w2' => ['widget_id' => 'w2', 'title' => 'B', 'sql' => 'SELECT 2', 'g2_spec' => ['type' => 'line']],
            ],
        ];
        $dashboard = ['current_schema' => json_encode($full, JSON_UNESCAPED_UNICODE)];

        $this->assertSame($full, PreviewSchemaResolver::resolveFromCurrentSchema($dashboard));
    }
}
