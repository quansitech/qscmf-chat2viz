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

/** The 11 standard Chat2Viz event types (legacy single-chart conversation). */
const STANDARD_EVENT_TYPES = [
  'answer',
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

/**
 * Dashboard-specific event types under the declarative-frontend-adapter
 * whole-tree protocol (contract §1/§5). The deprecated DASHBOARD_INIT /
 * WIDGET_DATA_UPDATE / dashboard_patch / WIDGET_UPDATE / WIDGET_REMOVE /
 * dashboard_rollback / action_call / action_call_result were collapsed into
 * DASHBOARD_REPLACE; tool_start / tool_result are the §5 tool-progress names.
 */
const DASHBOARD_EVENT_TYPES = [
  'DASHBOARD_REPLACE',
  'WIDGET_READY',
  'WIDGET_ERROR',
  'tool_start',
  'tool_result',
] as const;

export type StandardEventType = (typeof STANDARD_EVENT_TYPES)[number];
export type DashboardEventType = (typeof DASHBOARD_EVENT_TYPES)[number];
export type SseEventType = StandardEventType | DashboardEventType;

// ---------------------------------------------------------------------------
// Parsed event shape
// ---------------------------------------------------------------------------

export interface SseEvent {
  /** The SSE event name (e.g. "WIDGET_DATA_UPDATE", "action_call"). */
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
      // Per SSE spec: strip exactly ONE leading space (if present), not all.
      // trimStart() would corrupt data values that legitimately begin with spaces.
      const afterColon = line.slice(5);
      dataLines.push(afterColon.startsWith(' ') ? afterColon.slice(1) : afterColon);
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

