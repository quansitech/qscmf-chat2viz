<?php

namespace Qscmf\Chat2Viz\Traits;

/**
 * Strip g2_spec.data from widget schemas during publish.
 */
trait SchemaStripTrait
{
    /**
     * Recursively strip g2_spec.data from all widgets in the schema.
     *
     * Pure function: returns a new array without modifying the input.
     * Walks through schema.widgets array and removes the 'data' key
     * from every widget's g2_spec object (if it exists).
     */
    private function stripG2SpecData(array $schema): array
    {
        if (!isset($schema['widgets']) || !is_array($schema['widgets'])) {
            return $schema;
        }

        $widgets = $schema['widgets'];
        foreach ($widgets as $i => $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if (isset($widget['g2_spec']) && is_array($widget['g2_spec'])) {
                unset($widget['g2_spec']['data']);
                $widgets[$i] = $widget;
            }
        }

        $schema['widgets'] = $widgets;
        return $schema;
    }
}
