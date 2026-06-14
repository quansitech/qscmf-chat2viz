import { useEffect, useRef, useCallback, useState } from 'react';
import { Modal, message } from 'antd';
import { useDashboardStore } from '../store/dashboardStore';
import { buildSchema } from '../utils/buildSchema';
import { ADMIN_BASE } from '../utils/routes';

// ---------------------------------------------------------------------------
// Debounce delays by trigger source
// ---------------------------------------------------------------------------

const DEBOUNCE_AI_EVENT_MS = 3000;
const DEBOUNCE_LAYOUT_MS = 500;
const DEBOUNCE_TITLE_MS = 1000;

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface SaveOptions {
  forceOverwrite?: boolean;
}

type SaveSource = 'ai' | 'layout' | 'title';

interface UseDashboardDraftReturn {
  /** Trigger a save for a specific event source (controls debounce delay). */
  save: (source: SaveSource) => void;
  /** Immediately save the current draft (bypasses debounce). */
  saveNow: () => Promise<void>;
  /** Whether a save request is in-flight. */
  isSaving: boolean;
  /** ISO timestamp of the last successful save. */
  lastSavedAt: string | null;
  /** Whether there are unsaved changes. */
  isDirty: boolean;
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

export function useDashboardDraft(): UseDashboardDraftReturn {
  const isDirty = useDashboardStore((s) => s.isDirty);
  const lastSavedAt = useDashboardStore((s) => s.lastSavedAt);

  const [saving, setSaving] = useState(false);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const updatedAtRef = useRef<string | null>(null);
  const abortRef = useRef<AbortController | null>(null);

  // ---- Core save implementation (HIGH-5: AbortController for cancellation) ----

  const performSave = useCallback(
    async (options: SaveOptions = {}) => {
      // Cancel any in-flight save request
      if (abortRef.current) {
        abortRef.current.abort();
      }
      const controller = new AbortController();
      abortRef.current = controller;

      // Read fresh state at call time
      const state = useDashboardStore.getState();
      if (!state.isDirty && !options.forceOverwrite) return;

      const currentUid = state.uid;
      if (!currentUid) return; // not saved yet — creation handled by store

      const schema = buildSchema(state.widgets);
      setSaving(true);

      try {
        const body: Record<string, unknown> = {
          title: state.title,
          current_schema: schema,
        };

        // Send updated_at for optimistic locking if we have it
        if (updatedAtRef.current) {
          body.updated_at = updatedAtRef.current;
        }
        if (options.forceOverwrite) {
          body.force_overwrite = true;
        }

        const resp = await fetch(`${ADMIN_BASE}/api_update/uid/${currentUid}`, {
          method: 'PUT',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
          signal: controller.signal,
        });

        if (controller.signal.aborted) return;

        if (!resp.ok) {
          throw new Error(`HTTP ${resp.status}: ${resp.statusText}`);
        }

        const result = await resp.json();

        if (result.status === 1) {
          updatedAtRef.current = result.data?.updated_at ?? new Date().toISOString();
          useDashboardStore.setState({
            isDirty: false,
            lastSavedAt: new Date().toISOString(),
          });
        } else if (result.conflict) {
          // Optimistic lock conflict — ask user whether to overwrite
          handleConflict(currentUid);
        } else {
          message.error(result.info || '保存失败');
        }
      } catch (err) {
        if (controller.signal.aborted) return;
        message.error(err instanceof Error ? err.message : '网络错误，保存失败');
      } finally {
        if (abortRef.current === controller) {
          abortRef.current = null;
        }
        setSaving(false);
      }
    },
    [],
  );

  // ---- Conflict resolution ----

  const conflictModalRef = useRef<ReturnType<typeof Modal.confirm> | null>(null);

  const handleConflict = useCallback(
    (conflictUid: string) => {
      // Prevent duplicate modals
      if (conflictModalRef.current) return;

      conflictModalRef.current = Modal.confirm({
        title: '内容冲突',
        content: '该仪表盘已被其他会话修改，是否用当前更改覆盖？',
        okText: '覆盖',
        cancelText: '放弃我的更改',
        onOk: async () => {
          conflictModalRef.current = null;
          // Force overwrite
          const state = useDashboardStore.getState();
          const schema = buildSchema(state.widgets);
          setSaving(true);
          try {
            const resp = await fetch(
              `${ADMIN_BASE}/api_update/uid/${conflictUid}`,
              {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  title: state.title,
                  current_schema: schema,
                  force_overwrite: true,
                }),
              },
            );
            if (!resp.ok) {
              throw new Error(`HTTP ${resp.status}: ${resp.statusText}`);
            }
            const result = await resp.json();
            if (result.status === 1) {
              updatedAtRef.current = result.data?.updated_at ?? new Date().toISOString();
              useDashboardStore.setState({
                isDirty: false,
                lastSavedAt: new Date().toISOString(),
              });
              message.success('已覆盖保存');
            } else {
              message.error(result.info || '覆盖失败');
            }
          } catch {
            message.error('网络错误');
          } finally {
            setSaving(false);
          }
        },
        onCancel: () => {
          conflictModalRef.current = null;
          // Mark as not dirty to prevent further auto-save attempts
          useDashboardStore.setState({ isDirty: false });
        },
      });
    },
    [],
  );

  // ---- Debounced save by source ----

  const save = useCallback(
    (source: 'ai' | 'layout' | 'title') => {
      // Clear previous timer
      if (timerRef.current) {
        clearTimeout(timerRef.current);
        timerRef.current = null;
      }

      const delay =
        source === 'ai'
          ? DEBOUNCE_AI_EVENT_MS
          : source === 'layout'
            ? DEBOUNCE_LAYOUT_MS
            : DEBOUNCE_TITLE_MS;

      timerRef.current = setTimeout(() => {
        performSave();
        timerRef.current = null;
      }, delay);
    },
    [performSave],
  );

  // ---- Immediate save (Ctrl+S) ----

  const saveNow = useCallback(async () => {
    // Cancel any pending debounced save
    if (timerRef.current) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
    await performSave();
  }, [performSave]);

  // ---- Ctrl+S keyboard listener ----

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveNow();
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [saveNow]);

  // ---- beforeunload warning for unsaved changes ----

  useEffect(() => {
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      const state = useDashboardStore.getState();
      if (state.isDirty) {
        e.preventDefault();
        // Modern browsers ignore custom messages but require preventDefault()
        return '';
      }
    };

    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, []);

  // ---- Auto-save subscription: detect isDirty transitions and trigger ----
  // ---- debounced save with the appropriate source.                    ----

  const saveRef = useRef(save);
  saveRef.current = save;

  useEffect(() => {
    let prevWidgets = useDashboardStore.getState().widgets;
    let prevTitle = useDashboardStore.getState().title;

    const unsubscribe = useDashboardStore.subscribe((state, prevState) => {
      // Only react when isDirty transitions from false to true
      if (prevState.isDirty || !state.isDirty) return;

      // Suppress auto-save during SSE streaming — save once after completion.
      if (state.streamingState === 'streaming') return;

      // Determine the source by comparing what changed
      let source: SaveSource = 'ai'; // default for SSE-driven changes

      if (state.title !== prevTitle) {
        source = 'title';
        prevTitle = state.title;
      } else if (state.widgets !== prevWidgets) {
        source = 'layout';
        prevWidgets = state.widgets;
      }

      saveRef.current(source);
    });

    return unsubscribe;
  }, []);

  // ---- Flush deferred save when streaming ends and draft is still dirty ----

  useEffect(() => {
    const unsubscribe = useDashboardStore.subscribe((state, prevState) => {
      if (prevState.streamingState !== 'streaming' || state.streamingState === 'streaming') return;
      // Streaming just ended — if there are unsaved changes, flush now.
      if (state.isDirty) {
        saveRef.current('ai');
      }
    });
    return unsubscribe;
  }, []);

  // ---- Cleanup on unmount: clear pending timers + abort in-flight saves ----

  useEffect(() => {
    return () => {
      if (timerRef.current) {
        clearTimeout(timerRef.current);
        timerRef.current = null;
      }
      if (abortRef.current) {
        abortRef.current.abort();
        abortRef.current = null;
      }
      if (conflictModalRef.current) {
        conflictModalRef.current.destroy();
        conflictModalRef.current = null;
      }
    };
  }, []);

  return { save, saveNow, isSaving: saving, lastSavedAt: lastSavedAt as unknown as string | null, isDirty: isDirty as unknown as boolean };
}
