import { useCallback } from 'react';
import { Button, Empty, Table, Tag, message } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { PlusOutlined } from '@ant-design/icons';
import { getPageProps, navigate } from './adapters';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface DashboardItem {
  uid: string;
  title: string;
  status: string;
  created_at: string;
}

interface DashboardListProps {
  dashboards: DashboardItem[];
  total: number;
  page: number;
  perPage: number;
}

// ---------------------------------------------------------------------------
// Status tag color mapping
// ---------------------------------------------------------------------------

const STATUS_COLORS: Record<string, string> = {
  draft: 'default',
  published: 'success',
  archived: 'warning',
};

function statusTag(status: string) {
  return <Tag color={STATUS_COLORS[status] || 'default'}>{status}</Tag>;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function DashboardList() {
  const { dashboards, total, page, perPage } = getPageProps<DashboardListProps>();

  // ---- Columns ----
  const columns: ColumnsType<DashboardItem> = [
    {
      title: 'Title',
      dataIndex: 'title',
      key: 'title',
      ellipsis: true,
    },
    {
      title: 'Status',
      dataIndex: 'status',
      key: 'status',
      width: 120,
      render: (status: string) => statusTag(status),
    },
    {
      title: 'Created',
      dataIndex: 'created_at',
      key: 'created_at',
      width: 180,
    },
    {
      title: 'Actions',
      key: 'actions',
      width: 160,
      render: (_: unknown, record: DashboardItem) => (
        <div style={{ display: 'flex', gap: 8 }}>
          <Button
            size="small"
            onClick={() => navigate(`/extends/Chat2VizDashboard/view/uid/${record.uid}`)}
          >
            View
          </Button>
          <Button
            size="small"
            type="primary"
            onClick={() => navigate(`/extends/Chat2VizDashboard/edit/uid/${record.uid}`)}
          >
            Edit
          </Button>
        </div>
      ),
    },
  ];

  // ---- Create new dashboard ----
  const handleCreate = useCallback(async () => {
    try {
      const resp = await fetch('/extends/Chat2VizDashboard/api_create', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: 'Untitled Dashboard' }),
      });
      if (!resp.ok) {
        throw new Error(`HTTP ${resp.status}: ${resp.statusText}`);
      }
      const result = await resp.json();
      if (result.status === 1 && result.data?.uid) {
        navigate(`/extends/Chat2VizDashboard/edit/uid/${result.data.uid}`);
      }
    } catch {
      message.error('Failed to create dashboard. Please try again.');
    }
  }, []);

  return (
    <div style={styles.container}>
      <div style={styles.header}>
        <h2 style={styles.heading}>Dashboards</h2>
        <Button type="primary" icon={<PlusOutlined />} onClick={handleCreate}>
          New Dashboard
        </Button>
      </div>

      <Table<DashboardItem>
        columns={columns}
        dataSource={dashboards || []}
        rowKey="uid"
        locale={{
          emptyText: (
            <Empty description="No dashboards yet. Create one to get started." />
          ),
        }}
        pagination={{
          total: total || 0,
          current: page || 1,
          pageSize: perPage || 20,
          showSizeChanger: false,
          onChange: (p) => navigate(`/extends/Chat2VizDashboard/index?page=${p}`),
        }}
      />
    </div>
  );
}

// ---------------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------------

const styles: Record<string, React.CSSProperties> = {
  container: {
    padding: 24,
    maxWidth: 1200,
    margin: '0 auto',
  },
  header: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 16,
  },
  heading: {
    margin: 0,
  },
};
