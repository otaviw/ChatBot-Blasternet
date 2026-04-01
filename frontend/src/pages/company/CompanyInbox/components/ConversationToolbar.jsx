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
    <div className="inbox-toolbar shrink-0">
      {/* Linha principal — sempre visível */}
      <div className="flex items-center gap-x-2 gap-y-1 flex-wrap">
        <span className="text-xs text-[#525252] inline-flex flex-wrap items-center gap-x-1.5 gap-y-0.5 min-w-0 flex-1">
          <span className="min-w-0 truncate">
            <strong>{detail.handling_mode === CONVERSATION_HANDLING_MODE.HUMAN ? 'Manual' : 'Bot'}</strong>
            {detail.assigned_user ? ` · ${detail.assigned_user.name}` : ''}
          </span>
          {detail.current_area?.name ? (
            <>
              <span className="text-[#a3a3a3] shrink-0" aria-hidden>·</span>
              <ServiceAreaBadge areaName={detail.current_area.name} serviceAreaNames={serviceAreaNames} />
            </>
          ) : null}
        </span>

        {/* Nome do contato — oculto em telas muito pequenas */}
        <div className="hidden sm:flex items-center gap-1.5 shrink-0">
          <input
            type="text"
            value={contactNameInput}
            onChange={(event) => onContactNameChange(event.target.value)}
            placeholder="Nome"
            className="w-24 sm:w-28 app-input text-xs py-1"
          />
          <button type="button" onClick={onSaveContactName} disabled={contactBusy} className="app-btn-secondary text-xs py-1">
            {contactBusy ? '...' : 'Salvar'}
          </button>
        </div>

        <div className="relative shrink-0" ref={actionsMenuRef}>
          <button
            type="button"
            onClick={() => setActionsMenuOpen((open) => !open)}
            className="app-btn-secondary text-xs py-1 inline-flex items-center gap-1"
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
              {/* Nome do contato dentro do menu no mobile */}
              <div className="sm:hidden px-2 py-1.5 border-b border-[#e5e5e5]">
                <p className="text-[10px] uppercase font-semibold text-[#a3a3a3] mb-1">Renomear contato</p>
                <div className="flex gap-1">
                  <input
                    type="text"
                    value={contactNameInput}
                    onChange={(event) => onContactNameChange(event.target.value)}
                    placeholder="Nome"
                    className="flex-1 app-input text-xs py-1"
                  />
                  <button
                    type="button"
                    onClick={() => { onSaveContactName(); closeMenu(); }}
                    disabled={contactBusy}
                    className="app-btn-secondary text-xs py-1 shrink-0"
                  >
                    {contactBusy ? '...' : 'OK'}
                  </button>
                </div>
                {contactSuccess && <p className="text-[10px] text-green-600 mt-0.5">{contactSuccess}</p>}
                {contactError && <p className="text-[10px] text-red-600 mt-0.5">{contactError}</p>}
              </div>
              <button
                type="button"
                role="menuitem"
                disabled={actionBusy}
                onClick={() => { onAssumeConversation(); closeMenu(); }}
              >
                Assumir conversa
              </button>
              <button
                type="button"
                role="menuitem"
                disabled={actionBusy}
                onClick={() => { onReleaseConversation(); closeMenu(); }}
              >
                Soltar conversa
              </button>
              <div className="inbox-toolbar-actions-sep" role="separator" />
              <button
                type="button"
                role="menuitem"
                disabled={actionBusy || detail?.status === CONVERSATION_STATUS.CLOSED}
                className="!text-red-700 hover:!bg-red-50"
                onClick={() => { onCloseConversation(); closeMenu(); }}
              >
                Encerrar conversa
              </button>
              <div className="inbox-toolbar-actions-sep" role="separator" />
              <button
                type="button"
                role="menuitem"
                onClick={() => { onOpenTagsModal(); closeMenu(); }}
              >
                Tags{(detail.tags ?? []).length > 0 ? ` (${(detail.tags ?? []).length})` : ''}
              </button>
              <button
                type="button"
                role="menuitem"
                onClick={() => { onOpenTransferModal(); closeMenu(); }}
              >
                Transferir…
              </button>
            </div>
          ) : null}
        </div>
      </div>

      {/* Feedback de contato — somente no desktop (no mobile fica dentro do menu) */}
      {(contactSuccess || contactError) && (
        <div className="hidden sm:flex gap-2 mt-0.5">
          {contactSuccess && <span className="text-xs text-green-600">{contactSuccess}</span>}
          {contactError && <span className="text-xs text-red-600">{contactError}</span>}
        </div>
      )}
    </div>
  );
}

export default ConversationToolbar;
