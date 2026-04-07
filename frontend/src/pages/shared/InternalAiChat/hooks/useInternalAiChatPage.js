import { useCallback, useEffect, useRef, useState } from 'react';
import {
  createInternalAiConversation,
  getInternalAiConversation,
  listInternalAiConversations,
  sendInternalAiConversationMessage,
  sortInternalAiConversationsByActivity,
  upsertInternalAiConversationInList,
} from '@/services/internalAiChatService';
import {
  canRequestMessageSend,
  mergeMessagesById,
  parseRequestErrorMessage,
  toStatusCode,
} from '../internalAiChatUtils';

const CONVERSATIONS_PER_PAGE = 15;
const MESSAGES_PER_PAGE = 30;

export default function useInternalAiChatPage({ enabled, companyId = null }) {
  const [conversations, setConversations] = useState([]);
  const [conversationsPagination, setConversationsPagination] = useState(null);
  const [conversationsLoading, setConversationsLoading] = useState(false);
  const [conversationsLoadingMore, setConversationsLoadingMore] = useState(false);
  const [conversationsError, setConversationsError] = useState('');

  const [selectedConversationId, setSelectedConversationId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [messagesPagination, setMessagesPagination] = useState(null);
  const [messagesLoadingOlder, setMessagesLoadingOlder] = useState(false);

  const [draftMessage, setDraftMessage] = useState('');
  const [sendBusy, setSendBusy] = useState(false);
  const [sendError, setSendError] = useState('');

  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');

  const selectedConversationIdRef = useRef(null);
  const chatListRef = useRef(null);
  const shouldScrollToBottomRef = useRef(false);
  const wasChatNearBottomRef = useRef(true);

  useEffect(() => {
    selectedConversationIdRef.current = selectedConversationId;
  }, [selectedConversationId]);

  const clearConversationSelection = useCallback(() => {
    setSelectedConversationId(null);
    setDetail(null);
    setDetailError('');
    setMessagesPagination(null);
    setMessagesLoadingOlder(false);
    setDraftMessage('');
    setSendError('');
  }, []);

  const removeConversationFromList = useCallback(
    (conversationId) => {
      const id = Number.parseInt(String(conversationId ?? ''), 10);
      if (id <= 0) {
        return;
      }

      setConversations((previous) =>
        previous.filter((conversation) => Number(conversation.id) !== id)
      );

      if (Number(selectedConversationIdRef.current) === id) {
        clearConversationSelection();
      }
    },
    [clearConversationSelection]
  );

  const loadConversations = useCallback(
    async ({ page = 1, append = false, silent = false } = {}) => {
      if (!enabled) {
        return;
      }

      const normalizedPage = Math.max(1, Number.parseInt(String(page ?? ''), 10) || 1);

      if (append) {
        setConversationsLoadingMore(true);
      } else if (!silent) {
        setConversationsLoading(true);
      }

      if (!append) {
        setConversationsError('');
      }

      try {
        const response = await listInternalAiConversations({
          page: normalizedPage,
          perPage: CONVERSATIONS_PER_PAGE,
          companyId,
        });

        setConversations((previous) => {
          if (!append || normalizedPage <= 1) {
            return response.conversations ?? [];
          }

          const merged = new Map(previous.map((conversation) => [Number(conversation.id), conversation]));
          (response.conversations ?? []).forEach((conversation) => {
            merged.set(Number(conversation.id), {
              ...(merged.get(Number(conversation.id)) ?? {}),
              ...conversation,
            });
          });

          return sortInternalAiConversationsByActivity(Array.from(merged.values()));
        });

        if (response.pagination) {
          setConversationsPagination((previous) => {
            if (!append || !previous) {
              return response.pagination;
            }

            return {
              ...response.pagination,
              current_page: Math.max(
                Number(previous.current_page ?? 1),
                Number(response.pagination.current_page ?? 1)
              ),
            };
          });
        }
      } catch (requestError) {
        if (!append) {
          setConversationsError(
            parseRequestErrorMessage(requestError, {
              fallback422: 'Nao foi possivel carregar a lista de conversas.',
              fallback404: 'Nao foi possivel localizar as conversas.',
              fallbackUnexpected: 'Falha ao carregar conversas do chat interno com IA.',
            })
          );
        }
      } finally {
        if (append) {
          setConversationsLoadingMore(false);
        } else if (!silent) {
          setConversationsLoading(false);
        }
      }
    },
    [enabled, companyId]
  );

  const openConversation = useCallback(
    async (conversationId, { silent = false } = {}) => {
      if (!enabled) {
        return;
      }

      const id = Number.parseInt(String(conversationId ?? ''), 10);
      if (id <= 0) {
        return;
      }

      setSelectedConversationId(id);
      setDetailError('');
      setMessagesPagination(null);
      setMessagesLoadingOlder(false);
      setSendError('');
      setDraftMessage('');
      if (!silent) {
        setDetailLoading(true);
        setDetail(null);
      }

      try {
        const response = await getInternalAiConversation({
          conversationId: id,
          messagesPerPage: MESSAGES_PER_PAGE,
          companyId,
        });

        if (!response.conversation) {
          throw new Error('Conversa nao encontrada.');
        }

        shouldScrollToBottomRef.current = true;
        wasChatNearBottomRef.current = true;
        setDetail(response.conversation);
        setMessagesPagination(response.messagesPagination ?? null);
        setConversations((previous) =>
          upsertInternalAiConversationInList(previous, response.conversation)
        );
      } catch (requestError) {
        const status = toStatusCode(requestError);
        if (status === 404) {
          removeConversationFromList(id);
        }

        setDetail(null);
        setDetailError(
          parseRequestErrorMessage(requestError, {
            fallback422: 'Nao foi possivel abrir a conversa selecionada.',
            fallback404: 'Conversa nao encontrada para seu usuario.',
            fallbackUnexpected: 'Falha ao carregar detalhes da conversa.',
          })
        );
      } finally {
        if (!silent) {
          setDetailLoading(false);
        }
      }
    },
    [enabled, removeConversationFromList]
  );

  const reloadSelectedConversation = useCallback(async () => {
    const selectedId = Number(selectedConversationIdRef.current ?? 0);
    if (selectedId <= 0) {
      return;
    }

    await openConversation(selectedId, { silent: true });
  }, [openConversation]);

  const loadOlderMessages = useCallback(async () => {
    const conversationId = Number(selectedConversationIdRef.current ?? 0);
    if (conversationId <= 0) {
      return;
    }

    const currentPage = Number(messagesPagination?.current_page ?? 1);
    if (currentPage <= 1 || messagesLoadingOlder) {
      return;
    }

    setMessagesLoadingOlder(true);

    try {
      const response = await getInternalAiConversation({
        conversationId,
        messagesPage: currentPage - 1,
        messagesPerPage: MESSAGES_PER_PAGE,
        companyId,
      });

      if (!response.conversation) {
        return;
      }

      setDetail((previous) => {
        if (!previous || Number(previous.id) !== conversationId) {
          return previous;
        }

        return {
          ...previous,
          ...response.conversation,
          messages: mergeMessagesById(
            response.conversation.messages ?? [],
            previous.messages ?? []
          ),
        };
      });
      setMessagesPagination(response.messagesPagination ?? null);
    } catch (_requestError) {
    } finally {
      setMessagesLoadingOlder(false);
    }
  }, [messagesLoadingOlder, messagesPagination]);

  const sendMessage = useCallback(async () => {
    const conversationId = Number(selectedConversationIdRef.current ?? 0);
    if (!canRequestMessageSend({ conversationId, sendBusy })) {
      return;
    }

    const content = String(draftMessage ?? '').trim();
    if (!content) {
      setSendError('Informe uma mensagem para enviar.');
      return;
    }

    setSendBusy(true);
    setSendError('');

    try {
      const response = await sendInternalAiConversationMessage({
        conversationId,
        content,
        companyId,
      });

      setDraftMessage('');
      shouldScrollToBottomRef.current = true;
      wasChatNearBottomRef.current = true;

      if (response.conversation) {
        setConversations((previous) =>
          upsertInternalAiConversationInList(previous, response.conversation)
        );
      }

      setDetail((previous) => {
        if (!previous || Number(previous.id) !== conversationId) {
          return previous;
        }

        const nextMessages = mergeMessagesById(previous.messages ?? [], [
          response.userMessage,
          response.assistantMessage,
        ]);

        return {
          ...previous,
          ...(response.conversation ?? {}),
          messages: nextMessages,
          last_message: response.assistantMessage ?? response.conversation?.last_message ?? null,
          last_message_at:
            response.assistantMessage?.created_at ??
            response.conversation?.last_message_at ??
            previous.last_message_at,
        };
      });
    } catch (requestError) {
      const status = toStatusCode(requestError);
      if (status === 404) {
        removeConversationFromList(conversationId);
      }

      setSendError(
        parseRequestErrorMessage(requestError, {
          fallback422: 'Nao foi possivel enviar a mensagem para IA.',
          fallback404: 'Conversa nao encontrada para seu usuario.',
          fallbackUnexpected: 'Falha ao enviar mensagem.',
        })
      );
    } finally {
      setSendBusy(false);
    }
  }, [draftMessage, removeConversationFromList, sendBusy]);

  const createConversation = useCallback(async () => {
    if (!enabled || createBusy) {
      return;
    }

    setCreateBusy(true);
    setCreateError('');

    try {
      const response = await createInternalAiConversation({ companyId });
      if (!response.conversation?.id) {
        throw new Error('Conversa nao retornada pela API.');
      }

      setConversations((previous) =>
        upsertInternalAiConversationInList(previous, response.conversation)
      );
      await openConversation(response.conversation.id);
      void loadConversations({ silent: true });
    } catch (requestError) {
      setCreateError(
        parseRequestErrorMessage(requestError, {
          fallback422: 'Nao foi possivel criar uma nova conversa de IA.',
          fallback404: 'Endpoint de conversa nao encontrado.',
          fallbackUnexpected: 'Falha ao criar conversa interna com IA.',
        })
      );
    } finally {
      setCreateBusy(false);
    }
  }, [createBusy, enabled, loadConversations, openConversation]);

  const handleChatScroll = useCallback((event) => {
    const element = event.currentTarget;
    const remaining = element.scrollHeight - element.scrollTop - element.clientHeight;
    wasChatNearBottomRef.current = remaining <= 72;
  }, []);

  useEffect(() => {
    if (!enabled) {
      setConversations([]);
      setConversationsPagination(null);
      clearConversationSelection();
      setConversationsError('');
      setCreateError('');
      return;
    }

    void loadConversations();
  }, [clearConversationSelection, enabled, loadConversations]);

  useEffect(() => {
    const chatElement = chatListRef.current;
    if (!chatElement || !detail?.id) {
      return;
    }

    if (shouldScrollToBottomRef.current || wasChatNearBottomRef.current) {
      shouldScrollToBottomRef.current = false;
      requestAnimationFrame(() => {
        const element = chatListRef.current;
        if (!element) {
          return;
        }
        element.scrollTop = element.scrollHeight;
        wasChatNearBottomRef.current = true;
      });
    }
  }, [detail?.id, detail?.messages?.length]);

  const hasMoreConversations =
    Number(conversationsPagination?.current_page ?? 1) < Number(conversationsPagination?.last_page ?? 1);

  const hasOlderMessages =
    Number(messagesPagination?.current_page ?? 1) > 1;

  const loadMoreConversations = useCallback(async () => {
    if (!hasMoreConversations || conversationsLoadingMore) {
      return;
    }

    const nextPage = Number(conversationsPagination?.current_page ?? 1) + 1;
    await loadConversations({ page: nextPage, append: true });
  }, [conversationsLoadingMore, conversationsPagination?.current_page, hasMoreConversations, loadConversations]);

  return {
    chatListRef,
    conversations,
    conversationsError,
    conversationsLoading,
    conversationsLoadingMore,
    conversationsPagination,
    createBusy,
    createConversation,
    createError,
    detail,
    detailError,
    detailLoading,
    draftMessage,
    hasMoreConversations,
    hasOlderMessages,
    loadConversations,
    loadMoreConversations,
    loadOlderMessages,
    messagesLoadingOlder,
    messagesPagination,
    openConversation,
    reloadSelectedConversation,
    selectedConversationId,
    sendBusy,
    sendError,
    sendMessage,
    setDraftMessage,
    handleChatScroll,
  };
}
