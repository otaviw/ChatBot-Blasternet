import {
  CONVERSATION_HANDLING_MODE,
  CONVERSATION_STATUS,
} from '@/constants/conversation';

function ConversationToolbar({
  detail,
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
  transferExpanded,
  onToggleTransferExpanded,
  transferArea,
  onTransferAreaChange,
  transferOptions,
  transferUserId,
  onTransferUserChange,
  availableUsers,
  onTransferConversation,
  transferBusy,
  transferSuccess,
  transferError,
}) {
  return (
    <div className="inbox-toolbar shrink-0 flex flex-wrap items-center gap-2">
      <span className="text-xs text-[#525252]">
        Modo: <strong>{detail.handling_mode === CONVERSATION_HANDLING_MODE.HUMAN ? 'Manual' : 'Bot'}</strong>
        {detail.assigned_user ? ` · ${detail.assigned_user.name}` : ''}
        {detail.current_area?.name ? ` · ${detail.current_area.name}` : ''}
      </span>
      <div className="flex items-center gap-1.5">
        <input
          type="text"
          value={contactNameInput}
          onChange={(event) => onContactNameChange(event.target.value)}
          placeholder="Nome"
          className="w-32 app-input text-xs py-1.5"
        />
        <button type="button" onClick={onSaveContactName} disabled={contactBusy} className="app-btn-secondary text-xs py-1.5">
          {contactBusy ? '...' : 'Salvar'}
        </button>
      </div>
      {contactSuccess && <span className="text-xs text-green-600">{contactSuccess}</span>}
      {contactError && <span className="text-xs text-red-600">{contactError}</span>}
      <div className="flex gap-1">
        <button type="button" onClick={onAssumeConversation} disabled={actionBusy} className="app-btn-secondary text-xs py-1.5">
          Assumir
        </button>
        <button type="button" onClick={onReleaseConversation} disabled={actionBusy} className="app-btn-secondary text-xs py-1.5">
          Soltar
        </button>
        <button
          type="button"
          onClick={onCloseConversation}
          disabled={actionBusy || detail?.status === CONVERSATION_STATUS.CLOSED}
          className="app-btn-danger text-xs py-1.5"
        >
          Encerrar
        </button>
      </div>
      <button type="button" onClick={onOpenTagsModal} className="app-btn-secondary text-xs py-1.5">
        Tags {(detail.tags ?? []).length > 0 && `(${(detail.tags ?? []).length})`}
      </button>
      <div className="inbox-transfer-wrap relative">
        <button type="button" onClick={onToggleTransferExpanded} className="app-btn-secondary text-xs py-1.5">
          Transferir {transferExpanded ? '▲' : '▼'}
        </button>
        {transferExpanded && (
          <div className="inbox-transfer-dropdown">
            <div className="space-y-2 mb-2">
              <label className="block text-xs">
                Área
                <select value={transferArea} onChange={(event) => onTransferAreaChange(event.target.value)} className="app-input text-xs mt-0.5">
                  <option value="">Selecionar</option>
                  {(transferOptions.areas ?? []).map((area) => (
                    <option key={area.id} value={area.id}>
                      {area.name}
                    </option>
                  ))}
                </select>
              </label>
              <label className="block text-xs">
                Usuário (opcional)
                <select value={transferUserId} onChange={(event) => onTransferUserChange(event.target.value)} className="app-input text-xs mt-0.5">
                  <option value="">Selecionar</option>
                  {availableUsers.map((user) => (
                    <option key={user.id} value={user.id}>
                      {user.name} {(user.areas ?? []).length ? `(${user.areas.map((area) => area.name).join(', ')})` : ''}
                    </option>
                  ))}
                </select>
              </label>
            </div>
            <button type="button" onClick={onTransferConversation} disabled={transferBusy} className="app-btn-primary text-xs w-full">
              {transferBusy ? 'Transferindo...' : 'Transferir'}
            </button>
            {transferSuccess && <p className="text-xs text-green-600 mt-1">{transferSuccess}</p>}
            {transferError && <p className="text-xs text-red-600 mt-1">{transferError}</p>}
            {(detail.transfer_history ?? []).length > 0 && (
              <div className="mt-2 pt-2 border-t border-[#eee]">
                <p className="text-xs text-[#737373] mb-1">Histórico</p>
                <ul className="space-y-1 text-xs max-h-24 overflow-y-auto">
                  {detail.transfer_history.map((item) => {
                    const fromLabel = item.from_user?.name || item.from_area || '—';
                    const toLabel = item.to_user?.name || item.to_area || '—';
                    return (
                      <li key={item.id} className="text-[#525252]">
                        {fromLabel} → {toLabel}
                      </li>
                    );
                  })}
                </ul>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

export default ConversationToolbar;
