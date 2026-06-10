<?php

namespace Qscmf\Chat2Viz\Traits;

/**
 * Strip g2_spec.data from widget schemas during publish.
 */
trait SchemaStripTrait
{
    /**
     * Strip g2_spec.data from all widgets in the schema.
     *
     * Pure function: returns a new array without modifying the input.
     * Each widget is explicitly copied before any modification, ensuring
     * the original $schema remains untouched regardless of PHP internals.
     *
     * @param array $schema Dashboard schema containing a 'widgets' key
     * @return array New schema with g2_spec.data stripped from every widget
     */
    private function stripG2SpecData(array $schema): array
    {
        if (!isset($schema['widgets']) || !is_array($schema['widgets'])) {
            return $schema;
        }

        $newWidgets = [];
        foreach ($schema['widgets'] as $widget) {
            $newWidget = $widget; // explicit copy — never mutate the original

            if (isset($newWidget['g2_spec']['data'])) {
                $strippedSpec = $newWidget['g2_spec'];
                unset($strippedSpec['data']);
                $newWidget['g2_spec'] = $strippedSpec;
            }

            $newWidgets[] = $newWidget;
        }

        return ['widgets' => $newWidgets] + $schema;
    }
}
