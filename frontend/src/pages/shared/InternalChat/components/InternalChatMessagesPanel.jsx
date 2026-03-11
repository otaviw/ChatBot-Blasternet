import { useState } from 'react';
import InboxBackButton from '@/components/ui/InboxBackButton/InboxBackButton.jsx';
import InternalChatComposer from './InternalChatComposer.jsx';

const QUICK_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

function MessageReactions({ reactions, currentUserId, onToggleReaction, messageId }) {
  const entries = Object.entries(reactions ?? {});
  if (!entries.length) {
    return null;
  }

  return (
    <div className="internal-chat-reactions">
      {entries.map(([emoji, userIds]) => {
        const count = Array.isArray(userIds) ? userIds.length : 0;
        if (count === 0) return null;
        const iReacted = Array.isArray(userIds) && userIds.includes(Number(currentUserId));

        return (
          <button
            key={emoji}
            type="button"
            className={`internal-chat-reaction-pill ${iReacted ? 'internal-chat-reaction-pill--mine' : ''}`}
            onClick={() => onToggleReaction(messageId, emoji)}
            title={`${emoji} (${count})`}
          >
            <span>{emoji}</span>
            <span className="internal-chat-reaction-count">{count}</span>
          </button>
        );
      })}
    </div>
  );
}

function ReactionPicker({ onPick }) {
  return (
    <div className="internal-chat-reaction-picker">
      {QUICK_EMOJIS.map((emoji) => (
        <button
          key={emoji}
          type="button"
          className="internal-chat-reaction-picker-btn"
          onClick={() => onPick(emoji)}
        >
          {emoji}
        </button>
      ))}
    </div>
  );
}

function ReadStatusLabel({ message, currentUserId }) {
  const isMine = currentUserId && Number(message.sender_id) === Number(currentUserId);
  if (!isMine) return null;

  const readByCount = Number(message.read_by_count ?? 0);
  const participantCount = Number(message.participant_count ?? 0);
  const othersCount = Math.max(0, participantCount - 1);

  if (othersCount <= 0) return null;

  let label;
  if (readByCount >= othersCount) {
    label = 'Lida';
  } else if (readByCount > 0) {
    label = `Lida por ${readByCount}/${othersCount}`;
  } else {
    label = 'Enviada';
  }

  return <span className="internal-chat-read-status">{label}</span>;
}

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
  messagesLoadingOlder,
  messagesPagination,
  onBack,
  onCancelMessageEditing,
  onChatScroll,
  onLoadMessagesPage,
  onMessageDelete,
  onMessageEditSave,
  onRefresh,
  onStartMessageEditing,
  onToggleMessageOptions,
  onToggleReaction,
  onUpdateEditingMessageText,
  openMessageOptionsId,
  selectedConversation,
  selectedConversationId,
  sidebarVisibleOnMobile,
}) {
  const [reactionPickerOpenId, setReactionPickerOpenId] = useState(null);

  const handlePickReaction = (messageId, emoji) => {
    setReactionPickerOpenId(null);
    if (onToggleReaction) {
      void onToggleReaction(messageId, emoji);
    }
  };

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

          {messagesPagination && Number(messagesPagination.last_page ?? 1) > 1 ? (
            <div className="internal-chat-messages-pagination">
              <button
                type="button"
                className="app-btn-secondary text-xs"
                onClick={() => void onLoadMessagesPage(Number(messagesPagination.current_page ?? 1) - 1)}
                disabled={Number(messagesPagination.current_page ?? 1) <= 1}
              >
                Anterior
              </button>
              <span className="internal-chat-messages-pagination-label">
                Msgs pag. {messagesPagination.current_page} / {messagesPagination.last_page}
              </span>
              <button
                type="button"
                className="app-btn-secondary text-xs"
                onClick={() => void onLoadMessagesPage(Number(messagesPagination.current_page ?? 1) + 1)}
                disabled={
                  Number(messagesPagination.current_page ?? 1) >=
                  Number(messagesPagination.last_page ?? 1)
                }
              >
                Proxima
              </button>
            </div>
          ) : null}

          <ul ref={chatListRef} onScroll={onChatScroll} className="internal-chat-message-list">
            {messagesLoadingOlder ? (
              <li className="internal-chat-list-state">Carregando mensagens anteriores...</li>
            ) : messagesPagination && Number(messagesPagination.current_page ?? 1) > 1 ? (
              <li className="internal-chat-list-state">Role para cima para carregar mensagens anteriores.</li>
            ) : null}

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
              const reactions = message.reactions ?? {};

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
                      {message.attachments.map((attachment) => {
                        const attachmentUrl = String(attachment?.url ?? '').trim();
                        const isImage = String(attachment?.mime_type ?? '')
                          .toLowerCase()
                          .startsWith('image/');
                        const attachmentKey =
                          attachment.id ?? `${attachmentUrl}-${attachment.original_name}`;

                        if (isImage && attachmentUrl) {
                          return (
                            <a
                              key={attachmentKey}
                              href={attachmentUrl}
                              target="_blank"
                              rel="noreferrer"
                              className="internal-chat-attachment-image-link"
                            >
                              <img
                                src={attachmentUrl}
                                alt={attachment.original_name || 'Imagem anexada'}
                                className="internal-chat-attachment-image"
                              />
                            </a>
                          );
                        }

                        return (
                          <a
                            key={attachmentKey}
                            href={attachmentUrl || '#'}
                            target={attachmentUrl ? '_blank' : undefined}
                            rel={attachmentUrl ? 'noreferrer' : undefined}
                            className="internal-chat-attachment-link"
                            onClick={(event) => {
                              if (!attachmentUrl) {
                                event.preventDefault();
                              }
                            }}
                          >
                            {attachment.original_name || attachmentUrl || 'Anexo'}
                          </a>
                        );
                      })}
                    </div>
                  ) : null}

                  {!isDeleted ? (
                    <MessageReactions
                      reactions={reactions}
                      currentUserId={currentUserId}
                      onToggleReaction={handlePickReaction}
                      messageId={message.id}
                    />
                  ) : null}

                  <div className="internal-chat-message-footer">
                    <span className="internal-chat-message-time">
                      {formatDateTime(message.created_at)}
                      <ReadStatusLabel message={message} currentUserId={currentUserId} />
                    </span>

                    {!isDeleted && !isEditing ? (
                      <div className="internal-chat-message-actions-row">
                        <div className="internal-chat-reaction-toggle-wrapper">
                          <button
                            type="button"
                            className="internal-chat-reaction-toggle-btn"
                            onClick={() =>
                              setReactionPickerOpenId((prev) =>
                                Number(prev) === Number(message.id) ? null : Number(message.id)
                              )
                            }
                            title="Reagir"
                          >
                            😊
                          </button>
                          {Number(reactionPickerOpenId) === Number(message.id) ? (
                            <ReactionPicker onPick={(emoji) => handlePickReaction(message.id, emoji)} />
                          ) : null}
                        </div>

                        {isMine ? (
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
