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
      title: '标题',
      dataIndex: 'title',
      key: 'title',
      ellipsis: true,
    },
    {
      title: '状态',
      dataIndex: 'status',
      key: 'status',
      width: 120,
      render: (status: string) => statusTag(status),
    },
    {
      title: '创建时间',
      dataIndex: 'created_at',
      key: 'created_at',
      width: 180,
    },
    {
      title: '操作',
      key: 'actions',
      width: 160,
      render: (_: unknown, record: DashboardItem) => (
        <div style={{ display: 'flex', gap: 8 }}>
          <Button
            size="small"
            onClick={() => navigate(`/extends/Chat2VizDashboard/view/uid/${record.uid}`)}
          >
            查看
          </Button>
          <Button
            size="small"
            type="primary"
            onClick={() => navigate(`/extends/Chat2VizDashboard/edit/uid/${record.uid}`)}
          >
            编辑
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
        body: JSON.stringify({ title: '未命名仪表盘' }),
      });
      if (!resp.ok) {
        throw new Error(`HTTP ${resp.status}: ${resp.statusText}`);
      }
      const result = await resp.json();
      if (result.status === 1 && result.data?.uid) {
        navigate(`/extends/Chat2VizDashboard/edit/uid/${result.data.uid}`);
      }
    } catch {
      message.error('创建仪表盘失败，请重试');
    }
  }, []);

  return (
    <div style={styles.container}>
      <div style={styles.header}>
        <h2 style={styles.heading}>仪表盘</h2>
        <Button type="primary" icon={<PlusOutlined />} onClick={handleCreate}>
          新建仪表盘
        </Button>
      </div>

      <Table<DashboardItem>
        columns={columns}
        dataSource={dashboards || []}
        rowKey="uid"
        locale={{
          emptyText: (
            <Empty description="暂无仪表盘，点击上方按钮创建" />
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
