// ---------------------------------------------------------------------------
// SSE Event Parser for Chat2Viz
//
// Parses raw SSE text frames (as produced by the SsePassthrough backend) into
// typed event objects.  Each SSE frame follows the standard format:
//
//   event: <type>
//   data: <json-string>
//
// The parser also handles multi-line data fields (split with \n) and frames
// that omit the explicit `event:` line (defaults to "message").
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Event type constants
// ---------------------------------------------------------------------------

/** The 12 standard Chat2Viz event types (single-chart conversation). */
const STANDARD_EVENT_TYPES = [
  'answer',
  'chart_ready',
  'sql_generated',
  'thinking',
  'thinking_done',
  'data_preview',
  'schema_info',
  'column_stats',
  'error',
  'done',
  'conversation_id',
  'metadata',
] as const;

/** The 3 additional dashboard-specific event types. */
const DASHBOARD_EVENT_TYPES = [
  'action_call',
  'action_call_result',
  'dashboard_patch',
] as const;

export type StandardEventType = (typeof STANDARD_EVENT_TYPES)[number];
export type DashboardEventType = (typeof DASHBOARD_EVENT_TYPES)[number];
export type SseEventType = StandardEventType | DashboardEventType;

// ---------------------------------------------------------------------------
// Parsed event shape
// ---------------------------------------------------------------------------

export interface SseEvent {
  /** The SSE event name (e.g. "chart_ready", "action_call"). */
  type: SseEventType | string;
  /** The parsed JSON data payload. */
  data: Record<string, unknown>;
  /** The raw data string (before JSON parse), useful for debugging. */
  raw: string;
}

// ---------------------------------------------------------------------------
// Parser
// ---------------------------------------------------------------------------

/**
 * Parse a single SSE frame (the text between two `\n\n` boundaries) into a
 * structured SseEvent.
 *
 * Returns `null` if the frame is empty or the data cannot be parsed.
 */
export function parseSseEvent(frame: string): SseEvent | null {
  const lines = frame.split('\n');
  let eventType = 'message';
  const dataLines: string[] = [];

  for (const line of lines) {
    // Skip comments
    if (line.startsWith(':')) continue;

    if (line.startsWith('event:')) {
      eventType = line.slice(6).trim();
    } else if (line.startsWith('data:')) {
      dataLines.push(line.slice(5).trimStart());
    } else if (line.includes(':')) {
      // Unknown field; ignore per SSE spec
    }
  }

  if (dataLines.length === 0) return null;

  const raw = dataLines.join('\n');

  let data: Record<string, unknown>;
  try {
    const parsed = JSON.parse(raw);
    data = typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)
      ? parsed
      : { value: parsed };
  } catch {
    // If JSON parse fails, wrap the raw text
    data = { text: raw };
  }

  return { type: eventType, data, raw };
}

// ---------------------------------------------------------------------------
// Type guards
// ---------------------------------------------------------------------------

export function isStandardEvent(type: string): type is StandardEventType {
  return (STANDARD_EVENT_TYPES as readonly string[]).includes(type);
}

export function isDashboardEvent(type: string): type is DashboardEventType {
  return (DASHBOARD_EVENT_TYPES as readonly string[]).includes(type);
}

// ---------------------------------------------------------------------------
// Dashboard event type definitions for downstream consumers
// ---------------------------------------------------------------------------

/** Emitted when the AI requests the frontend to execute an action. */
export interface ActionCallEvent {
  type: 'action_call';
  data: {
    action_type: string;
    params: Record<string, unknown>;
  };
}

/** Emitted to report the outcome of a previously dispatched action_call. */
export interface ActionCallResultEvent {
  type: 'action_call_result';
  data: {
    success: boolean;
    result: unknown;
  };
}

/** Emitted to apply partial updates (JSON-Patch style) to the dashboard. */
export interface DashboardPatchEvent {
  type: 'dashboard_patch';
  data: {
    patches: Array<{
      op: 'add' | 'remove' | 'replace';
      path: string;
      value?: unknown;
    }>;
  };
}
