import {
  CONVERSATION_HANDLING_MODE,
  CONVERSATION_STATUS,
} from '@/constants/conversation';

function ConversationsSidebar({
  selectedId,
  convSearchInput,
  onConvSearchInputChange,
  onConvSearchEnter,
  conversationListRef,
  onConversationsScroll,
  conversations,
  unreadConversationSet,
  onOpenConversation,
  conversationsPagination,
  onNextConversationPage,
  conversationsLoadingMore,
  loadedConversationPage,
}) {
  return (
    <aside
      className={`inbox-conversations ${selectedId ? 'hidden lg:flex' : 'flex'} flex-col`}
    >
      <div className="inbox-conversations-header">
        <h2 className="inbox-conversations-title">Conversas</h2>
        <input
          type="search"
          value={convSearchInput}
          onChange={(event) => onConvSearchInputChange(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              onConvSearchEnter();
            }
          }}
          placeholder="Buscar contatos..."
          className="inbox-search-input app-input"
        />
      </div>
      <ul
        ref={conversationListRef}
        onScroll={onConversationsScroll}
        className="inbox-conversations-list space-y-2 text-sm"
      >
        {!conversations.length && <li className="text-[#706f6c] py-4">Nenhuma conversa.</li>}
        {conversations.map((conv) => {
          const hasUnread = unreadConversationSet.has(Number(conv.id));

          return (
            <li key={conv.id}>
              <button
                type="button"
                onClick={() => onOpenConversation(conv.id)}
                className={`w-full text-left px-3 py-2.5 rounded-lg transition ${
                  selectedId === conv.id
                    ? 'bg-[#eff6ff]'
                    : conv.status === CONVERSATION_STATUS.CLOSED
                      ? 'opacity-70 hover:bg-white/60'
                      : hasUnread
                        ? 'bg-red-50/80 hover:bg-red-50'
                        : 'hover:bg-white'
                }`}
              >
                <div className="font-medium text-[#0f172a]">
                  {conv.customer_name ? `${conv.customer_name} (${conv.customer_phone})` : conv.customer_phone}
                  {' - '}
                  {conv.status} ({conv.messages_count ?? 0} msg)
                </div>
                <div className="text-xs text-[#526175] mt-1">
                  {conv.status === CONVERSATION_STATUS.CLOSED
                    ? 'encerrada'
                    : conv.handling_mode === CONVERSATION_HANDLING_MODE.HUMAN
                      ? 'manual'
                      : 'bot'}
                  {hasUnread ? (
                    <span className="ml-2 rounded-full bg-[#dc2626] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                      nova msg
                    </span>
                  ) : null}
                  {conv.current_area?.name ? <span className="ml-2">área: {conv.current_area.name}</span> : null}
                  {(conv.tags ?? []).length > 0 && (
                    <span className="ml-2">{conv.tags.join(', ')}</span>
                  )}
                </div>
              </button>
            </li>
          );
        })}
      </ul>
      {conversationsPagination && conversationsPagination.last_page > 1 && (
        <div className="inbox-conversations-pagination">
          <span className="text-xs text-[#737373]">
            Pág. {conversationsPagination.current_page} / {conversationsPagination.last_page}
          </span>
          <button
            type="button"
            onClick={onNextConversationPage}
            disabled={conversationsPagination.current_page >= conversationsPagination.last_page}
            className="app-btn-secondary text-xs"
          >
            Próxima
          </button>
        </div>
      )}
      <div className="inbox-conversations-status">
        {conversationsLoadingMore ? (
          <span className="text-xs text-[#737373]">Carregando mais conversas...</span>
        ) : conversationsPagination && loadedConversationPage < Number(conversationsPagination.last_page ?? 1) ? (
          <span className="text-xs text-[#737373]">Role para carregar mais conversas.</span>
        ) : (
          <span className="text-xs text-[#a3a3a3]">Fim da lista.</span>
        )}
        {conversationsPagination?.total ? (
          <span className="text-xs text-[#a3a3a3]">
            {conversations.length} / {conversationsPagination.total}
          </span>
        ) : null}
      </div>
    </aside>
  );
}

export default ConversationsSidebar;
