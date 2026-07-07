<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * SchemaStripTrait v3 keyed-map + data-null tests (code-review H8).
 *
 * The trait must: (1) preserve the string widget_id keys of the v3 keyed map,
 * (2) strip widgets[id].data → null, (3) still handle the legacy v2 indexed
 * list + g2_spec.data shape.
 */
final class SchemaStripTraitTest extends TestCase
{
    /**
     * Expose the private trait method via a reflection helper.
     */
    private function callStrip(array $schema): array
    {
        $trait = new class {
            use \Qscmf\Chat2Viz\Traits\SchemaStripTrait;
        };
        $ref = new \ReflectionMethod($trait, 'stripG2SpecData');
        $ref->setAccessible(true);
        return $ref->invoke($trait, $schema);
    }

    public function test_preserves_v3_keyed_map_widget_ids(): void
    {
        $schema = [
            'version' => '3.0.0',
            'widgets' => [
                'w1' => ['widget_id' => 'w1', 'data' => ['rows' => [['a' => 1]]]],
                'w2' => ['widget_id' => 'w2', 'data' => null],
            ],
        ];
        $out = $this->callStrip($schema);
        // String keys preserved (not flattened to 0-indexed).
        $this->assertSame(['w1', 'w2'], array_keys($out['widgets']));
    }

    public function test_strips_v3_widget_data_to_null(): void
    {
        $schema = [
            'version' => '3.0.0',
            'widgets' => [
                'w1' => ['widget_id' => 'w1', 'data' => ['rows' => [['a' => 1]]]],
            ],
        ];
        $out = $this->callStrip($schema);
        $this->assertNull($out['widgets']['w1']['data']);
    }

    public function test_strips_legacy_v2_g2_spec_data(): void
    {
        $schema = [
            'widgets' => [
                ['id' => 'w1', 'g2_spec' => ['type' => 'line', 'data' => [['x' => 1]]]],
            ],
        ];
        $out = $this->callStrip($schema);
        // g2_spec.data stripped; g2_spec.type preserved.
        $this->assertArrayNotHasKey('data', $out['widgets'][0]['g2_spec']);
        $this->assertSame('line', $out['widgets'][0]['g2_spec']['type']);
    }

    public function test_preserves_other_schema_keys(): void
    {
        $schema = [
            'version' => '3.0.0',
            'queries' => ['q1' => ['query_id' => 'q1']],
            'widgets' => ['w1' => ['data' => null]],
            'slicers' => [],
        ];
        $out = $this->callStrip($schema);
        $this->assertSame('3.0.0', $out['version']);
        $this->assertArrayHasKey('queries', $out);
        $this->assertArrayHasKey('slicers', $out);
    }
}
