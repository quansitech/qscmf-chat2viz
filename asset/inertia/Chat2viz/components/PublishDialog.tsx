import { useState, useCallback, useEffect } from 'react';
import { Button, Input, Modal, message } from 'antd';
import { CloudUploadOutlined } from '@ant-design/icons';
import { navigate } from '../adapters';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface PublishDialogProps {
  uid: string;
  title: string;
  visible: boolean;
  onClose: () => void;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function PublishDialog({ uid, title, visible, onClose }: PublishDialogProps) {
  const [titleValue, setTitleValue] = useState(title);
  const [publishing, setPublishing] = useState(false);

  // Sync local title when prop changes (e.g. store updates)
  useEffect(() => {
    setTitleValue(title);
  }, [title]);

  const handlePublish = useCallback(async () => {
    if (!uid) {
      message.error('仪表盘尚未保存');
      return;
    }
    setPublishing(true);

    try {
      const resp = await fetch(`/extends/Chat2VizDashboard/api_publish/uid/${uid}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: titleValue }),
      });
      if (!resp.ok) {
        throw new Error(`HTTP ${resp.status}: ${resp.statusText}`);
      }
      const result = await resp.json();

      if (result.status === 1) {
        message.success('仪表盘发布成功');
        navigate(`/extends/Chat2VizDashboard/view/uid/${uid}`);
      } else {
        message.error(result.info || '发布失败');
      }
    } catch {
      message.error('网络错误，请重试');
    } finally {
      setPublishing(false);
    }
  }, [uid, titleValue]);

  return (
    <Modal
      title="发布仪表盘"
      open={visible}
      onCancel={onClose}
      destroyOnClose
      footer={[
        <Button key="cancel" onClick={onClose}>
          取消
        </Button>,
        <Button
          key="publish"
          type="primary"
          icon={<CloudUploadOutlined />}
          loading={publishing}
          onClick={handlePublish}
        >
          发布
        </Button>,
      ]}
    >
      <div style={styles.body}>
        <label style={styles.label}>仪表盘标题</label>
        <Input
          value={titleValue}
          onChange={(e) => setTitleValue(e.target.value)}
          placeholder="输入仪表盘标题"
          maxLength={255}
        />
        <p style={styles.hint}>
          发布后可通过分享链接公开查看仪表盘。
        </p>
      </div>
    </Modal>
  );
}

// ---------------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------------

const styles: Record<string, React.CSSProperties> = {
  body: {
    display: 'flex',
    flexDirection: 'column',
    gap: 12,
  },
  label: {
    fontWeight: 600,
    fontSize: 14,
  },
  hint: {
    color: '#8c8c8c',
    fontSize: 13,
    margin: 0,
  },
};
