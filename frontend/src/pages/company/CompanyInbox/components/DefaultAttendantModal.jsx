function DefaultAttendantModal({
  open,
  detail,
  attendants = [],
  valueUserId = '',
  valueSkipBot = false,
  onUserIdChange,
  onSkipBotChange,
  onSave,
  busy = false,
  error = '',
  success = '',
  onClose,
}) {
  if (!open || !detail) {
    return null;
  }

  return (
    <div className="inbox-tags-modal-overlay" onClick={onClose} role="presentation">
      <div
        className="inbox-tags-modal w-full"
        role="dialog"
        aria-modal="true"
        aria-label="Configurar atendente padrão"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-semibold">Atendente padrão</h3>
          <button
            type="button"
            onClick={onClose}
            className="text-[var(--ui-text-muted)] hover:text-[var(--ui-text)] text-lg leading-none"
            aria-label="Fechar modal"
          >
            ×
          </button>
        </div>

        <div className="space-y-2">
          <label className="block text-xs">
            Atendente
            <select
              value={valueUserId}
              onChange={(event) => {
                const nextValue = event.target.value;
                onUserIdChange(nextValue);
                if (!nextValue) {
                  onSkipBotChange(false);
                }
              }}
              className="app-input text-xs mt-0.5 w-full py-1.5"
              disabled={busy}
            >
              <option value="">Sem atendente padrão</option>
              {attendants.map((attendant) => (
                <option key={attendant.id} value={String(attendant.id)}>
                  {attendant.name}
                </option>
              ))}
            </select>
          </label>

          <label className="flex items-center gap-2 text-xs">
            <input
              type="checkbox"
              checked={Boolean(valueSkipBot)}
              onChange={(event) => onSkipBotChange(event.target.checked)}
              disabled={busy || !valueUserId}
            />
            <span>Pular bot e direcionar direto ao atendente padrão</span>
          </label>

          <p className="text-[11px] text-[var(--ui-text-muted)]">
            Essa configuração vale para novas entradas do cliente nesta conversa ({detail.customer_phone}).
          </p>
        </div>

        <button
          type="button"
          onClick={onSave}
          disabled={busy}
          className="app-btn-primary text-xs w-full mt-3 py-2"
        >
          {busy ? 'Salvando…' : 'Salvar configuração'}
        </button>

        {success ? <p className="text-xs text-green-600 mt-1.5">{success}</p> : null}
        {error ? <p className="text-xs text-red-600 mt-1.5">{error}</p> : null}
      </div>
    </div>
  );
}

export default DefaultAttendantModal;
