import { useEffect, useId, useRef } from 'react';
import Button from '@/components/ui/Button/Button.jsx';
import './ConfirmDialog.css';

/** @typedef {import('@/types/ui').ConfirmDialogProps} ConfirmDialogProps */

/** @param {ConfirmDialogProps} props */
function ConfirmDialog({
  open = false,
  title = 'Confirmar ação',
  description = '',
  confirmLabel = 'Confirmar',
  cancelLabel = 'Cancelar',
  confirmTone = 'danger',
  busy = false,
  onConfirm = null,
  onClose = null,
}) {
  const dialogRef = useRef(null);
  const previousFocusRef = useRef(null);
  const titleId = useId();
  const descriptionId = useId();

  useEffect(() => {
    if (!open) return undefined;
    previousFocusRef.current = document.activeElement;

    const panel = dialogRef.current;
    const firstFocusable = panel?.querySelector(
      'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    if (firstFocusable instanceof HTMLElement) {
      firstFocusable.focus();
    }

    const onKeyDown = (event) => {
      if (event.key === 'Escape' && !busy) {
        onClose?.();
      }

      if (event.key !== 'Tab' || !panel) return;
      const focusable = Array.from(
        panel.querySelectorAll(
          'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
      );
      if (focusable.length === 0) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    window.addEventListener('keydown', onKeyDown);
    return () => {
      window.removeEventListener('keydown', onKeyDown);
      if (previousFocusRef.current instanceof HTMLElement) {
        previousFocusRef.current.focus();
      }
    };
  }, [open, busy, onClose]);

  if (!open) return null;

  return (
    <div
      className="confirm-dialog__overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
      aria-describedby={description ? descriptionId : undefined}
      onClick={() => {
        if (!busy) onClose?.();
      }}
    >
      <div className="confirm-dialog__panel" onClick={(event) => event.stopPropagation()} ref={dialogRef}>
        <h2 id={titleId} className="confirm-dialog__title">{title}</h2>
        {description ? <p id={descriptionId} className="confirm-dialog__description">{description}</p> : null}

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
