import { buildConversationPreview, buildConversationTitle } from '@/services/internalChatService';

function InternalChatSidebar({
  conversationListRef,
  conversationSearchInput,
  conversations,
  conversationsError,
  conversationsLoading,
  conversationsLoadingMore,
  conversationsPagination,
  currentUserId,
  formatDateTime,
  onConversationSearchInputChange,
  onConversationsScroll,
  onNextConversationPage,
  onOpenConversation,
  onOpenCreateModal,
  onOpenCreateGroupModal,
  loadedConversationPage,
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
        <div className="internal-chat-sidebar-btn-row">
          <button
            type="button"
            className="app-btn-primary internal-chat-new-btn"
            onClick={() => void onOpenCreateModal()}
          >
            Nova conversa
          </button>
          <button
            type="button"
            className="app-btn-secondary internal-chat-new-btn"
            onClick={() => void onOpenCreateGroupModal()}
          >
            Novo grupo
          </button>
        </div>
        <input
          type="search"
          className="app-input internal-chat-search"
          placeholder="Buscar conversa..."
          value={conversationSearchInput}
          onChange={(event) => onConversationSearchInputChange(event.target.value)}
        />
      </div>

      <ul
        ref={conversationListRef}
        onScroll={onConversationsScroll}
        className="internal-chat-conversation-list"
      >
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

      {conversationsPagination && Number(conversationsPagination.last_page ?? 1) > 1 ? (
        <div className="internal-chat-conversations-pagination">
          <span className="internal-chat-conversations-pagination-label">
            Pag. {conversationsPagination.current_page} / {conversationsPagination.last_page}
          </span>
          <button
            type="button"
            className="app-btn-secondary text-xs"
            onClick={onNextConversationPage}
            disabled={
              Number(conversationsPagination.current_page ?? 1) >=
              Number(conversationsPagination.last_page ?? 1)
            }
          >
            Proxima
          </button>
        </div>
      ) : null}

      <div className="internal-chat-conversations-status">
        {conversationsLoadingMore ? (
          <span className="internal-chat-conversations-status-text">
            Carregando mais conversas...
          </span>
        ) : conversationsPagination &&
          Number(loadedConversationPage ?? 1) < Number(conversationsPagination.last_page ?? 1) ? (
          <span className="internal-chat-conversations-status-text">
            Role para carregar mais conversas.
          </span>
        ) : (
          <span className="internal-chat-conversations-status-text internal-chat-conversations-status-text--muted">
            Fim da lista.
          </span>
        )}

        {Number(conversationsPagination?.total ?? 0) > 0 ? (
          <span className="internal-chat-conversations-status-text internal-chat-conversations-status-text--muted">
            {conversations.length} / {conversationsPagination.total}
          </span>
        ) : null}
      </div>

      {conversationsError ? <p className="internal-chat-error-box">{conversationsError}</p> : null}
    </aside>
  );
}

export default InternalChatSidebar;
