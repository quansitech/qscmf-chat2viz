/**
 * Unit tests for sse-parser.ts — validates parsing and streaming behavior.
 *
 * Run with: npx vitest run asset/inertia/Chat2viz/sse-parser.test.ts
 * (or jest, depending on host project config)
 */
import { describe, it, expect } from 'vitest';
import { parseSseBlock, createSseProcessor, type SseEvent } from './sse-parser';

describe('parseSseBlock', () => {
  it('parses a single answer event', () => {
    const result = parseSseBlock('event: answer\ndata: {"delta":"SELECT"}');
    expect(result).toEqual({ type: 'answer', data: { delta: 'SELECT' } });
  });

  it('parses a sql event', () => {
    const result = parseSseBlock('event: sql\ndata: {"sql":"SELECT 1"}');
    expect(result).toEqual({ type: 'sql', data: { sql: 'SELECT 1' } });
  });

  it('parses a tool event', () => {
    const result = parseSseBlock('event: tool\ndata: {"name":"search_objects"}');
    expect(result).toEqual({ type: 'tool', data: { name: 'search_objects' } });
  });

  it('parses a done event', () => {
    const result = parseSseBlock('event: done\ndata: {}');
    expect(result).toEqual({ type: 'done', data: {} });
  });

  it('parses an error event', () => {
    const result = parseSseBlock('event: error\ndata: {"type":"upstream_disconnected","info":"分析服务连接中断"}');
    expect(result).toEqual({
      type: 'error',
      data: { type: 'upstream_disconnected', info: '分析服务连接中断' },
    });
  });

  it('concatenates multi-line data', () => {
    const result = parseSseBlock('event: answer\ndata: {"delta":"line1"}\ndata: {"delta":"line2"}');
    // Multi-line data is joined with \n and parsed as JSON
    expect(result).not.toBeNull();
    expect(result!.type).toBe('answer');
  });

  it('ignores comment lines (heartbeat)', () => {
    const result = parseSseBlock(': heartbeat');
    expect(result).toBeNull();
  });

  it('returns null for empty block', () => {
    expect(parseSseBlock('')).toBeNull();
    expect(parseSseBlock('   ')).toBeNull();
  });

  it('handles g2_spec event', () => {
    const result = parseSseBlock('event: g2_spec\ndata: {"type":"line","x":"date","y":"value"}');
    expect(result).toEqual({
      type: 'g2_spec',
      data: { type: 'line', x: 'date', y: 'value' },
    });
  });
});

describe('createSseProcessor', () => {
  it('buffers partial chunks', () => {
    const events: SseEvent[] = [];
    const processor = createSseProcessor((e) => events.push(e));

    processor.processChunk('event: answ');
    expect(events).toHaveLength(0);

    processor.processChunk('er\ndata: {"delta":"hi"}\n\n');
    expect(events).toHaveLength(1);
    expect(events[0]).toEqual({ type: 'answer', data: { delta: 'hi' } });
  });

  it('processes multiple events in one chunk', () => {
    const events: SseEvent[] = [];
    const processor = createSseProcessor((e) => events.push(e));

    processor.processChunk('event: sql\ndata: {"sql":"SELECT 1"}\n\nevent: done\ndata: {}\n\n');
    expect(events).toHaveLength(2);
    expect(events[0].type).toBe('sql');
    expect(events[1].type).toBe('done');
  });

  it('ignores heartbeat comments', () => {
    const events: SseEvent[] = [];
    const processor = createSseProcessor((e) => events.push(e));

    processor.processChunk(': heartbeat\n\n');
    expect(events).toHaveLength(0);
  });

  it('flush emits trailing partial event', () => {
    const events: SseEvent[] = [];
    const processor = createSseProcessor((e) => events.push(e));

    processor.processChunk('event: answer\ndata: {"delta":"hello"}');
    expect(events).toHaveLength(0);

    processor.flush();
    expect(events).toHaveLength(1);
    expect(events[0]).toEqual({ type: 'answer', data: { delta: 'hello' } });
  });

  it('flush ignores comment-only trailing buffer', () => {
    const events: SseEvent[] = [];
    const processor = createSseProcessor((e) => events.push(e));

    processor.processChunk(': trailing');
    processor.flush();
    expect(events).toHaveLength(0);
  });
});
