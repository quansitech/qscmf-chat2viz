import { describe, it, expect } from 'vitest';
import { runWithConcurrency, createSemaphore } from '../asset/inertia/Chat2viz/utils/concurrency';

/** Sleep helper. */
const sleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));

describe('concurrency — bounded parallel fetching (议题3b)', () => {
  it('runs all tasks and preserves result order', async () => {
    const tasks = [3, 1, 2].map((n) => async () => {
      await sleep(n * 10);
      return n;
    });
    const results = await runWithConcurrency(tasks, 2);
    expect(results).toEqual([3, 1, 2]);
  });

  it('never exceeds the concurrency cap', async () => {
    let active = 0;
    let peak = 0;
    const tasks = Array.from({ length: 20 }, () => async () => {
      active++;
      peak = Math.max(peak, active);
      await sleep(10);
      active--;
      return active;
    });
    await runWithConcurrency(tasks, 6);
    // Cap is 6; allow no slack.
    expect(peak).toBeLessThanOrEqual(6);
  });

  it('createSemaphore shares one cap across many independent callers', async () => {
    const limit = createSemaphore(6);
    let active = 0;
    let peak = 0;
    const run = () =>
      limit(async () => {
        active++;
        peak = Math.max(peak, active);
        await sleep(10);
        active--;
      });
    // 15 independent callers scheduled nearly simultaneously.
    await Promise.all(Array.from({ length: 15 }, () => run()));
    expect(peak).toBeLessThanOrEqual(6);
    expect(peak).toBeGreaterThan(1); // sanity: it did parallelize
  });

  it('createSemaphore preserves resolution values and errors', async () => {
    const limit = createSemaphore(3);
    await expect(limit(async () => 42)).resolves.toBe(42);
    await expect(limit(async () => { throw new Error('boom'); })).rejects.toThrow('boom');
  });

  it('handles an empty task list', async () => {
    expect(await runWithConcurrency([], 6)).toEqual([]);
  });
});
