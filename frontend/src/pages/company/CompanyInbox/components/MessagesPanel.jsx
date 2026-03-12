import { MESSAGE_DELIVERY_STATUS } from '@/constants/messageDeliveryStatus';

const OUTBOUND_STATUS_LABELS = {
  [MESSAGE_DELIVERY_STATUS.PENDING]: 'Pendente',
  [MESSAGE_DELIVERY_STATUS.SENT]: 'Enviada',
  [MESSAGE_DELIVERY_STATUS.DELIVERED]: 'Entregue',
  [MESSAGE_DELIVERY_STATUS.READ]: 'Lida',
  [MESSAGE_DELIVERY_STATUS.FAILED]: 'Falhou',
};

function normalizeOutboundStatus(message) {
  const raw = String(message?.delivery_status ?? '').trim().toLowerCase();
  if (raw && OUTBOUND_STATUS_LABELS[raw]) {
    return raw;
  }

  return null;
}

function groupReactionsByEmoji(reactions) {
  if (!Array.isArray(reactions) || reactions.length === 0) {
    return [];
  }

  const grouped = new Map();

  reactions.forEach((reaction) => {
    const emoji = String(reaction?.emoji ?? '').trim();
    if (!emoji) {
      return;
    }

    grouped.set(emoji, Number(grouped.get(emoji) ?? 0) + 1);
  });

  return Array.from(grouped.entries()).map(([emoji, count]) => ({
    emoji,
    count,
  }));
}

function MessagesPanel({
  detail,
  messagesPagination,
  onLoadMessagesPage,
  chatListRef,
  onChatScroll,
  messagesLoadingOlder,
  getMessageImageUrl,
}) {
  return (
    <>
      {messagesPagination && messagesPagination.last_page > 1 && (
        <div className="inbox-messages-pagination shrink-0 flex items-center gap-2">
          <button
            type="button"
            onClick={() => onLoadMessagesPage(messagesPagination.current_page - 1)}
            disabled={messagesPagination.current_page <= 1}
            className="app-btn-secondary text-xs"
          >
            Anterior
          </button>
          <span className="text-xs text-[#737373]">
            Msgs pág. {messagesPagination.current_page} / {messagesPagination.last_page}
          </span>
          <button
            type="button"
            onClick={() => onLoadMessagesPage(messagesPagination.current_page + 1)}
            disabled={messagesPagination.current_page >= messagesPagination.last_page}
            className="app-btn-secondary text-xs"
          >
            Próxima
          </button>
        </div>
      )}

      <ul
        ref={chatListRef}
        onScroll={onChatScroll}
        className="inbox-chat space-y-2.5 text-sm flex-1 min-h-0 overflow-y-auto overscroll-contain"
      >
        {messagesLoadingOlder ? (
          <li className="inbox-chat-loader">Carregando mensagens anteriores...</li>
        ) : messagesPagination && Number(messagesPagination.current_page ?? 1) > 1 ? (
          <li className="inbox-chat-loader">Role para cima para carregar mensagens anteriores.</li>
        ) : null}
        {(detail.messages ?? []).map((msg) => {
          const outboundStatus = msg.direction === 'out' ? normalizeOutboundStatus(msg) : null;
          const reactionGroups = groupReactionsByEmoji(msg.reactions ?? []);

          return (
            <li
              key={msg.id}
              className={`inbox-message-bubble inbox-message-${msg.direction === 'in' ? 'in' : 'out'}`}
            >
              <span className="inbox-message-label">{msg.direction === 'in' ? 'Cliente' : 'Atendente/Bot'}</span>
              {msg.content_type === 'image' ? (
                <div className="company-inbox-message-media">
                  <a href={getMessageImageUrl(msg)} target="_blank" rel="noreferrer">
                    <img
                      src={getMessageImageUrl(msg)}
                      alt="Imagem enviada na conversa"
                      className="company-inbox-message-image"
                    />
                  </a>
                  {msg.text ? <p className="company-inbox-message-caption">{msg.text}</p> : null}
                </div>
              ) : (
                <span className="inbox-message-text">{msg.text}</span>
              )}
              {outboundStatus ? (
                <span className={`inbox-message-status inbox-message-status-${outboundStatus}`}>
                  {OUTBOUND_STATUS_LABELS[outboundStatus]}
                </span>
              ) : null}
              {reactionGroups.length > 0 ? (
                <div className="inbox-message-reactions">
                  {reactionGroups.map((item) => (
                    <span
                      key={`${msg.id}-${item.emoji}`}
                      className="inbox-message-reaction-pill"
                      title={`${item.emoji} (${item.count})`}
                    >
                      <span>{item.emoji}</span>
                      <span className="inbox-message-reaction-count">{item.count}</span>
                    </span>
                  ))}
                </div>
              ) : null}
            </li>
          );
        })}
      </ul>
    </>
  );
}

export default MessagesPanel;
