import InboxBackButton from '@/components/ui/InboxBackButton/InboxBackButton.jsx';
import InternalChatComposer from './InternalChatComposer.jsx';

function InternalChatMessagesPanel({
  chatListRef,
  composerProps,
  currentParticipants,
  currentTitle,
  currentUserId,
  detailError,
  detailLoading,
  editingMessageId,
  editingMessageText,
  formatDateTime,
  messageActionBusyId,
  messageActionError,
  onBack,
  onCancelMessageEditing,
  onChatScroll,
  onMessageDelete,
  onMessageEditSave,
  onRefresh,
  onStartMessageEditing,
  onToggleMessageOptions,
  onUpdateEditingMessageText,
  openMessageOptionsId,
  selectedConversation,
  selectedConversationId,
  sidebarVisibleOnMobile,
}) {
  return (
    <section
      className={`internal-chat-main ${
        selectedConversationId && !sidebarVisibleOnMobile ? 'internal-chat-main--visible' : ''
      }`}
    >
      {selectedConversationId ? (
        <InboxBackButton
          onClick={onBack}
          className="internal-chat-back-btn lg:hidden"
          label="Voltar para conversas"
        />
      ) : null}

      {!selectedConversationId ? (
        <div className="internal-chat-empty-state">
          Selecione uma conversa para visualizar as mensagens.
        </div>
      ) : null}

      {selectedConversationId && detailLoading ? (
        <div className="internal-chat-empty-state">Carregando conversa...</div>
      ) : null}

      {selectedConversationId && detailError ? (
        <div className="internal-chat-error-box">{detailError}</div>
      ) : null}

      {selectedConversationId && selectedConversation && !detailLoading ? (
        <div className="internal-chat-detail">
          <header className="internal-chat-toolbar">
            <div>
              <h2 className="internal-chat-toolbar-title">{currentTitle}</h2>
              <p className="internal-chat-toolbar-subtitle">
                {currentParticipants.map((participant) => participant.name).join(' | ') ||
                  'Sem participantes carregados'}
              </p>
            </div>
            <button type="button" className="app-btn-secondary" onClick={() => void onRefresh()}>
              Atualizar
            </button>
          </header>

          <ul ref={chatListRef} onScroll={onChatScroll} className="internal-chat-message-list">
            {!selectedConversation.messages?.length ? (
              <li className="internal-chat-list-state">Sem mensagens nesta conversa ainda.</li>
            ) : null}

            {(selectedConversation.messages ?? []).map((message) => {
              const isMine = currentUserId && Number(message.sender_id) === Number(currentUserId);
              const isDeleted = Boolean(message.deleted_at || message.is_deleted);
              const hasAttachments = !isDeleted && (message.attachments ?? []).length > 0;
              const isEditing =
                Number(editingMessageId ?? 0) > 0 &&
                Number(editingMessageId) === Number(message.id);
              const isMessageActionBusy =
                Number(messageActionBusyId ?? 0) > 0 &&
                Number(messageActionBusyId) === Number(message.id);

              return (
                <li
                  key={`${message.id ?? 'local'}-${message.created_at ?? ''}-${message.sender_id ?? ''}`}
                  className={`internal-chat-message ${
                    isMine ? 'internal-chat-message--mine' : 'internal-chat-message--other'
                  }`}
                >
                  <span className="internal-chat-message-label">
                    {message.sender_name}
                    {isMine ? ' (voce)' : ''}
                    {isDeleted ? ' (apagada)' : message.edited_at ? ' (editada)' : ''}
                  </span>

                  {isEditing ? (
                    <div className="internal-chat-message-edit">
                      <textarea
                        className="app-input internal-chat-message-edit-textarea"
                        rows={2}
                        value={editingMessageText}
                        onChange={(event) => onUpdateEditingMessageText(event.target.value)}
                      />
                      <div className="internal-chat-message-edit-actions">
                        <button
                          type="button"
                          className="app-btn-secondary"
                          disabled={isMessageActionBusy}
                          onClick={onCancelMessageEditing}
                        >
                          Cancelar
                        </button>
                        <button
                          type="button"
                          className="app-btn-primary"
                          disabled={isMessageActionBusy || !editingMessageText.trim()}
                          onClick={() => void onMessageEditSave(message.id)}
                        >
                          {isMessageActionBusy ? 'Salvando...' : 'Salvar'}
                        </button>
                      </div>
                    </div>
                  ) : message.content ? (
                    <p
                      className={`internal-chat-message-content ${
                        isDeleted ? 'internal-chat-message-content--deleted' : ''
                      }`}
                    >
                      {message.content}
                    </p>
                  ) : null}

                  {hasAttachments ? (
                    <div className="internal-chat-attachments">
                      {message.attachments.map((attachment) => (
                        <a
                          key={attachment.id ?? `${attachment.url}-${attachment.original_name}`}
                          href={attachment.url || '#'}
                          target={attachment.url ? '_blank' : undefined}
                          rel={attachment.url ? 'noreferrer' : undefined}
                          className="internal-chat-attachment-link"
                          onClick={(event) => {
                            if (!attachment.url) {
                              event.preventDefault();
                            }
                          }}
                        >
                          {attachment.original_name || attachment.url || 'Anexo'}
                        </a>
                      ))}
                    </div>
                  ) : null}

                  <div className="internal-chat-message-footer">
                    <span className="internal-chat-message-time">
                      {formatDateTime(message.created_at)}
                    </span>

                    {isMine && !isDeleted && !isEditing ? (
                      <div
                        className="internal-chat-message-options"
                        data-chat-message-options="true"
                      >
                        <button
                          type="button"
                          className="internal-chat-message-options-trigger"
                          disabled={isMessageActionBusy}
                          onClick={() => onToggleMessageOptions(message.id)}
                          aria-label="Opcoes da mensagem"
                          title="Opcoes"
                        >
                          <span aria-hidden="true" className="internal-chat-message-options-icon" />
                        </button>

                        {Number(openMessageOptionsId) === Number(message.id) ? (
                          <div className="internal-chat-message-options-popover">
                            <button
                              type="button"
                              className="internal-chat-message-options-item"
                              disabled={isMessageActionBusy}
                              onClick={() => onStartMessageEditing(message)}
                            >
                              Editar
                            </button>
                            <button
                              type="button"
                              className="internal-chat-message-options-item internal-chat-message-options-item--danger"
                              disabled={isMessageActionBusy}
                              onClick={() => void onMessageDelete(message.id)}
                            >
                              {isMessageActionBusy ? 'Apagando...' : 'Apagar'}
                            </button>
                          </div>
                        ) : null}
                      </div>
                    ) : null}
                  </div>
                </li>
              );
            })}
          </ul>

          {messageActionError ? (
            <p className="internal-chat-error-inline internal-chat-message-action-error">
              {messageActionError}
            </p>
          ) : null}

          <InternalChatComposer {...composerProps} />
        </div>
      ) : null}
    </section>
  );
}

export default InternalChatMessagesPanel;
