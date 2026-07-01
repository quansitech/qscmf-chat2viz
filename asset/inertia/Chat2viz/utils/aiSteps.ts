import type { AiStep } from '../store/dashboardStore';

/**
 * Pure helpers for AI-step bookkeeping, extracted from the SSE dispatcher so
 * they can be unit-tested without a live store.
 *
 * Design (matches the agreed review outcome):
 *  - At most ONE in-progress ("processing") tool step at a time.
 *  - Repeated `action_call` events for the SAME `action_type` refresh the
 *    existing in-progress step (label/timestamp) instead of appending a new
 *    row — this is the fix for the "不断追加工具执行过程" anti-pattern.
 *  - Different `action_type`s each get their own step, but any prior
 *    in-progress tool step is completed first so the indicator shows a single
 *    spinner.
 *  - `action_call_result` completes the most recent in-progress tool step.
 */

export function findInProgressToolStep(steps: AiStep[]): AiStep | undefined {
  for (let i = steps.length - 1; i >= 0; i--) {
    const s = steps[i];
    if (s.type === 'tool_start' && !s.completed) return s;
  }
  return undefined;
}

/**
 * Decide how to react to an incoming `action_call` of `actionType`.
 *
 * Returns one of:
 *  - { kind: 'refresh', id, label }  — update an existing in-progress step of
 *    the same type (no new row).
 *  - { kind: 'completeAndAdd', completeId?, step } — complete the current
 *    in-progress tool step (if any, possibly of a different type) and append a
 *    fresh step.
 *  - { kind: 'add', step } — no in-progress step exists; just append.
 */
export type ActionCallDecision =
  | { kind: 'refresh'; id: string; label: string }
  | { kind: 'completeAndAdd'; completeId?: string; step: AiStep }
  | { kind: 'add'; step: AiStep };

export function planActionCall(
  steps: AiStep[],
  actionType: string,
  label: string,
  makeStep: () => AiStep,
): ActionCallDecision {
  const inProgress = findInProgressToolStep(steps);

  // Same type currently running → refresh it in place.
  if (inProgress && inProgress.type === 'tool_start' && inProgress.label === label) {
    return { kind: 'refresh', id: inProgress.id, label };
  }

  // A different tool is running → complete it, then add the new one.
  if (inProgress) {
    return { kind: 'completeAndAdd', completeId: inProgress.id, step: makeStep() };
  }

  // Nothing in progress → just append.
  return { kind: 'add', step: makeStep() };
}
