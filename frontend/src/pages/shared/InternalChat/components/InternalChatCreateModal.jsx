function InternalChatCreateModal({
  createBusy,
  createError,
  createModalOpen,
  createType,
  filteredRecipients,
  onClose,
  onCreateDirectConversation,
  onCreateGroupConversation,
  onRecipientSearchChange,
  onSelectRecipient,
  onToggleGroupParticipant,
  recipientSearch,
  recipientsError,
  recipientsLoading,
  selectedGroupIds,
  selectedRecipientId,
}) {
  if (!createModalOpen) {
    return null;
  }

  return (
    <div className="internal-chat-modal-overlay" role="dialog" aria-modal="true">
      <div className="internal-chat-modal">
        <header className="internal-chat-modal-header">
          <h3>{createType === 'group' ? 'Novo grupo' : 'Nova conversa interna'}</h3>
          <button
            type="button"
            className="app-btn-ghost"
            onClick={onClose}
          >
            Fechar
          </button>
        </header>

        {createType === 'group' ? (
          <p className="internal-chat-modal-hint">
            Selecione pelo menos 2 participantes para criar o grupo.
            {selectedGroupIds.length > 0 ? ` (${selectedGroupIds.length} selecionado${selectedGroupIds.length > 1 ? 's' : ''})` : ''}
          </p>
        ) : null}

        <input
          type="search"
          className="app-input"
          placeholder="Buscar usuario por nome ou email..."
          value={recipientSearch}
          onChange={(event) => onRecipientSearchChange(event.target.value)}
        />

        <div className="internal-chat-recipient-list">
          {recipientsLoading ? (
            <p className="internal-chat-list-state">Carregando usuarios...</p>
          ) : null}

          {!recipientsLoading && !filteredRecipients.length ? (
            <p className="internal-chat-list-state">
              Nenhum usuario disponivel para iniciar conversa.
            </p>
          ) : null}

          {filteredRecipients.map((recipient) => {
            const isSelectedDirect = createType === 'direct' && Number(selectedRecipientId) === Number(recipient.id);
            const isSelectedGroup = createType === 'group' && selectedGroupIds.includes(Number(recipient.id));
            const isActive = isSelectedDirect || isSelectedGroup;

            return (
              <button
                key={recipient.id}
                type="button"
                className={`internal-chat-recipient-item ${isActive ? 'internal-chat-recipient-item--active' : ''}`}
                onClick={() => {
                  if (createType === 'group') {
                    onToggleGroupParticipant(recipient.id);
                  } else {
                    onSelectRecipient(recipient.id);
                  }
                }}
              >
                <span className="internal-chat-recipient-name">
                  {isSelectedGroup ? '\u2713 ' : ''}{recipient.name}
                </span>
                <span className="internal-chat-recipient-email">{recipient.email}</span>
              </button>
            );
          })}
        </div>

        {recipientsError ? <p className="internal-chat-error-inline">{recipientsError}</p> : null}
        {createError ? <p className="internal-chat-error-inline">{createError}</p> : null}

        <footer className="internal-chat-modal-actions">
          <button
            type="button"
            className="app-btn-secondary"
            onClick={onClose}
            disabled={createBusy}
          >
            Cancelar
          </button>
          <button
            type="button"
            className="app-btn-primary"
            onClick={() => {
              if (createType === 'group') {
                void onCreateGroupConversation();
              } else {
                void onCreateDirectConversation();
              }
            }}
            disabled={createBusy}
          >
            {createBusy ? 'Criando...' : createType === 'group' ? 'Criar grupo' : 'Criar conversa'}
          </button>
        </footer>
      </div>
    </div>
  );
}

export default InternalChatCreateModal;
