import React from 'react';
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

function AudioPlayer({ src, mimeType }) {
  const [cannotPlay, setCannotPlay] = React.useState(false);

  return (
    <div className="flex flex-col gap-1">
      {!cannotPlay && (
        <audio
          controls
          src={src}
          className="w-full max-w-md"
          onError={() => setCannotPlay(true)}
        />
      )}
      {cannotPlay && (
        <a
          href={src}
          target="_blank"
          rel="noreferrer"
          className="inline-flex items-center gap-1 px-3 py-2 bg-gray-100 rounded text-xs text-gray-700 hover:bg-gray-200"
        >
          🎵 Ouvir / baixar áudio
        </a>
      )}
    </div>
  );
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
  const handleDownloadDocument = async (msg) => {
    const mediaUrl = getMessageImageUrl(msg);
    try {
      const response = await fetch(mediaUrl, { credentials: 'same-origin' });
      if (!response.ok) throw new Error('Erro ao baixar');
      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = msg.media_filename || 'documento';
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      a.remove();
    } catch (error) {
      console.error('Download falhou:', error);
      alert('Erro ao baixar arquivo');
    }
  };

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
              )

                : msg.content_type === 'audio' ? (
                  <div className="company-inbox-message-media">
                    {msg.media_key ? (
                      <AudioPlayer src={getMessageImageUrl(msg)} mimeType={msg.media_mime_type} />
                    ) : (
                      <span className="inbox-message-text text-xs text-gray-400">Áudio indisponível</span>
                    )}
                    {msg.text ? <p className="company-inbox-message-caption text-xs mt-1">{msg.text}</p> : null}
                  </div>)

                  : msg.content_type === 'video' ? (
                    <div className="company-inbox-message-media">
                      {msg.media_key ? (
                        <video controls className="w-full max-w-md rounded">
                          <source src={getMessageImageUrl(msg)} type={msg.media_mime_type || 'video/mp4'} />
                          Seu navegador não suporta vídeo.
                        </video>
                      ) : (
                        <span className="inbox-message-text text-xs text-gray-400">Vídeo indisponível</span>
                      )}
                      {msg.text && <p className="company-inbox-message-caption">{msg.text}</p>}
                    </div>
                  )

                    : msg.content_type === 'document' ? (
                      <div className="company-inbox-message-media">
                        {msg.media_key ? (
                          <button
                            onClick={() => handleDownloadDocument(msg)}
                            className="inline-flex items-center p-2 bg-blue-100 rounded text-sm hover:bg-blue-200 cursor-pointer"
                          >
                            {msg.media_filename || 'Documento'}
                            {msg.media_size_bytes && (
                              <span className="ml-2 text-xs text-gray-500">
                                ({(msg.media_size_bytes / 1024 / 1024).toFixed(1)} MB)
                              </span>
                            )}
                          </button>
                        ) : (
                          <span className="inbox-message-text text-xs text-gray-400">Documento indisponível</span>
                        )}
                        {msg.text && <p className="company-inbox-message-caption">{msg.text}</p>}
                      </div>
                    )

                      : msg.content_type === 'sticker' ? (
                        <img src={getMessageImageUrl(msg)} className="max-w-xs rounded" alt="Sticker" />
                      )

                        : msg.content_type === 'location' ? (
                          <div className="p-2 bg-blue-50 rounded">
                            📍 {JSON.parse(msg.text || '{}').name || 'Localização'}<br />
                            <a href={`https://maps.google.com/?q=${JSON.parse(msg.text || '{}').latitude},${JSON.parse(msg.text || '{}').longitude}`} target="_blank">Abrir no Maps</a>
                          </div>
                        )

                          : (
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
