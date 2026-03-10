import { buildConversationPreview, buildConversationTitle } from '@/services/internalChatService';

function InternalChatSidebar({
  conversationSearchInput,
  conversations,
  conversationsError,
  conversationsLoading,
  currentUserId,
  formatDateTime,
  onConversationSearchInputChange,
  onOpenConversation,
  onOpenCreateModal,
  selectedConversationId,
  sidebarVisibleOnMobile,
}) {
  return (
    <aside
      className={`internal-chat-sidebar ${
        sidebarVisibleOnMobile || !selectedConversationId ? 'internal-chat-sidebar--visible' : ''
      }`}
    >
      <div className="internal-chat-sidebar-top">
        <button
          type="button"
          className="app-btn-primary internal-chat-new-btn"
          onClick={() => void onOpenCreateModal()}
        >
          Nova conversa
        </button>
        <input
          type="search"
          className="app-input internal-chat-search"
          placeholder="Buscar conversa..."
          value={conversationSearchInput}
          onChange={(event) => onConversationSearchInputChange(event.target.value)}
        />
      </div>

      <ul className="internal-chat-conversation-list">
        {conversationsLoading && !conversations.length ? (
          <li className="internal-chat-list-state">Carregando conversas...</li>
        ) : null}

        {!conversationsLoading && !conversations.length ? (
          <li className="internal-chat-list-state">Nenhuma conversa encontrada.</li>
        ) : null}

        {conversations.map((conversation) => {
          const active = Number(selectedConversationId) === Number(conversation.id);
          const unreadCount = Number(conversation.unread_count ?? 0);

          return (
            <li key={conversation.id}>
              <button
                type="button"
                className={`internal-chat-conversation-item ${
                  active ? 'internal-chat-conversation-item--active' : ''
                }`}
                onClick={() => void onOpenConversation(conversation.id)}
              >
                <div className="internal-chat-conversation-row">
                  <span className="internal-chat-conversation-name">
                    {buildConversationTitle(conversation, currentUserId)}
                  </span>
                  <span className="internal-chat-conversation-time">
                    {formatDateTime(conversation.last_message_at)}
                  </span>
                </div>
                <div className="internal-chat-conversation-row">
                  <span className="internal-chat-conversation-preview">
                    {buildConversationPreview(conversation)}
                  </span>
                  {unreadCount > 0 ? (
                    <span className="internal-chat-unread-badge">
                      {unreadCount > 99 ? '99+' : unreadCount}
                    </span>
                  ) : null}
                </div>
              </button>
            </li>
          );
        })}
      </ul>

      {conversationsError ? <p className="internal-chat-error-box">{conversationsError}</p> : null}
    </aside>
  );
}

export default InternalChatSidebar;
