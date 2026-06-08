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
      message.error('Dashboard not saved yet');
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
        message.success('Dashboard published successfully');
        navigate(`/extends/Chat2VizDashboard/view/uid/${uid}`);
      } else {
        message.error(result.info || 'Publish failed');
      }
    } catch {
      message.error('Network error, please try again');
    } finally {
      setPublishing(false);
    }
  }, [uid, titleValue]);

  return (
    <Modal
      title="Publish Dashboard"
      open={visible}
      onCancel={onClose}
      destroyOnClose
      footer={[
        <Button key="cancel" onClick={onClose}>
          Cancel
        </Button>,
        <Button
          key="publish"
          type="primary"
          icon={<CloudUploadOutlined />}
          loading={publishing}
          onClick={handlePublish}
        >
          Publish
        </Button>,
      ]}
    >
      <div style={styles.body}>
        <label style={styles.label}>Dashboard Title</label>
        <Input
          value={titleValue}
          onChange={(e) => setTitleValue(e.target.value)}
          placeholder="Enter dashboard title"
          maxLength={255}
        />
        <p style={styles.hint}>
          Published dashboards can be viewed publicly via a shared link.
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
