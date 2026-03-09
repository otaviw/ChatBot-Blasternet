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
        {(detail.messages ?? []).map((msg) => (
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
          </li>
        ))}
      </ul>
    </>
  );
}

export default MessagesPanel;
