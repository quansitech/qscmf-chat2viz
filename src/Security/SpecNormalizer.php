<?php
/**
 * SpecNormalizer — cleans LLM-generated G2 v5 specs before they reach the frontend.
 *
 * The LLM occasionally produces structurally invalid specs that crash G2 v5
 * (TypeError: h is not iterable / o.flatMap is not a function / invalid format).
 * This normalizer fixes the most common mistakes so existing dirty specs in
 * the DB are healed on read, without requiring a data migration.
 *
 * Mirrors the Python-side normalize_g2_spec() in app/agent/parser.py.
 */
namespace Qscmf\Chat2Viz\Security;

class SpecNormalizer
{
    /** Numeric style fields that must be numbers, not strings. */
    private const STYLE_NUMERIC_KEYS = [
        'opacity', 'fillOpacity', 'strokeOpacity', 'lineWidth',
        'lineDash', 'size', 'radius', 'innerRadius',
        'fontWeight', 'fontSize', 'rotation',
    ];

    /** String-formatted function fields that G2 v5 cannot eval. */
    private const FUNCTION_STRING_KEYS = [
        'labelFormatter', 'nameFormatter', 'valueFormatter',
    ];

    /**
     * Normalize an entire dashboard schema (widgets + their g2_spec).
     *
     * @param array $schema Dashboard schema with 'widgets' key
     * @return array Normalized schema
     */
    public static function normalizeSchema(array $schema): array
    {
        // Gate: when spec validation is ON (default), specs are already valid at
        // DB write time — normalization is a no-op pass-through.
        // When OFF (rollback), full normalization runs.
        // (spec-typed-contract: encode-normalize gate, Decision 7)
        $enabled = getenv('CHAT2VIZ_SPEC_VALIDATION_ENABLED');
        $enabled = ($enabled === false) ? true : strtolower($enabled) !== 'false';
        if ($enabled) {
            return $schema; // pass-through — specs already validated
        }

        if (!isset($schema['widgets']) || !is_array($schema['widgets'])) {
            return $schema;
        }
        $schema['widgets'] = array_map(function ($widget) {
            if (is_array($widget) && isset($widget['g2_spec']) && is_array($widget['g2_spec'])) {
                $widget['g2_spec'] = self::normalizeSpec($widget['g2_spec']);
            }
            return $widget;
        }, $schema['widgets']);
        return $schema;
    }

    /**
     * Normalize a single G2 spec object.
     */
    public static function normalizeSpec(array $spec): array
    {
        return self::normalizeNode($spec);
    }

    private static function normalizeNode($node)
    {
        if (!is_array($node)) {
            return $node;
        }
        $result = [];
        foreach ($node as $key => $value) {
            switch ($key) {
                case 'children':
                    $result[$key] = self::normalizeChildren($value);
                    break;
                case 'style':
                    $result[$key] = is_array($value) ? self::normalizeStyle($value) : $value;
                    break;
                case 'labels':
                    $result[$key] = self::normalizeLabels($value);
                    break;
                case 'axis':
                case 'legend':
                    $result[$key] = is_array($value) ? self::normalizeAxisLegend($value) : $value;
                    break;
                case 'transform':
                case 'coordinate':
                case 'point':
                    // These can contain {item: {...}} wrappers or string
                    // function fields — unwrap and recurse.
                    $result[$key] = is_array($value) ? self::normalizeNode(self::unwrapItem($value)) : $value;
                    break;
                default:
                    // Recurse into any other dict to catch nested item wrappers
                    $result[$key] = is_array($value) ? self::normalizeNode(self::unwrapItem($value)) : $value;
            }
        }
        return $result;
    }

    private static function normalizeChildren($value): array
    {
        // Bare array — already correct
        if (is_array($value) && self::isList($value)) {
            return array_map([self::class, 'normalizeNode'], $value);
        }
        // {item: [...]} or {children: [...]} wrapper → unwrap
        if (is_array($value)) {
            foreach (['item', 'children', 'nodes'] as $wrapper) {
                if (isset($value[$wrapper]) && is_array($value[$wrapper]) && self::isList($value[$wrapper])) {
                    return array_map([self::class, 'normalizeNode'], $value[$wrapper]);
                }
            }
            // Single object-as-child
            return [self::normalizeNode($value)];
        }
        return [];
    }

    private static function normalizeStyle(array $style): array
    {
        $result = [];
        foreach ($style as $k => $v) {
            $result[$k] = in_array($k, self::STYLE_NUMERIC_KEYS, true) ? self::coerceNumeric($v) : $v;
        }
        return $result;
    }

    private static function normalizeLabels($value)
    {
        $value = self::unwrapItem($value);
        if (is_array($value) && self::isList($value)) {
            return array_map([self::class, 'normalizeNode'], $value);
        }
        return self::normalizeNode($value);
    }

    private static function normalizeAxisLegend(array $value): array
    {
        $result = [];
        foreach ($value as $k => $v) {
            // Drop string-formatted functions — G2 can't eval them
            if (in_array($k, self::FUNCTION_STRING_KEYS, true) && is_string($v)) {
                continue;
            }
            // Coerce string booleans
            if (is_string($v) && in_array(strtolower($v), ['true', 'false'], true)) {
                $result[$k] = strtolower($v) === 'true';
            } elseif (is_array($v)) {
                $result[$k] = self::normalizeAxisLegend(self::unwrapItem($v));
            } else {
                $result[$k] = $v;
            }
        }
        return $result;
    }

    private static function unwrapItem($value)
    {
        if (is_array($value) && count($value) === 1 && isset($value['item'])) {
            return $value['item'];
        }
        return $value;
    }

    private static function coerceNumeric($value)
    {
        if (is_string($value)) {
            $s = trim($value);
            if ($s !== '' && !in_array(strtolower($s), ['true', 'false', 'nan'], true)) {
                if (preg_match('/^-?\d+$/', $s)) return (int)$s;
                if (preg_match('/^-?\d*\.\d+$/', $s)) return (float)$s;
            }
        }
        return $value;
    }

    /** Check if array is a list (sequential numeric keys from 0). */
    private static function isList(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
