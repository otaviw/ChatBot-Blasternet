import React, { useMemo } from 'react';
import { useVirtualizer } from '@tanstack/react-virtual';
import { MESSAGE_DELIVERY_STATUS } from '@/constants/messageDeliveryStatus';
import { showError } from '@/services/toastService';

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

  return Array.from(grouped.entries()).map(([emoji, count]) => ({ emoji, count }));
}

function parseLocationPayload(rawText) {
  try {
    const parsed = JSON.parse(rawText || '{}');
    return {
      name: parsed?.name ? String(parsed.name) : 'Localizacao',
      latitude: String(parsed?.latitude ?? ''),
      longitude: String(parsed?.longitude ?? ''),
    };
  } catch {
    return {
      name: 'Localizacao',
      latitude: '',
      longitude: '',
    };
  }
}

function AudioPlayer({ src }) {
  const audioRef = React.useRef(null);
  const [playing, setPlaying] = React.useState(false);
  const [currentTime, setCurrentTime] = React.useState(0);
  const [duration, setDuration] = React.useState(0);

  const toggle = () => {
    const element = audioRef.current;
    if (!element) return;
    if (playing) {
      element.pause();
    } else {
      void element.play();
    }
  };

  const formatTime = (seconds) => {
    if (!seconds || Number.isNaN(seconds) || !Number.isFinite(seconds)) return '0:00';
    const minutes = Math.floor(seconds / 60);
    const sec = Math.floor(seconds % 60);
    return `${minutes}:${sec.toString().padStart(2, '0')}`;
  };

  const progress = duration > 0 ? (currentTime / duration) * 100 : 0;

  return (
    <div className="flex items-center gap-2 px-3 py-2 bg-gray-100 rounded max-w-xs w-full">
      <audio
        ref={audioRef}
        src={src}
        preload="metadata"
        onPlay={() => setPlaying(true)}
        onPause={() => setPlaying(false)}
        onEnded={() => {
          setPlaying(false);
          setCurrentTime(0);
        }}
        onTimeUpdate={() => setCurrentTime(audioRef.current?.currentTime ?? 0)}
        onLoadedMetadata={() => setDuration(audioRef.current?.duration ?? 0)}
      />

      <button
        type="button"
        onClick={toggle}
        aria-label={playing ? 'Pausar audio' : 'Reproduzir audio'}
        className="shrink-0 w-8 h-8 flex items-center justify-center rounded-full bg-blue-500 text-white hover:bg-blue-600"
      >
        {playing ? (
          <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor" aria-hidden="true">
            <rect x="1" y="1" width="3.5" height="10" rx="1" />
            <rect x="7.5" y="1" width="3.5" height="10" rx="1" />
          </svg>
        ) : (
          <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor" aria-hidden="true">
            <path d="M2 1.5l9 4.5-9 4.5z" />
          </svg>
        )}
      </button>

      <div className="flex-1 flex flex-col gap-1 min-w-0">
        <div
          className="h-1.5 bg-gray-300 rounded-full cursor-pointer"
          onClick={(event) => {
            const element = audioRef.current;
            if (!element || !duration) return;
            const rect = event.currentTarget.getBoundingClientRect();
            element.currentTime = ((event.clientX - rect.left) / rect.width) * duration;
          }}
        >
          <div className="h-1.5 bg-blue-500 rounded-full" style={{ width: `${progress}%` }} />
        </div>
        <div className="flex justify-between text-[10px] text-gray-500">
          <span>{formatTime(currentTime)}</span>
          <span>{formatTime(duration)}</span>
        </div>
      </div>
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
  focusedMessageId,
}) {
  const setChatListElement = React.useCallback(
    (node) => {
      if (chatListRef) {
        chatListRef.current = node;
      }
    },
    [chatListRef]
  );

  const messages = React.useMemo(() => detail?.messages ?? [], [detail?.messages]);
  const rows = useMemo(() => {
    const result = [];

    if (messagesLoadingOlder) {
      result.push({
        key: 'messages-loader',
        type: 'state',
        text: 'Carregando mensagens anteriores...',
      });
    } else if (messagesPagination && Number(messagesPagination.current_page ?? 1) > 1) {
      result.push({
        key: 'messages-hint',
        type: 'state',
        text: 'Role para cima para carregar mensagens anteriores.',
      });
    }

    messages.forEach((message, index) => {
      result.push({
        key: `message-${message.id ?? index}-${message.created_at ?? ''}`,
        type: 'message',
        message,
      });
    });

    return result;
  }, [messages, messagesLoadingOlder, messagesPagination]);

  const rowVirtualizer = useVirtualizer({
    count: rows.length,
    getScrollElement: () => chatListRef?.current ?? null,
    estimateSize: (index) => (rows[index]?.type === 'state' ? 34 : 120),
    overscan: 8,
  });

  React.useEffect(() => {
    rowVirtualizer.measure();
  }, [rowVirtualizer, rows.length, detail?.id]);

  React.useEffect(() => {
    if (!focusedMessageId) return;

    const rowIndex = rows.findIndex(
      (row) => row?.type === 'message' && Number(row.message?.id) === Number(focusedMessageId)
    );
    if (rowIndex < 0) return;

    rowVirtualizer.scrollToIndex(rowIndex, { align: 'center' });
  }, [focusedMessageId, rowVirtualizer, rows]);

  const handleDownloadDocument = async (message) => {
    const mediaUrl = getMessageImageUrl(message);

    try {
      const response = await fetch(mediaUrl, { credentials: 'same-origin' });
      if (!response.ok) throw new Error('Erro ao baixar');

      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = message.media_filename || 'documento';
      document.body.appendChild(anchor);
      anchor.click();
      window.URL.revokeObjectURL(url);
      anchor.remove();
    } catch (_error) {
      showError('Erro ao baixar arquivo.');
    }
  };

  const renderMessage = (message) => {
    const outboundStatus = message.direction === 'out' ? normalizeOutboundStatus(message) : null;
    const reactionGroups = groupReactionsByEmoji(message.reactions ?? []);
    const isFocused = Number(focusedMessageId || 0) === Number(message.id || 0);

    return (
      <div
        key={message.id}
        className={`inbox-message-bubble inbox-message-${message.direction === 'in' ? 'in' : 'out'}${isFocused ? ' inbox-message-focus' : ''}`}
      >
        {(message.direction === 'in' || message.sender_name) && (
          <span className="inbox-message-label">
            {message.direction === 'in' ? 'Cliente' : message.sender_name}
          </span>
        )}
        {message.content_type === 'image' ? (
          <div className="company-inbox-message-media">
            <a href={getMessageImageUrl(message)} target="_blank" rel="noreferrer">
              <img
                src={getMessageImageUrl(message)}
                alt="Imagem enviada na conversa"
                className="company-inbox-message-image"
              />
            </a>
            {message.text ? <p className="company-inbox-message-caption">{message.text}</p> : null}
          </div>
        ) : message.content_type === 'audio' ? (
          <div className="company-inbox-message-media">
            {message.media_key ? (
              <AudioPlayer src={getMessageImageUrl(message)} />
            ) : (
              <span className="inbox-message-text text-xs text-gray-400">Audio indisponivel</span>
            )}
            {message.text ? <p className="company-inbox-message-caption text-xs mt-1">{message.text}</p> : null}
          </div>
        ) : message.content_type === 'video' ? (
          <div className="company-inbox-message-media">
            {message.media_key ? (
              <video controls className="w-full max-w-md rounded">
                <source src={getMessageImageUrl(message)} type={message.media_mime_type || 'video/mp4'} />
                Seu navegador nao suporta video.
              </video>
            ) : (
              <span className="inbox-message-text text-xs text-gray-400">Video indisponivel</span>
            )}
            {message.text ? <p className="company-inbox-message-caption">{message.text}</p> : null}
          </div>
        ) : message.content_type === 'document' ? (
          <div className="company-inbox-message-media">
            {message.media_key ? (
              <button
                type="button"
                onClick={() => void handleDownloadDocument(message)}
                className="inline-flex items-center p-2 bg-blue-100 rounded text-sm hover:bg-blue-200 cursor-pointer"
                aria-label={`Baixar arquivo ${message.media_filename || 'documento'}`}
              >
                {message.media_filename || 'Documento'}
                {message.media_size_bytes ? (
                  <span className="ml-2 text-xs text-gray-500">
                    ({(message.media_size_bytes / 1024 / 1024).toFixed(1)} MB)
                  </span>
                ) : null}
              </button>
            ) : (
              <span className="inbox-message-text text-xs text-gray-400">Documento indisponivel</span>
            )}
            {message.text ? <p className="company-inbox-message-caption">{message.text}</p> : null}
          </div>
        ) : message.content_type === 'sticker' ? (
          <img src={getMessageImageUrl(message)} className="max-w-xs rounded" alt="Sticker" />
        ) : message.content_type === 'location' ? (
          <div className="p-2 bg-blue-50 rounded">
            {(() => {
              const location = parseLocationPayload(message.text);
              const hasCoordinates = location.latitude && location.longitude;
              return (
                <>
                  {location.name}
                  <br />
                  {hasCoordinates ? (
                    <a
                      href={`https://maps.google.com/?q=${location.latitude},${location.longitude}`}
                      target="_blank"
                      rel="noreferrer"
                    >
                      Abrir no Maps
                    </a>
                  ) : (
                    <span className="text-xs text-[#64748b]">Coordenadas indisponiveis</span>
                  )}
                </>
              );
            })()}
          </div>
        ) : (
          <span className="inbox-message-text">{message.text}</span>
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
                key={`${message.id}-${item.emoji}`}
                className="inbox-message-reaction-pill"
                title={`${item.emoji} (${item.count})`}
              >
                <span>{item.emoji}</span>
                <span className="inbox-message-reaction-count">{item.count}</span>
              </span>
            ))}
          </div>
        ) : null}
      </div>
    );
  };

  return (
    <>
      {messagesPagination && messagesPagination.last_page > 1 ? (
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
            Msgs pag. {messagesPagination.current_page} / {messagesPagination.last_page}
          </span>
          <button
            type="button"
            onClick={() => onLoadMessagesPage(messagesPagination.current_page + 1)}
            disabled={messagesPagination.current_page >= messagesPagination.last_page}
            className="app-btn-secondary text-xs"
          >
            Proxima
          </button>
        </div>
      ) : null}

      <div
        ref={setChatListElement}
        onScroll={onChatScroll}
        className="inbox-chat space-y-2.5 text-sm flex-1 min-h-0 overflow-y-auto overscroll-contain"
        role="list"
        aria-label="Mensagens da conversa"
      >
        <div style={{ height: `${rowVirtualizer.getTotalSize()}px`, position: 'relative' }}>
          {rowVirtualizer.getVirtualItems().map((virtualRow) => {
            const row = rows[virtualRow.index];
            if (!row) return null;

            return (
              <div
                key={row.key}
                ref={rowVirtualizer.measureElement}
                data-index={virtualRow.index}
                style={{
                  position: 'absolute',
                  top: 0,
                  left: 0,
                  width: '100%',
                  transform: `translateY(${virtualRow.start}px)`,
                }}
                role="listitem"
              >
                {row.type === 'state' ? (
                  <div className="inbox-chat-loader">{row.text}</div>
                ) : (
                  renderMessage(row.message)
                )}
              </div>
            );
          })}
        </div>
      </div>
    </>
  );
}

export default MessagesPanel;
