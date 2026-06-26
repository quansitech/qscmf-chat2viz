import { useState, useCallback, useEffect } from 'react';
import { Button, Input, Modal, Typography, message } from 'antd';
import { CloudUploadOutlined } from '@ant-design/icons';
import { navigate } from '../adapters';
import { ADMIN_BASE, PUBLIC_BASE } from '../utils/routes';

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
  const [shareMode, setShareMode] = useState(false);
  const [shareUrl, setShareUrl] = useState('');

  // Sync local title when prop changes (e.g. store updates)
  useEffect(() => {
    setTitleValue(title);
  }, [title]);

  const handlePublish = useCallback(async () => {
    if (!uid) {
      message.error('仪表盘尚未保存');
      return;
    }
    // Path-traversal guard: validate uid before concatenating into URL.
    // Spec fix-frontend-review-defects/frontend-hardening requires
    // ^[a-zA-Z0-9_-]+$ for every URL-bound uid. encodeURIComponent is a
    // belt-and-braces defense — the regex alone rejects the dangerous
    // characters, but encoding keeps the URL canonical even if a future
    // refactor relaxes the pattern.
    if (!/^[a-zA-Z0-9_-]+$/.test(uid)) {
      message.error('无效的仪表盘标识符');
      return;
    }
    setPublishing(true);

    try {
      const resp = await fetch(`${ADMIN_BASE}/api_publish/uid/${encodeURIComponent(uid)}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: titleValue }),
      });
      if (!resp.ok) {
        // Read server error body for actionable message (task 8.9: previously
        // swallowed, showing generic "网络错误" even when server explained why).
        let serverMsg = `HTTP ${resp.status}`;
        try {
          const errBody = await resp.json();
          serverMsg = errBody.info || errBody.message || serverMsg;
        } catch { /* non-JSON error body; keep HTTP status */ }
        throw new Error(serverMsg);
      }
      const result = await resp.json();

      if (result.status === 1) {
        message.success('仪表盘发布成功');
        const publishedUid = result.data?.uid || uid;
        setShareUrl(`${window.location.origin}${PUBLIC_BASE}/view/uid/${publishedUid}`);
        setShareMode(true);
      } else {
        message.error(result.info || '发布失败');
      }
    } catch {
      message.error('网络错误，请重试');
    } finally {
      setPublishing(false);
    }
  }, [uid, titleValue]);

  const handleClose = useCallback(() => {
    setShareMode(false);
    setShareUrl('');
    onClose();
  }, [onClose]);

  const handleCopy = useCallback(async () => {
    try {
      await navigator.clipboard.writeText(shareUrl);
      message.success('链接已复制');
    } catch {
      message.error('复制失败，请手动复制');
    }
  }, [shareUrl]);

  const handleGoToView = useCallback(() => {
    window.location.href = shareUrl;
  }, [shareUrl]);

  return (
    <Modal
      title="发布仪表盘"
      open={visible}
      onCancel={handleClose}
      destroyOnClose
      footer={
        shareMode
          ? [
              <Button key="continue" onClick={handleClose}>
                继续编辑
              </Button>,
              <Button key="view" type="primary" onClick={handleGoToView}>
                前往查看
              </Button>,
            ]
          : [
              <Button key="cancel" onClick={handleClose}>
                取消
              </Button>,
              <Button
                key="publish"
                type="primary"
                icon={<CloudUploadOutlined />}
                loading={publishing}
                disabled={!uid}
                onClick={handlePublish}
              >
                发布
              </Button>,
            ]
      }
    >
      {shareMode ? (
        <div style={styles.body}>
          <Typography.Paragraph copyable={{ text: shareUrl }} style={{ margin: 0, wordBreak: 'break-all' }}>
            {shareUrl}
          </Typography.Paragraph>
          <Button type="link" onClick={handleCopy} style={{ padding: 0, marginTop: 8 }}>
            复制链接
          </Button>
        </div>
      ) : (
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
      )}
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
