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
     * Walks through schema.widgets array and removes the 'data' key
     * from every widget's g2_spec object (if it exists).
     */
    private function stripG2SpecData(array $schema): array
    {
        if (!isset($schema['widgets']) || !is_array($schema['widgets'])) {
            return $schema;
        }

        foreach ($schema['widgets'] as $i => $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if (isset($widget['g2_spec']) && is_array($widget['g2_spec'])) {
                unset($schema['widgets'][$i]['g2_spec']['data']);
            }
        }

        return $schema;
    }
}
