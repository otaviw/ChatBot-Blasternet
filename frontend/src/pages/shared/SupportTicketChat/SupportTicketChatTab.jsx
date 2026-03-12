import { useCallback, useEffect, useRef, useState } from 'react';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import useNotifications from '@/hooks/useNotifications';
import supportTicketChatService from '@/services/supportTicketChatService';

function formatDate(value) {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';
  return date.toLocaleString('pt-BR');
}

function isImageMime(mimeType) {
  return String(mimeType ?? '').toLowerCase().startsWith('image/');
}

function resolveAttachmentUrl(attachment) {
  return String(attachment?.media_url ?? attachment?.url ?? '');
}

function sortMessages(messages) {
  return [...messages].sort((a, b) => Number(a?.id ?? 0) - Number(b?.id ?? 0));
}

function SupportTicketChatTab({ ticketId, viewerRole = 'company' }) {
  const { markReadByReference } = useNotifications({ autoLoad: false });
  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [draftMessage, setDraftMessage] = useState('');
  const [pendingImages, setPendingImages] = useState([]);
  const [sending, setSending] = useState(false);
  const [previewImageUrl, setPreviewImageUrl] = useState('');
  const pendingImagesRef = useRef([]);

  const fetchMessages = useCallback(
    async ({ silent = false } = {}) => {
      if (!ticketId) return;

      if (silent) {
        setRefreshing(true);
      } else {
        setLoading(true);
      }
      setError('');

      try {
        const response =
          viewerRole === 'admin'
            ? await supportTicketChatService.listForAdmin(ticketId)
            : await supportTicketChatService.listForCompany(ticketId);
        setMessages(response.messages ?? []);
      } catch (err) {
        setError(err?.response?.data?.message || 'Falha ao carregar mensagens do chat.');
      } finally {
        if (silent) {
          setRefreshing(false);
        } else {
          setLoading(false);
        }
      }
    },
    [ticketId, viewerRole]
  );

  const clearPendingImages = useCallback(() => {
    setPendingImages((prev) => {
      prev.forEach((item) => {
        if (item.previewUrl) {
          URL.revokeObjectURL(item.previewUrl);
        }
      });
      return [];
    });
  }, []);

  useEffect(() => {
    void fetchMessages();
  }, [fetchMessages]);

  useEffect(() => {
    if (!ticketId) return;

    void markReadByReference(
      NOTIFICATION_MODULE.SUPPORT,
      NOTIFICATION_REFERENCE_TYPE.SUPPORT_TICKET,
      ticketId
    );
  }, [markReadByReference, ticketId]);

  useEffect(() => {
    pendingImagesRef.current = pendingImages;
  }, [pendingImages]);

  useEffect(() => {
    return () => {
      pendingImagesRef.current.forEach((item) => {
        if (item.previewUrl) {
          URL.revokeObjectURL(item.previewUrl);
        }
      });
    };
  }, []);

  const handleSelectImages = (event) => {
    const fileList = Array.from(event.target?.files ?? []);
    event.target.value = '';

    if (!fileList.length) return;

    const nextImages = fileList
      .filter((file) => String(file?.type ?? '').toLowerCase().startsWith('image/'))
      .map((file) => ({
        id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
        file,
        previewUrl: URL.createObjectURL(file),
      }));

    if (!nextImages.length) return;

    setPendingImages((prev) => {
      const merged = [...prev, ...nextImages];
      const maxItems = 8;
      if (merged.length <= maxItems) {
        return merged;
      }

      const overflow = merged.slice(maxItems);
      overflow.forEach((item) => {
        if (item.previewUrl) {
          URL.revokeObjectURL(item.previewUrl);
        }
      });

      return merged.slice(0, maxItems);
    });
  };

  const handleRemovePendingImage = (imageId) => {
    setPendingImages((prev) => {
      const target = prev.find((item) => item.id === imageId);
      if (target?.previewUrl) {
        URL.revokeObjectURL(target.previewUrl);
      }

      return prev.filter((item) => item.id !== imageId);
    });
  };

  const handleSendMessage = async () => {
    if (sending) return;

    const normalizedMessage = draftMessage.trim();
    const hasImages = pendingImages.length > 0;
    if (!normalizedMessage && !hasImages) return;

    setSending(true);
    setError('');

    try {
      const response =
        viewerRole === 'admin'
          ? await supportTicketChatService.sendForAdmin(ticketId, {
              message: normalizedMessage,
              images: pendingImages.map((item) => item.file),
            })
          : await supportTicketChatService.sendForCompany(ticketId, {
              message: normalizedMessage,
              images: pendingImages.map((item) => item.file),
            });

      if (response?.message) {
        setMessages((prev) => sortMessages([...prev, response.message]));
      } else {
        await fetchMessages({ silent: true });
      }

      setDraftMessage('');
      clearPendingImages();
    } catch (err) {
      setError(err?.response?.data?.message || 'Falha ao enviar mensagem.');
    } finally {
      setSending(false);
    }
  };

  return (
    <section className="border border-[#e3e3e0] rounded-lg p-4">
      <div className="flex items-center justify-between gap-3 mb-4">
        <h2 className="font-medium">Chat da solicitação</h2>
        <button
          type="button"
          onClick={() => void fetchMessages({ silent: true })}
          disabled={refreshing || loading}
          className="px-3 py-2 text-sm rounded border border-[#d5d5d2] disabled:opacity-60"
        >
          {refreshing ? 'Atualizando...' : 'Atualizar'}
        </button>
      </div>

      {error && (
        <p className="text-sm text-red-600 mb-3">{error}</p>
      )}

      {loading ? (
        <p className="text-sm text-[#64748b]">Carregando mensagens...</p>
      ) : (
        <div className="border border-[#ececec] rounded-lg p-3 bg-[#fafafa]">
          {!messages.length ? (
            <p className="text-sm text-[#64748b]">
              Nenhuma mensagem ainda. Envie a primeira mensagem no chat.
            </p>
          ) : (
            <div className="max-h-[28rem] overflow-y-auto space-y-3 pr-1">
              {messages.map((message) => {
                const senderIsAdmin = Boolean(message.sender_is_admin);
                const isMine = viewerRole === 'admin' ? senderIsAdmin : !senderIsAdmin;

                return (
                  <div
                    key={message.id}
                    className={`flex ${isMine ? 'justify-end' : 'justify-start'}`}
                  >
                    <article
                      className={
                        `max-w-[92%] sm:max-w-[78%] rounded-lg border px-3 py-2 ` +
                        (isMine
                          ? 'bg-[#e7f0ff] border-[#c9dfff]'
                          : 'bg-white border-[#e4e4e7]')
                      }
                    >
                      <div className="flex items-center justify-between gap-4 mb-1">
                        <p className="text-xs font-medium text-[#334155]">
                          {message.sender_name || (senderIsAdmin ? 'Suporte' : 'Solicitante')}
                        </p>
                        <p className="text-[11px] text-[#64748b]">
                          {formatDate(message.created_at)}
                        </p>
                      </div>

                      {message.content ? (
                        <p className="text-sm whitespace-pre-wrap break-words text-[#0f172a]">
                          {message.content}
                        </p>
                      ) : null}

                      {(message.attachments ?? []).length > 0 && (
                        <div className={`flex flex-wrap gap-2 ${message.content ? 'mt-2' : ''}`}>
                          {message.attachments.map((attachment) => {
                            const attachmentUrl = resolveAttachmentUrl(attachment);

                            if (isImageMime(attachment.mime_type)) {
                              return (
                                <button
                                  key={attachment.id}
                                  type="button"
                                  onClick={() => setPreviewImageUrl(attachmentUrl)}
                                  className="block"
                                >
                                  <img
                                    src={attachmentUrl}
                                    alt="Imagem enviada no chat"
                                    className="w-28 h-28 rounded-md border border-[#d7d7dc] object-cover"
                                  />
                                </button>
                              );
                            }

                            return (
                              <a
                                key={attachment.id}
                                href={attachmentUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex px-2 py-1 text-xs rounded border border-[#d5d5d2] text-[#334155] hover:underline"
                              >
                                Abrir anexo
                              </a>
                            );
                          })}
                        </div>
                      )}
                    </article>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      <div className="mt-4 border-t border-[#ececec] pt-4">
        <label className="block mb-2 text-sm font-medium text-[#334155]" htmlFor="support-chat-message-input">
          Nova mensagem
        </label>
        <textarea
          id="support-chat-message-input"
          value={draftMessage}
          onChange={(event) => setDraftMessage(event.target.value)}
          placeholder="Escreva uma mensagem para a outra parte..."
          rows={3}
          className="w-full rounded-md border border-[#d5d5d2] px-3 py-2 text-sm outline-none focus:border-[#2563eb]"
        />

        {!!pendingImages.length && (
          <div className="mt-3 flex flex-wrap gap-2">
            {pendingImages.map((item) => (
              <div key={item.id} className="relative">
                <img
                  src={item.previewUrl}
                  alt="Pré-visualização da imagem"
                  className="w-20 h-20 rounded-md border border-[#d7d7dc] object-cover"
                />
                <button
                  type="button"
                  onClick={() => handleRemovePendingImage(item.id)}
                  className="absolute -top-2 -right-2 w-6 h-6 rounded-full border border-[#d5d5d2] bg-white text-xs"
                >
                  x
                </button>
              </div>
            ))}
          </div>
        )}

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <label className="inline-flex items-center px-3 py-2 text-sm rounded border border-[#d5d5d2] cursor-pointer">
            Anexar imagens
            <input
              type="file"
              accept="image/*"
              multiple
              onChange={handleSelectImages}
              className="hidden"
            />
          </label>

          <button
            type="button"
            onClick={() => void handleSendMessage()}
            disabled={sending || (!draftMessage.trim() && pendingImages.length === 0)}
            className="px-4 py-2 text-sm rounded bg-[#2563eb] text-white disabled:opacity-60"
          >
            {sending ? 'Enviando...' : 'Enviar'}
          </button>
        </div>
      </div>

      {previewImageUrl && (
        <div
          className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center"
          onClick={() => setPreviewImageUrl('')}
        >
          <div
            className="bg-white rounded-lg p-3 max-w-[90vw] max-h-[90vh] flex flex-col gap-2"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="flex justify-between items-center gap-2">
              <p className="text-sm text-[#171717] font-medium truncate">Visualizar imagem</p>
              <button
                type="button"
                onClick={() => setPreviewImageUrl('')}
                className="px-2 py-1 text-xs rounded border border-[#d5d5d2]"
              >
                Fechar
              </button>
            </div>
            <img
              src={previewImageUrl}
              alt="Imagem do chat"
              className="max-w-[80vw] max-h-[70vh] object-contain rounded border border-[#e5e5e5]"
            />
          </div>
        </div>
      )}
    </section>
  );
}

export default SupportTicketChatTab;
