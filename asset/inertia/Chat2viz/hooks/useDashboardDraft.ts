import { useEffect, useRef, useCallback, useState } from 'react';
import { Modal, message } from 'antd';
import { useDashboardStore, saveDashboardDraft } from '../store/dashboardStore';
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

  // ---- api_update path (extracted so the !uid branch can call it after the
  // conversation_id frame backfills the uid) ----

  const performSaveFromUpdate = useCallback(
    async (uid: string, options: SaveOptions = {}) => {
      const state = useDashboardStore.getState();

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

        const resp = await fetch(`${ADMIN_BASE}/api_update/uid/${uid}`, {
          method: 'PUT',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
          signal: abortRef.current?.signal,
        });

        if (resp.ok) {
          const result = await resp.json();
          if (result.status === 1) {
            updatedAtRef.current = result.data?.updated_at ?? new Date().toISOString();
            useDashboardStore.setState({
              isDirty: false,
              lastSavedAt: new Date().toISOString(),
            });
            if (options.forceOverwrite) {
              message.success('已覆盖保存');
            }
          } else if (result.conflict) {
            handleConflict(uid);
          } else {
            message.error(result.info || '保存失败');
          }
        }
      } catch (err) {
        // ignore abort errors
      } finally {
        setSaving(false);
      }
    },
    [],
  );

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
      if (!currentUid) {
        // conversation-one-to-one: on a brand-new dashboard, the backend's
        // initializeOnFirstMessage creates the dashboard + conversation + user
        // message in one transaction and returns the uid via the conversation_id
        // SSE frame. If a conversationId is already in the store (the frame
        // arrived but the uid write is racing), SKIP the client-side api_create
        // — it would create a SECOND orphan dashboard. Instead, re-read the
        // uid after a microtask; if it has arrived, proceed to api_update; if
        // not, defer (the flush-on-stream-end subscription will retry).
        if (state.conversationId) {
          const settledUid = useDashboardStore.getState().uid;
          if (settledUid) {
            // uid arrived between the read above and now — fall through to
            // the api_update path below by reassigning.
            // (re-read fresh state to avoid stale closure)
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
            const u = useDashboardStore.getState().uid!;
            return performSaveFromUpdate(u, options);
          }
          // uid not yet settled — skip this save; the stream-end flush will retry
          // once the conversation_id frame has backfilled the uid.
          return;
        }
        // No conversationId either: this is a manual save (Ctrl+S) on a
        // dashboard that has never been chatted on. Delegate to api_create.
        setSaving(true);
        try {
          await saveDashboardDraft();
          const newUid = useDashboardStore.getState().uid;
          if (newUid) {
            window.history.replaceState(null, '', `${ADMIN_BASE}/edit?uid=${newUid}`);
          }
        } finally {
          setSaving(false);
        }
        return;
      }

      return performSaveFromUpdate(currentUid, options);
    },
    [performSaveFromUpdate],
  );

  // ---- Conflict resolution ----

  const conflictModalRef = useRef<ReturnType<typeof Modal.confirm> | null>(null);

  // Stable ref so Modal.confirm's onOk (created once per conflict) can call
  // the latest performSave without re-creating the modal.
  const performSaveRef = useRef(performSave);
  performSaveRef.current = performSave;

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
          // task 5.2: reuse performSave instead of a separate fetch — this
          // routes through the same AbortController + state-update path as
          // the normal save, so cancel-on-unmount, in-flight replacement,
          // and isDirty/lastSavedAt bookkeeping all stay consistent.
          await performSaveRef.current({ forceOverwrite: true });
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
  // N-2 root cause A: the flush subscription below MUST save immediately on
  // stream end, not via the 3s debounce. The previous saveRef.current('ai')
  // left a 3s window where unmount (cleanup aborts in-flight saves + clears
  // the timer) or a page refresh silently dropped the whole streaming result
  // — the store updated and G2 re-rendered, but the save never fired, so the
  // DB kept the old value and a refresh reverted the edit. saveNow bypasses
  // debounce and persists the complete result at the instant the stream ends.
  const saveNowRef = useRef(saveNow);
  saveNowRef.current = saveNow;

  useEffect(() => {
    let prevWidgets = useDashboardStore.getState().widgets;
    let prevTitle = useDashboardStore.getState().title;

    const unsubscribe = useDashboardStore.subscribe((state, prevState) => {
      // Only react when isDirty transitions from false to true
      if (prevState.isDirty || !state.isDirty) return;

      // Suppress auto-save while an ask is in flight — covers both the
      // 'submitted' (pre-first-frame) and 'streaming' phases. The full result
      // is persisted once after completion via the flush subscription below.
      if (state.streamingState !== 'idle') return;

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
      // Fire once when an in-flight ask ends (any non-idle → idle transition),
      // covering 'submitted' → idle and 'streaming' → idle.
      if (prevState.streamingState === 'idle' || state.streamingState !== 'idle') return;
      // Streaming just ended — flush IMMEDIATELY (N-2: saveNow, not the 3s
      // debounced save) so the full result is persisted before any refresh or
      // unmount can cancel it. All WIDGET_UPDATE edits are already applied to
      // the store when the stream goes idle, so there is nothing left to settle.
      if (state.isDirty) {
        saveNowRef.current();
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
