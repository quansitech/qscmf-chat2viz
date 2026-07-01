/**
 * Bounded-concurrency promise runner.
 *
 * Runs an array of async tasks with at most `concurrency` in flight at once.
 * A dependency-free alternative to p-limit: react-query already handles
 * per-key de-duplication, staleTime caching and view-gating (`enabled`); this
 * helper only adds a hard in-flight cap as a defensive backstop that protects
 * the backend DB connection pool and aligns with the browser's HTTP/1.1
 * per-origin connection ceiling (~6).
 *
 * Tasks are dispatched in input order; resolution order is preserved in the
 * returned array (like Promise.all). A rejected task rejects the whole batch —
 * callers wanting per-task error isolation should wrap their task body in a
 * try/catch that resolves a sentinel instead of throwing.
 *
 * @example
 *   const rows = await runWithConcurrency(
 *     ids.map((id) => () => fetchWidget(id)),
 *     6,
 *   );
 */
export async function runWithConcurrency<T>(
  tasks: (() => Promise<T>)[],
  concurrency: number,
): Promise<T[]> {
  const n = Math.max(1, Math.floor(concurrency));
  const results: T[] = new Array(tasks.length);
  let cursor = 0;

  async function worker(): Promise<void> {
    while (true) {
      const index = cursor++;
      if (index >= tasks.length) return;
      results[index] = await tasks[index]();
    }
  }

  const workers: Promise<void>[] = [];
  for (let i = 0; i < Math.min(n, tasks.length); i++) {
    workers.push(worker());
  }
  await Promise.all(workers);
  return results;
}

/**
 * Create a reusable semaphore that caps concurrent executions at `concurrency`.
 *
 * Unlike {@link runWithConcurrency} (one-shot batch), this returns a long-lived
 * `withLimit` wrapper: any number of call sites can schedule tasks through it
 * over time and the cap is enforced across all of them. Ideal for wrapping
 * react-query `queryFn`s so a shared instance throttles the whole page.
 *
 * @example
 *   const limit = createSemaphore(6);
 *   useQuery({ queryKey, queryFn: () => limit(() => fetchWidget(id)) });
 */
export function createSemaphore(concurrency: number) {
  const max = Math.max(1, Math.floor(concurrency));
  let active = 0;
  const queue: (() => void)[] = [];

  function pump(): void {
    while (active < max && queue.length > 0) {
      const resolve = queue.shift()!;
      active++;
      resolve();
    }
  }

  return function withLimit<T>(task: () => Promise<T>): Promise<T> {
    return new Promise<T>((resolve, reject) => {
      const run = (): void => {
        task()
          .then(resolve, reject)
          .finally(() => {
            active--;
            pump();
          });
      };
      if (active < max) {
        active++;
        run();
      } else {
        queue.push(run);
      }
    });
  };
}
