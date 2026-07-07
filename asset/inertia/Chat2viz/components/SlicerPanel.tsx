import { useMemo } from 'react';
import { DatePicker, Input, InputNumber, Select, Typography } from 'antd';
import { useDashboardStore } from '../store/dashboardStore';
import { useSlicerOptions } from '../hooks/useWidgetData';
import { useSlicerReducer } from '../hooks/useSlicerReducer';
import type { SlicerSpec, QueryParamType } from '../types/dsl';

// ---------------------------------------------------------------------------
// Component
//
// Renders one antd control per dsl.slicers[*], choosing the control by the
// referenced query param's `type` (contract §3.3):
//   string   → Select (options via useSlicerOptions) or Input (no options)
//   number   → InputNumber
//   date     → DatePicker
//   datetime → DatePicker with showTime
// Dispatches via useSlicerReducer (single setSlicerValue side effect).
// ---------------------------------------------------------------------------

interface SlicerPanelProps {
  uid?: string;
  /** Edit-page linkage carries the in-memory DSL (form B). */
  dslFormB?: boolean;
}

/** Resolve the QueryParam.type for a slicer's first target_query_params entry. */
function resolveSlicerParamType(
  slicer: SlicerSpec,
  paramTypes: Map<string, QueryParamType>,
): QueryParamType {
  const keys = Object.keys(slicer.target_query_params ?? {});
  if (keys.length === 0) return 'string';
  // The param name is the value of the first entry; look it up in the query.
  const queryId = keys[0];
  const paramName = slicer.target_query_params[queryId];
  const key = `${queryId}:${paramName}`;
  return paramTypes.get(key) ?? 'string';
}

export default function SlicerPanel({ uid, dslFormB = false }: SlicerPanelProps) {
  const dsl = useDashboardStore((s) => s.dsl);
  const slicerValues = useDashboardStore((s) => s.slicerValues);
  const { changeSlicer } = useSlicerReducer();

  // Build a query_id:param_name → type lookup from the DSL queries.
  const paramTypes = useMemo(() => {
    const map = new Map<string, QueryParamType>();
    if (!dsl) return map;
    for (const [qid, q] of Object.entries(dsl.queries)) {
      for (const p of q.params ?? []) {
        map.set(`${qid}:${p.name}`, p.type);
      }
    }
    return map;
  }, [dsl]);

  const slicers = dsl?.slicers ?? [];
  if (slicers.length === 0) return null;

  return (
    <div style={styles.wrap}>
      {slicers.map((slicer) => {
        const sid = slicer.slicer_id;
        const paramType = resolveSlicerParamType(slicer, paramTypes);
        const currentValue = slicerValues[sid];
        const label = slicer.label ?? slicer.field ?? sid;

        // string with options_query_id → Select (options via useSlicerOptions).
        if (paramType === 'string' && slicer.options_query_id) {
          return (
            <SlicerControl key={sid} label={label}>
              <StringSelectSlicer
                slicer={slicer}
                uid={uid}
                dsl={dslFormB ? dsl : null}
                value={currentValue as string | undefined}
                onChange={(v) => changeSlicer(sid, v)}
              />
            </SlicerControl>
          );
        }

        // string without options → free Input.
        if (paramType === 'string') {
          return (
            <SlicerControl key={sid} label={label}>
              <Input
                value={currentValue == null ? undefined : String(currentValue)}
                onChange={(e) => changeSlicer(sid, e.target.value)}
                placeholder={`输入${label}`}
                allowClear
              />
            </SlicerControl>
          );
        }

        // number → InputNumber.
        if (paramType === 'number') {
          return (
            <SlicerControl key={sid} label={label}>
              <InputNumber
                value={typeof currentValue === 'number' ? currentValue : undefined}
                onChange={(v) => changeSlicer(sid, v ?? null)}
                placeholder={`输入${label}`}
                style={{ width: '100%' }}
              />
            </SlicerControl>
          );
        }

        // date / datetime → DatePicker.
        const isDateTime = paramType === 'datetime';
        return (
          <SlicerControl key={sid} label={label}>
            <DatePicker
              {...(isDateTime ? { showTime: true } : {})}
              onChange={(_d, dateStr) => changeSlicer(sid, typeof dateStr === 'string' ? dateStr : null)}
              style={{ width: '100%' }}
              placeholder={`选择${label}`}
            />
          </SlicerControl>
        );
      })}
    </div>
  );
}

function SlicerControl({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div style={styles.control}>
      <Typography.Text type="secondary" style={{ fontSize: 12, marginBottom: 2, display: 'block' }}>
        {label}
      </Typography.Text>
      {children}
    </div>
  );
}

function StringSelectSlicer({
  slicer,
  uid,
  dsl,
  value,
  onChange,
}: {
  slicer: SlicerSpec;
  uid?: string;
  dsl: ReturnType<typeof useDashboardStore.getState>['dsl'];
  value: string | undefined;
  onChange: (v: string) => void;
}) {
  const { data: options } = useSlicerOptions(slicer.slicer_id, {
    optionsQueryId: slicer.options_query_id,
    uid,
    dsl,
  });
  return (
    <Select
      value={value}
      onChange={(v) => onChange(v as string)}
      options={(options ?? []).map((o) => ({ value: o.value, label: o.label }))}
      allowClear
      showSearch
      placeholder="请选择"
      style={{ width: '100%' }}
    />
  );
}

const styles: Record<string, React.CSSProperties> = {
  wrap: {
    display: 'flex',
    flexWrap: 'wrap',
    gap: 12,
    padding: '8px 12px',
    borderBottom: '1px solid #f0f0f0',
    background: '#fafafa',
  },
  control: {
    minWidth: 180,
    flex: '1 1 180px',
    maxWidth: 280,
  },
};
