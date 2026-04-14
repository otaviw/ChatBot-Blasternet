import {
  CONVERSATION_HANDLING_MODE,
  CONVERSATION_STATUS,
} from '@/constants/conversation';
import ServiceAreaBadge from '@/components/company/ServiceAreaBadge/ServiceAreaBadge.jsx';
import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
import ConversationsFilter from './ConversationsFilter.jsx';

function InlineTagBadge({ name, color }) {
  return (
    <span
      className="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium text-white"
      style={{ backgroundColor: color }}
    >
      {name}
    </span>
  );
}

const STATUS_LABEL = {
  [CONVERSATION_STATUS.OPEN]: 'aberta',
  [CONVERSATION_STATUS.IN_PROGRESS]: 'em atendimento',
  [CONVERSATION_STATUS.CLOSED]: 'encerrada',
};

function formatResultDate(value) {
  if (!value) return '';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';

  return date.toLocaleString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function renderHighlightedText(text, query) {
  const content = String(text ?? '');
  const term = String(query ?? '').trim();
  if (!content || !term) return content;

  const escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const parts = content.split(new RegExp(`(${escaped})`, 'ig'));
  const normalizedTerm = term.toLowerCase();

  return parts.map((part, index) => (
    part.toLowerCase() === normalizedTerm
      ? <strong key={`hl-${index}`}>{part}</strong>
      : <span key={`tx-${index}`}>{part}</span>
  ));
}

function truncatePreview(text, max = 60) {
  const normalized = String(text ?? '').replace(/\s+/g, ' ').trim();
  if (normalized === '') {
    return '';
  }

  if (normalized.length <= max) {
    return normalized;
  }

  return `${normalized.slice(0, max)}...`;
}

function formatLastMessagePreview(conversation) {
  const text = String(conversation?.last_message_text ?? '').trim();
  const direction = String(conversation?.last_message_direction ?? '').trim().toLowerCase();

  if (text === '') {
    if (conversation?.last_message_id) {
      return direction === 'out' ? 'Você: Mensagem de mídia' : 'Mensagem de mídia';
    }

    return 'Sem mensagens ainda';
  }

  const preview = truncatePreview(text, 60);
  return direction === 'out' ? `Você: ${preview}` : preview;
}

function ConversationsSidebar({
  serviceAreaNames = [],
  attendants = [],
  companyTags = [],
  conversationCounters = { por_area: [], sem_area: { total_abertas: 0 } },
  selectedId,
  mobileVisible,
  convSearchInput,
  onConvSearchInputChange,
  onConvSearchEnter,
  isSearchMode,
  searchTerm,
  filters,
  onFiltersChange,
  conversationListRef,
  onConversationsScroll,
  conversationsLoading,
  conversations,
  unreadConversationSet,
  onOpenConversation,
  conversationsPagination,
  onNextConversationPage,
  conversationsLoadingMore,
  loadedConversationPage,
  onNewConversation,
}) {
  return (
    <aside
      className={`inbox-conversations flex-col min-w-0${mobileVisible ? ' inbox-conversations--visible' : ''}`}
    >
      <div className="inbox-conversations-header">
        <div className="flex items-center justify-between gap-2">
          <h2 className="inbox-conversations-title" style={{ margin: 0 }}>Conversas</h2>
          <button
            type="button"
            onClick={onNewConversation}
            title="Nova conversa"
            className="app-btn-primary text-xs py-1 px-2.5 shrink-0"
          >
            + Nova
          </button>
        </div>
        <div className="inbox-search-box">
          <span className="inbox-search-icon" aria-hidden>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
              <circle cx="11" cy="11" r="7" stroke="currentColor" strokeWidth="2" />
              <path d="M20 20L16.5 16.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
            </svg>
          </span>
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
            placeholder="Buscar por telefone ou mensagem..."
            className="inbox-search-input app-input"
          />
        </div>
        <ConversationsFilter
          filters={filters}
          onFiltersChange={onFiltersChange}
          serviceAreaNames={serviceAreaNames}
          attendants={attendants}
          companyTags={companyTags}
        />
        {!isSearchMode && (conversationCounters?.por_area?.length > 0 || Number(conversationCounters?.sem_area?.total_abertas ?? 0) > 0) ? (
          <div className="inbox-area-counter-wrap">
            {Array.isArray(conversationCounters.por_area) && conversationCounters.por_area.map((item) => (
              <span key={`area-counter-${item.area_id}`} className="inbox-area-counter-item">
                <span className="truncate">{item.area_nome}</span>
                <span className="inbox-area-counter-badge">{item.total_abertas}</span>
              </span>
            ))}
            {Number(conversationCounters?.sem_area?.total_abertas ?? 0) > 0 ? (
              <span className="inbox-area-counter-item">
                <span className="truncate">Sem área</span>
                <span className="inbox-area-counter-badge">{conversationCounters.sem_area.total_abertas}</span>
              </span>
            ) : null}
          </div>
        ) : null}
      </div>
      <ul
        ref={conversationListRef}
        onScroll={onConversationsScroll}
        className="inbox-conversations-list space-y-2 text-sm"
      >
        {conversationsLoading && !conversations.length ? (
          <li className="space-y-2 py-1">
            {Array.from({ length: 6 }).map((_, index) => (
              <div key={`conv-skeleton-${index}`} className="rounded-lg border border-[#e2e8f0] bg-white p-3">
                <LoadingSkeleton className="h-4 w-10/12" />
                <LoadingSkeleton className="h-3 w-8/12 mt-2" />
              </div>
            ))}
          </li>
        ) : null}
        {!conversationsLoading && !conversations.length ? (
          <li className="py-3">
            <EmptyState
              title={
                isSearchMode
                  ? `Nenhuma conversa encontrada para "${searchTerm}"`
                  : 'Nenhuma conversa encontrada'
              }
              subtitle={
                isSearchMode
                  ? 'Tente ajustar o termo ou os filtros de status e data.'
                  : 'Tente ajustar os filtros ou iniciar um novo atendimento.'
              }
            />
          </li>
        ) : null}
        {conversations.map((conv) => {
          const hasUnread = unreadConversationSet.has(Number(conv.id));
          const convTags = Array.isArray(conv.tags) ? conv.tags : [];
          const isSearchResult = isSearchMode;

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
                  {!isSearchResult ? (
                    <>
                      {' - '}
                      ({conv.messages_count ?? 0} msg)
                    </>
                  ) : null}
                </div>
                <div className="text-xs text-[#526175] mt-1 flex flex-wrap items-center gap-1.5">
                  <span>{STATUS_LABEL[conv.status] ?? conv.status}</span>
                  {isSearchResult && conv.matched_at ? (
                    <span>{formatResultDate(conv.matched_at)}</span>
                  ) : null}
                  {!isSearchResult ? (
                    <span>
                      {conv.handling_mode === CONVERSATION_HANDLING_MODE.HUMAN ? 'manual' : 'bot'}
                    </span>
                  ) : null}
                  {hasUnread ? (
                    <span className="rounded-full bg-[#dc2626] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                      nova msg
                    </span>
                  ) : null}
                  {conv.current_area?.name ? (
                    <ServiceAreaBadge areaName={conv.current_area.name} serviceAreaNames={serviceAreaNames} />
                  ) : null}
                  {convTags.map((tag) => (
                    <InlineTagBadge key={tag.id} name={tag.name} color={tag.color} />
                  ))}
                </div>
                {isSearchResult && conv.snippet ? (
                  <div className="mt-1 text-xs text-[#334155] line-clamp-2">
                    {renderHighlightedText(conv.snippet, searchTerm)}
                  </div>
                ) : !isSearchResult ? (
                  <div className="mt-1 text-xs text-[#475569] line-clamp-1">
                    {formatLastMessagePreview(conv)}
                  </div>
                ) : null}
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
        {conversationsLoading ? (
          <span className="text-xs text-[#737373]">
            {isSearchMode ? 'Buscando conversas...' : 'Atualizando conversas...'}
          </span>
        ) : conversationsLoadingMore ? (
          <span className="text-xs text-[#737373]">Carregando mais conversas...</span>
        ) : isSearchMode ? (
          <span className="text-xs text-[#737373]">Resultados da busca</span>
        ) : conversationsPagination && loadedConversationPage < Number(conversationsPagination.last_page ?? 1) ? (
          <span className="text-xs text-[#737373]">Role para carregar mais conversas.</span>
        ) : (
          <span className="text-xs text-[#a3a3a3]">Fim da lista.</span>
        )}
        {isSearchMode ? (
          <span className="text-xs text-[#a3a3a3]">{conversations.length} resultado(s)</span>
        ) : conversationsPagination?.total ? (
          <span className="text-xs text-[#a3a3a3]">
            {conversations.length} / {conversationsPagination.total}
          </span>
        ) : null}
      </div>
    </aside>
  );
}

export default ConversationsSidebar;
