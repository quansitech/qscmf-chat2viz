/**
 * G2Spec — TypeScript type mirroring Python G2SpecModel.
 *
 * Generated from app/agent/spec_model.py (spec-typed-contract).
 * This is the single source of truth for G2 v5 spec structure on the frontend.
 * Specs stored in the DB are validated at commit_widget ingress, so any spec
 * reaching the frontend conforms to this type.
 */

export type G2MarkType =
  | 'interval' | 'line' | 'area' | 'point' | 'cell' | 'view'
  | 'text' | 'link' | 'vector' | 'polygon' | 'image' | 'box'
  | 'shape' | 'liquid' | 'gauge' | 'lineX' | 'lineY'
  | 'rangeX' | 'rangeY' | 'connector' | 'sankey'
  | 'pie' | 'table'
  | string; // forward-compatible: unknown types pass through with a warning

/** Encode channel value: bare string, array of strings, or Vega-Lite object
 * (coerced to string by G2SpecModel at ingress). */
export type G2EncodeValue = string | string[] | { field: string; type?: string };

export interface G2Encode {
  x?: G2EncodeValue;
  y?: G2EncodeValue;
  color?: G2EncodeValue;
  size?: G2EncodeValue;
  shape?: G2EncodeValue;
  facet?: G2EncodeValue;
  [key: string]: G2EncodeValue | undefined; // extra channels
}

export interface G2Transform {
  type: string;
  by?: string;
  order?: string;
  [key: string]: unknown;
}

export interface G2Coordinate {
  type?: string;
  transform?: Record<string, unknown>[];
  [key: string]: unknown;
}

export interface G2Spec {
  type: G2MarkType;
  encode?: G2Encode;
  transform?: G2Transform[];
  coordinate?: G2Coordinate;
  children?: G2Spec[];
  title?: string | { text?: string };
  style?: Record<string, unknown>;
  scale?: Record<string, unknown>;
  axis?: Record<string, unknown>;
  legend?: Record<string, unknown>;
  labels?: unknown;
  [key: string]: unknown; // extra="allow" — forward-compatible
}
