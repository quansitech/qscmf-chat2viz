<?php

namespace Qscmf\Chat2Viz\Traits;

/**
 * Strip render-only data from widget schemas during publish.
 *
 * Handles BOTH the legacy v2 shape (widgets as an indexed list, each carrying
 * g2_spec.data) and the v3 DSL shape (widgets as a keyed map keyed by
 * widget_id, each carrying top-level data). The widget map's string keys are
 * preserved (v3 contract §2.1: key == widget_id).
 */
trait SchemaStripTrait
{
    /**
     * Strip inline data from all widgets in the schema.
     *
     * Pure function: returns a new array without modifying the input.
     * Each widget is explicitly copied before any modification, ensuring
     * the original $schema remains untouched regardless of PHP internals.
     *
     * - v3: `widgets[id].data` → null (slim, contract §5).
     * - v2 legacy: `widgets[].g2_spec.data` → unset.
     *
     * @param array $schema Dashboard schema containing a 'widgets' key
     * @return array New schema with inline data stripped from every widget
     */
    private function stripG2SpecData(array $schema): array
    {
        if (!isset($schema['widgets']) || !is_array($schema['widgets'])) {
            return $schema;
        }

        $newWidgets = [];
        // Preserve BOTH keyed-map (v3: widget_id => widget) and indexed-list
        // (v2 legacy: 0 => widget) shapes. Using the key in the foreach keeps
        // the string widget_id keys intact for v3.
        foreach ($schema['widgets'] as $key => $widget) {
            $newWidget = $widget; // explicit copy — never mutate the original

            // v3 DSL: strip top-level widget.data → null (slim, contract §5).
            if (array_key_exists('data', $newWidget)) {
                $newWidget['data'] = null;
            }

            // v2 legacy: strip g2_spec.data (nested).
            if (isset($newWidget['g2_spec']['data'])) {
                $strippedSpec = $newWidget['g2_spec'];
                unset($strippedSpec['data']);
                $newWidget['g2_spec'] = $strippedSpec;
            }

            $newWidgets[$key] = $newWidget;
        }

        return ['widgets' => $newWidgets] + $schema;
    }
}
