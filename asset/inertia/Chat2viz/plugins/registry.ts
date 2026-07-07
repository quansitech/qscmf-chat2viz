/**
 * PluginRegistry — directory-driven plugin dispatch (low-code pattern).
 *
 * Maps each contract widget `plugin_type` (§2.7) to a `PluginDefinition`
 * carrying the React component + optional validate/fallback/suggestHeight.
 * `<PluginRenderer>` does `getPlugin(plugin_type) ?? markdownFallback` — zero
 * if/else on chart kind in render code. Adding a plugin = one file + one
 * `registerPlugin` call.
 *
 * The registry NEVER throws: unknown/null/undefined types all resolve to the
 * markdown fallback definition (which itself may render a phase-aware error or
 * a silent markdown placeholder depending on the active phase constant).
 *
 * `filter` is deliberately NOT registered here — it is a slicer (§2.7 line 199),
 * rendered by <SlicerPanel>, not a widget plugin.
 */
import type { ComponentType } from 'react';
import type { PluginType, WidgetSpec } from '../types/dsl';

// ---------------------------------------------------------------------------
// Build-time phase constant (contract §2.7 lines 201-203)
//
// Phase 0-2 = error-and-rewind: unknown plugin_type renders an explicit error
//   widget prompting regeneration (no silent markdown — silent degradation
//   would swallow LLM production errors during the contract's "error-and-
//   rewind" phase). Default while Python has not shipped directory discovery.
// Phase 3+  = silent markdown fallback (never blank), once directory discovery
//   is active.
//
// This is a build-time constant (no runtime env read) so the conformance suite
// can pin both phases via a single source. Bump to 3 when Python ships.
// ---------------------------------------------------------------------------

export const PLUGIN_PHASE = 2;
export const UNKNOWN_PLUGIN_RENDERS_ERROR = PLUGIN_PHASE < 3;

// ---------------------------------------------------------------------------
// PluginProps — the uniform props contract every plugin component receives
// ---------------------------------------------------------------------------

export interface PluginProps {
  /** The widget being rendered. */
  widget: WidgetSpec;
  /** The render-side resolved data rows (from widgetDataCache). */
  data: Record<string, unknown>[];
  /** Total row count (server-reported; may exceed data.length when truncated). */
  total?: number;
  /** Whether the result was truncated by the row cap. */
  truncated?: boolean;
  /** Drill/detail callback for InteractionSpec wiring (§2.6). */
  onDrill?: (params: Record<string, unknown>) => void;
}

// ---------------------------------------------------------------------------
// PluginDefinition
// ---------------------------------------------------------------------------

export interface PluginDefinition {
  /** The React component that renders this plugin_type. */
  component: ComponentType<PluginProps>;
  /** Optional spec validator (returns an error message string or null). */
  validate?: (pluginSpec: Record<string, unknown>) => string | null;
  /** Optional fallback component when validate fails (defaults to markdown). */
  fallback?: ComponentType<PluginProps>;
  /** Optional height heuristic override (units of ROW_HEIGHT). */
  suggestHeight?: (data: Record<string, unknown>[]) => number;
}

// ---------------------------------------------------------------------------
// Registry
//
// A plain Map — populated at module load by the plugins/index.ts side-effect.
// Lazy imports are avoided so the registry is fully populated before any
// <PluginRenderer> mounts (the SPA has no SSR/code-splitting for these).
// ---------------------------------------------------------------------------

const registry = new Map<string, PluginDefinition>();

// The markdown fallback definition is set by plugins/index.ts AFTER
// MarkdownPlugin registers itself. Until then getPlugin returns a noop marker.
let markdownFallbackDefinition: PluginDefinition | null = null;

/** Internal: register the markdown plugin as the universal fallback. */
export function setMarkdownFallback(def: PluginDefinition): void {
  markdownFallbackDefinition = def;
  // Also register under 'markdown' so direct lookups resolve too.
  if (!registry.has('markdown')) {
    registry.set('markdown', def);
  }
}

/** Register a plugin definition for a plugin_type. */
export function registerPlugin(type: PluginType | string, def: PluginDefinition): void {
  registry.set(type, def);
  if (type === 'markdown' && !markdownFallbackDefinition) {
    markdownFallbackDefinition = def;
  }
}

/**
 * Look up a plugin definition by plugin_type. Returns the markdown fallback
 * when the type is unregistered, null, or undefined. NEVER throws.
 */
export function getPlugin(type: string | null | undefined): PluginDefinition {
  if (typeof type === 'string' && type !== '' && registry.has(type)) {
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
    return registry.get(type)!;
  }
  if (markdownFallbackDefinition) return markdownFallbackDefinition;
  // Defensive: if nothing is registered yet (module load race), return an
  // inline noop that renders nothing rather than throwing.
  return {
    component: () => null,
  };
}

/** Enumerate the registered plugin_type strings (excludes the fallback). */
export function listRegisteredPlugins(): string[] {
  return Array.from(registry.keys());
}
