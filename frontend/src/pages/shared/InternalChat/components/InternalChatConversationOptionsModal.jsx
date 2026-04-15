import { buildConversationTitle } from '@/services/internalChatService';

function InternalChatConversationOptionsModal({
  conversationOptionsBusy,
  conversationOptionsError,
  currentUserId,
  filteredAddableGroupRecipients,
  groupNameDraft,
  handleAddParticipantToGroup,
  handleDeleteDirectConversation,
  handleDeleteGroup,
  handleLeaveGroup,
  handleOpenParticipantsModal,
  handleRemoveParticipantFromGroup,
  handleSaveGroupName,
  handleToggleParticipantAdmin,
  leaveTransferAdminTo,
  optionsConversation,
  optionsCurrentUserIsAdmin,
  optionsIsGroup,
  optionsParticipants,
  participantsModalBusy,
  participantsModalError,
  participantsModalOpen,
  participantsModalSearch,
  setGroupNameDraft,
  setLeaveTransferAdminTo,
  setParticipantsModalOpen,
  setParticipantsModalSearch,
  transferAdminCandidates,
  closeConversationOptionsModal,
}) {
  if (!optionsConversation) {
    return null;
  }

  return (
    <>
      <div className="internal-chat-modal-overlay" role="dialog" aria-modal="true">
        <div className="internal-chat-modal internal-chat-conversation-options-modal">
          <header className="internal-chat-modal-header">
            <h3>Opcoes da conversa</h3>
            <button
              type="button"
              className="app-btn-ghost"
              onClick={closeConversationOptionsModal}
            >
              Fechar
            </button>
          </header>

          <p className="internal-chat-modal-hint">
            {buildConversationTitle(optionsConversation, currentUserId)}
          </p>

          {optionsIsGroup ? (
            <div className="internal-chat-group-options">
              <label className="internal-chat-group-options-label">
                Nome do grupo
                <input
                  type="text"
                  className="app-input"
                  value={groupNameDraft}
                  onChange={(event) => setGroupNameDraft(event.target.value)}
                  disabled={!optionsCurrentUserIsAdmin || conversationOptionsBusy}
                />
              </label>
              {optionsCurrentUserIsAdmin ? (
                <button
                  type="button"
                  className="app-btn-secondary"
                  onClick={() => void handleSaveGroupName()}
                  disabled={conversationOptionsBusy || !groupNameDraft.trim()}
                >
                  {conversationOptionsBusy ? 'Salvando...' : 'Salvar nome'}
                </button>
              ) : (
                <p className="internal-chat-modal-hint">
                  Apenas admins podem alterar o nome do grupo.
                </p>
              )}

              {optionsCurrentUserIsAdmin ? (
                <button
                  type="button"
                  className="app-btn-secondary"
                  onClick={() => void handleOpenParticipantsModal()}
                  disabled={conversationOptionsBusy}
                >
                  Participantes
                </button>
              ) : null}

              {optionsCurrentUserIsAdmin && transferAdminCandidates.length > 0 ? (
                <label className="internal-chat-group-options-label">
                  Transferir admin antes de sair (somente quando necessario)
                  <select
                    className="app-input"
                    value={leaveTransferAdminTo}
                    onChange={(event) => setLeaveTransferAdminTo(event.target.value)}
                    disabled={conversationOptionsBusy}
                  >
                    <option value="">Não transferir agora</option>
                    {transferAdminCandidates.map((participant) => (
                      <option key={participant.id} value={participant.id}>
                        {participant.name}
                      </option>
                    ))}
                  </select>
                </label>
              ) : null}

              <button
                type="button"
                className="app-btn-secondary"
                onClick={() => void handleLeaveGroup()}
                disabled={conversationOptionsBusy}
              >
                {conversationOptionsBusy ? 'Saindo...' : 'Sair do grupo'}
              </button>

              {optionsCurrentUserIsAdmin ? (
                <button
                  type="button"
                  className="app-btn-danger"
                  onClick={() => void handleDeleteGroup()}
                  disabled={conversationOptionsBusy}
                >
                  {conversationOptionsBusy ? 'Apagando...' : 'Apagar grupo'}
                </button>
              ) : null}
            </div>
          ) : (
            <div className="internal-chat-group-options">
              <p className="internal-chat-modal-hint">
                Esta acao remove a conversa apenas para voce.
              </p>
              <button
                type="button"
                className="app-btn-danger"
                onClick={() => void handleDeleteDirectConversation()}
                disabled={conversationOptionsBusy}
              >
                {conversationOptionsBusy ? 'Apagando...' : 'Apagar conversa'}
              </button>
            </div>
          )}

          {conversationOptionsError ? (
            <p className="internal-chat-error-inline">{conversationOptionsError}</p>
          ) : null}
        </div>
      </div>

      {participantsModalOpen ? (
        <div className="internal-chat-modal-overlay" role="dialog" aria-modal="true">
          <div className="internal-chat-modal internal-chat-participants-modal">
            <header className="internal-chat-modal-header">
              <h3>Participantes do grupo</h3>
              <button
                type="button"
                className="app-btn-ghost"
                onClick={() => setParticipantsModalOpen(false)}
              >
                Fechar
              </button>
            </header>

            <input
              type="search"
              className="app-input"
              placeholder="Buscar participante para adicionar..."
              value={participantsModalSearch}
              onChange={(event) => setParticipantsModalSearch(event.target.value)}
            />

            <div className="internal-chat-participants-grid">
              <section className="internal-chat-participants-block">
                <h4>Participantes atuais</h4>
                <ul className="internal-chat-participants-list">
                  {optionsParticipants.map((participant) => {
                    const isSelf = Number(participant.id) === Number(currentUserId);
                    const isParticipantAdmin = Boolean(participant.is_admin);

                    return (
                      <li key={participant.id} className="internal-chat-participant-item">
                        <div>
                          <strong>{participant.name}</strong>
                          <p>{participant.email}</p>
                          <span className="internal-chat-participant-badges">
                            {isParticipantAdmin ? 'Admin' : 'Participante'}
                            {isSelf ? ' | você' : ''}
                          </span>
                        </div>
                        <div className="internal-chat-participant-actions">
                          <button
                            type="button"
                            className="app-btn-secondary text-xs"
                            onClick={() => void handleToggleParticipantAdmin(participant)}
                            disabled={participantsModalBusy || isSelf}
                          >
                            {isParticipantAdmin ? 'Tirar admin' : 'Tornar admin'}
                          </button>
                          {!isSelf ? (
                            <button
                              type="button"
                              className="app-btn-danger text-xs"
                              onClick={() => void handleRemoveParticipantFromGroup(participant.id)}
                              disabled={participantsModalBusy}
                            >
                              Remover
                            </button>
                          ) : null}
                        </div>
                      </li>
                    );
                  })}
                </ul>
              </section>

              <section className="internal-chat-participants-block">
                <h4>Adicionar participantes</h4>
                <ul className="internal-chat-participants-list">
                  {filteredAddableGroupRecipients.length === 0 ? (
                    <li className="internal-chat-list-state">
                      Nenhum utilizador disponível para adicionar.
                    </li>
                  ) : (
                    filteredAddableGroupRecipients.map((recipient) => (
                      <li key={recipient.id} className="internal-chat-participant-item">
                        <div>
                          <strong>{recipient.name}</strong>
                          <p>{recipient.email}</p>
                        </div>
                        <button
                          type="button"
                          className="app-btn-primary text-xs"
                          onClick={() => void handleAddParticipantToGroup(recipient.id)}
                          disabled={participantsModalBusy}
                        >
                          Adicionar
                        </button>
                      </li>
                    ))
                  )}
                </ul>
              </section>
            </div>

            {participantsModalError ? (
              <p className="internal-chat-error-inline">{participantsModalError}</p>
            ) : null}
          </div>
        </div>
      ) : null}
    </>
  );
}

export default InternalChatConversationOptionsModal;
