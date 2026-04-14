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

function formatDate(value) {
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

function ConversationSearchModal({
  open,
  query,
  loading,
  results = [],
  onQueryChange,
  onClose,
  onSelectResult,
}) {
  if (!open) return null;

  return (
    <div className="inbox-tags-modal-overlay" role="dialog" aria-modal="true">
      <div className="inbox-conversation-search-modal">
        <div className="flex items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-[#0f172a]">Buscar nesta conversa</h3>
          <button type="button" className="app-btn-secondary text-xs py-1" onClick={onClose}>
            Fechar
          </button>
        </div>

        <input
          type="search"
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          className="app-input text-sm"
          placeholder="Digite para buscar no histórico da conversa..."
          autoFocus
        />

        <div className="inbox-conversation-search-list">
          {loading ? (
            <p className="text-xs text-[#64748b]">Buscando mensagens...</p>
          ) : results.length === 0 && query.trim() !== '' ? (
            <p className="text-xs text-[#64748b]">Nenhuma mensagem encontrada para "{query.trim()}".</p>
          ) : results.length === 0 ? (
            <p className="text-xs text-[#94a3b8]">Digite um termo para iniciar a busca.</p>
          ) : (
            results.map((result) => (
              <button
                key={`search-result-${result.message_id}`}
                type="button"
                className="inbox-conversation-search-item"
                onClick={() => onSelectResult(result)}
              >
                <span className="text-[11px] text-[#64748b]">
                  {result.direction === 'in' ? 'Cliente' : 'Atendente/Bot'} · {formatDate(result.created_at)}
                </span>
                <span className="text-xs text-[#334155]">{renderHighlightedText(result.snippet, query)}</span>
              </button>
            ))
          )}
        </div>
      </div>
    </div>
  );
}

export default ConversationSearchModal;
