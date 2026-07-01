import { describe, it, expect } from 'vitest';
import {
  planActionCall,
  findInProgressToolStep,
} from '../asset/inertia/Chat2viz/utils/aiSteps';
import type { AiStep } from '../asset/inertia/Chat2viz/store/dashboardStore';

/** Helper: build a tool_start step. */
function step(id: string, label: string, completed = false): AiStep {
  return { id, type: 'tool_start', label, timestamp: id, completed };
}

describe('aiSteps — tool-call de-duplication (缺陷2 fix)', () => {
  it('appends a step when none is in progress', () => {
    const decision = planActionCall([], 'execute_sql', '执行查询...', () =>
      step('s1', '执行查询...'),
    );
    expect(decision.kind).toBe('add');
  });

  it('refreshes (collapses) a repeat call of the same type instead of appending', () => {
    // Regression guard for the "不断追加工具执行过程" anti-pattern: a repeated
    // execute_sql must NOT create a new row.
    const steps: AiStep[] = [step('s1', '执行查询...')];
    const decision = planActionCall(steps, 'execute_sql', '执行查询...', () =>
      step('s2', '执行查询...'),
    );
    expect(decision.kind).toBe('refresh');
    if (decision.kind === 'refresh') {
      expect(decision.id).toBe('s1');
    }
  });

  it('completes the prior in-progress tool when a different type arrives', () => {
    // Different action_type → its own row, but the running one is completed
    // first so only one spinner shows at a time.
    const steps: AiStep[] = [step('s1', '搜索相关表...')];
    const newStep = step('s2', '执行查询...');
    const decision = planActionCall(steps, 'execute_sql', '执行查询...', () => newStep);
    expect(decision.kind).toBe('completeAndAdd');
    if (decision.kind === 'completeAndAdd') {
      expect(decision.completeId).toBe('s1');
      expect(decision.step).toBe(newStep);
    }
  });

  it('keeps at most one in-progress tool step at a time (integration via findInProgress)', () => {
    // Simulate a stream: execute_sql x3 then describe_table.
    let steps: AiStep[] = [];
    let counter = 0;
    const apply = (actionType: string, label: string) => {
      const d = planActionCall(steps, actionType, label, () =>
        step(`s${++counter}`, label),
      );
      if (d.kind === 'refresh') {
        steps = steps.map((s) =>
          s.id === d.id ? { ...s, completed: true } : s,
        );
        steps.push(step(`s${++counter}`, label));
      } else if (d.kind === 'completeAndAdd') {
        if (d.completeId)
          steps = steps.map((s) =>
            s.id === d.completeId ? { ...s, completed: true } : s,
          );
        steps.push(d.step);
      } else {
        steps.push(d.step);
      }
    };
    apply('execute_sql', '执行查询...');
    apply('execute_sql', '执行查询...');
    apply('execute_sql', '执行查询...');
    apply('describe_table', '分析表结构...');

    const inProgress = findInProgressToolStep(steps);
    // Exactly one in-progress step remains (the describe_table row).
    const inProgressCount = steps.filter((s) => !s.completed).length;
    expect(inProgressCount).toBe(1);
    expect(inProgress?.label).toBe('分析表结构...');
  });

  it('findInProgressToolStep returns undefined when all steps are completed', () => {
    expect(findInProgressToolStep([step('s1', 'x', true)])).toBeUndefined();
    expect(findInProgressToolStep([])).toBeUndefined();
  });
});
