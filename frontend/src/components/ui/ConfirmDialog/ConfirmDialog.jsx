import { useEffect } from 'react';
import Button from '@/components/ui/Button/Button.jsx';
import './ConfirmDialog.css';

function ConfirmDialog({
  open = false,
  title = 'Confirmar acao',
  description = '',
  confirmLabel = 'Confirmar',
  cancelLabel = 'Cancelar',
  confirmTone = 'danger',
  busy = false,
  onConfirm = null,
  onClose = null,
}) {
  useEffect(() => {
    if (!open) return undefined;

    const onKeyDown = (event) => {
      if (event.key === 'Escape' && !busy) {
        onClose?.();
      }
    };

    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [open, busy, onClose]);

  if (!open) return null;

  return (
    <div
      className="confirm-dialog__overlay"
      role="dialog"
      aria-modal="true"
      onClick={() => {
        if (!busy) onClose?.();
      }}
    >
      <div className="confirm-dialog__panel" onClick={(event) => event.stopPropagation()}>
        <h2 className="confirm-dialog__title">{title}</h2>
        {description ? <p className="confirm-dialog__description">{description}</p> : null}

        <div className="confirm-dialog__actions">
          <Button type="button" variant="secondary" onClick={onClose} disabled={busy}>
            {cancelLabel}
          </Button>
          <Button
            type="button"
            variant={confirmTone === 'danger' ? 'danger' : 'primary'}
            onClick={onConfirm}
            disabled={busy}
          >
            {busy ? 'Processando...' : confirmLabel}
          </Button>
        </div>
      </div>
    </div>
  );
}

export default ConfirmDialog;
