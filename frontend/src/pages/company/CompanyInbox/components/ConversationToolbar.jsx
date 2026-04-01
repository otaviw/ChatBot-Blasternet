import { useEffect, useRef, useState } from 'react';
import {
  CONVERSATION_HANDLING_MODE,
  CONVERSATION_STATUS,
} from '@/constants/conversation';
import ServiceAreaBadge from '@/components/company/ServiceAreaBadge/ServiceAreaBadge.jsx';

function ConversationToolbar({
  detail,
  serviceAreaNames = [],
  contactNameInput,
  onContactNameChange,
  onSaveContactName,
  contactBusy,
  contactSuccess,
  contactError,
  actionBusy,
  onAssumeConversation,
  onReleaseConversation,
  onCloseConversation,
  onOpenTagsModal,
  onOpenTransferModal,
}) {
  const [actionsMenuOpen, setActionsMenuOpen] = useState(false);
  const actionsMenuRef = useRef(null);

  useEffect(() => {
    if (!actionsMenuOpen) return undefined;

    const handlePointerDown = (event) => {
      if (actionsMenuRef.current && !actionsMenuRef.current.contains(event.target)) {
        setActionsMenuOpen(false);
      }
    };

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') {
        setActionsMenuOpen(false);
      }
    };

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [actionsMenuOpen]);

  const closeMenu = () => setActionsMenuOpen(false);

  return (
    <div className="inbox-toolbar shrink-0 flex flex-wrap items-center gap-x-2 gap-y-1.5">
      <span className="text-xs text-[#525252] inline-flex flex-wrap items-center gap-x-1.5 gap-y-1 min-w-0 flex-1">
        <span className="min-w-0">
          Modo: <strong>{detail.handling_mode === CONVERSATION_HANDLING_MODE.HUMAN ? 'Manual' : 'Bot'}</strong>
          {detail.assigned_user ? ` · ${detail.assigned_user.name}` : ''}
        </span>
        {detail.current_area?.name ? (
          <>
            <span className="text-[#a3a3a3] shrink-0" aria-hidden>
              ·
            </span>
            <ServiceAreaBadge areaName={detail.current_area.name} serviceAreaNames={serviceAreaNames} />
          </>
        ) : null}
      </span>

      <div className="flex items-center gap-1.5 shrink-0">
        <input
          type="text"
          value={contactNameInput}
          onChange={(event) => onContactNameChange(event.target.value)}
          placeholder="Nome"
          className="w-28 sm:w-32 app-input text-xs py-1.5"
        />
        <button type="button" onClick={onSaveContactName} disabled={contactBusy} className="app-btn-secondary text-xs py-1.5">
          {contactBusy ? '...' : 'Salvar'}
        </button>
      </div>

      {contactSuccess && <span className="text-xs text-green-600 shrink-0">{contactSuccess}</span>}
      {contactError && <span className="text-xs text-red-600 shrink-0">{contactError}</span>}

      <div className="relative shrink-0" ref={actionsMenuRef}>
        <button
          type="button"
          onClick={() => setActionsMenuOpen((open) => !open)}
          className="app-btn-secondary text-xs py-1.5 inline-flex items-center gap-1"
          aria-expanded={actionsMenuOpen}
          aria-haspopup="menu"
        >
          Ações
          <span className="text-[10px]" aria-hidden>
            {actionsMenuOpen ? '▴' : '▾'}
          </span>
        </button>

        {actionsMenuOpen ? (
          <div className="inbox-toolbar-actions-menu" role="menu">
            <button
              type="button"
              role="menuitem"
              disabled={actionBusy}
              onClick={() => {
                onAssumeConversation();
                closeMenu();
              }}
            >
              Assumir conversa
            </button>
            <button
              type="button"
              role="menuitem"
              disabled={actionBusy}
              onClick={() => {
                onReleaseConversation();
                closeMenu();
              }}
            >
              Soltar conversa
            </button>
            <div className="inbox-toolbar-actions-sep" role="separator" />
            <button
              type="button"
              role="menuitem"
              disabled={actionBusy || detail?.status === CONVERSATION_STATUS.CLOSED}
              className="!text-red-700 hover:!bg-red-50"
              onClick={() => {
                onCloseConversation();
                closeMenu();
              }}
            >
              Encerrar conversa
            </button>
            <div className="inbox-toolbar-actions-sep" role="separator" />
            <button
              type="button"
              role="menuitem"
              onClick={() => {
                onOpenTagsModal();
                closeMenu();
              }}
            >
              Tags
              {(detail.tags ?? []).length > 0 ? ` (${(detail.tags ?? []).length})` : ''}
            </button>
            <button
              type="button"
              role="menuitem"
              onClick={() => {
                onOpenTransferModal();
                closeMenu();
              }}
            >
              Transferir…
            </button>
          </div>
        ) : null}
      </div>
    </div>
  );
}

export default ConversationToolbar;
