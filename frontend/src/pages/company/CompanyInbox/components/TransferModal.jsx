function TransferModal({
  open,
  detail,
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
  onClose,
}) {
  if (!open || !detail) {
    return null;
  }

  return (
    <div className="inbox-tags-modal-overlay" onClick={onClose}>
      <div className="inbox-tags-modal w-full" onClick={(event) => event.stopPropagation()}>
        <div className="flex items-center justify-between mb-2">
          <h3 className="text-sm font-semibold">Transferir conversa</h3>
          <button type="button" onClick={onClose} className="text-[var(--ui-text-muted)] hover:text-[var(--ui-text)]" aria-label="Fechar">
            ✕
          </button>
        </div>

        <div className="space-y-2">
          <label className="block text-xs">
            Área
            <select
              value={transferArea}
              onChange={(event) => onTransferAreaChange(event.target.value)}
              className="app-input text-xs mt-0.5 w-full py-1.5"
            >
              <option value="">Selecionar</option>
              {(transferOptions.areas ?? []).map((area) => (
                <option key={area.id} value={area.id}>
                  {area.name}
                </option>
              ))}
            </select>
          </label>

          <label className="block text-xs">
            Utilizador (opcional)
            <select
              value={transferUserId}
              onChange={(event) => onTransferUserChange(event.target.value)}
              className="app-input text-xs mt-0.5 w-full py-1.5"
            >
              <option value="">Selecionar</option>
              {availableUsers.map((user) => (
                <option key={user.id} value={user.id}>
                  {user.name}{' '}
                  {(user.areas ?? []).length ? `(${user.areas.map((area) => area.name).join(', ')})` : ''}
                </option>
              ))}
            </select>
          </label>
        </div>

        <button
          type="button"
          onClick={onTransferConversation}
          disabled={transferBusy}
          className="app-btn-primary text-xs w-full mt-3 py-2"
        >
          {transferBusy ? 'Transferindo…' : 'Transferir'}
        </button>

        {transferSuccess ? <p className="text-xs text-green-600 mt-1.5">{transferSuccess}</p> : null}
        {transferError ? <p className="text-xs text-red-600 mt-1.5">{transferError}</p> : null}

        {(detail.transfer_history ?? []).length > 0 && (
          <div className="mt-3 pt-2 border-t border-[var(--ui-border)]">
            <p className="text-[11px] text-[var(--ui-text-muted)] mb-1">Histórico</p>
            <ul className="space-y-0.5 text-[11px] max-h-24 overflow-y-auto">
              {detail.transfer_history.map((item) => {
                const fromLabel = item.from_user?.name || item.from_area || '—';
                const toLabel = item.to_user?.name || item.to_area || '—';
                return (
                  <li key={item.id} className="text-[var(--ui-text-muted)]">
                    {fromLabel} → {toLabel}
                  </li>
                );
              })}
            </ul>
          </div>
        )}
      </div>
    </div>
  );
}

export default TransferModal;
