import { useCallback, useEffect, useRef, useState } from 'react';
import api from '@/services/api';
import realtimeClient from '@/services/realtimeClient';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import { mergeMessagesChronologically } from '../inboxRealtimeUtils';

const EMPTY_TRANSFER_OPTIONS = { areas: [], users: [] };
const MSG_PER_PAGE = 25;

export default function useCompanyInboxDetailMessages({
  data,
  loading,
  markReadByReference,
}) {
  const [selectedId, setSelectedId] = useState(null);
  const [messagesPagination, setMessagesPagination] = useState(null);
  const [messagesLoadingOlder, setMessagesLoadingOlder] = useState(false);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [transferOptions, setTransferOptions] = useState(EMPTY_TRANSFER_OPTIONS);
  const [contactNameInput, setContactNameInput] = useState('');
  const selectedIdRef = useRef(null);
  const queryConversationHandledRef = useRef(false);
  const chatListRef = useRef(null);
  const pendingMessageScrollRestoreRef = useRef(null);
  const shouldScrollChatToBottomRef = useRef(false);
  const wasChatNearBottomRef = useRef(true);

  useEffect(() => {
    selectedIdRef.current = selectedId;
  }, [selectedId]);

  const touchConversationPresence = useCallback(async (conversationId) => {
    const id = Number.parseInt(String(conversationId), 10);
    if (!id) {
      return;
    }

    try {
      await api.post(`/realtime/conversations/${id}/presence`);
    } catch (_error) {
    }
  }, []);

  const clearConversationPresence = useCallback(async (conversationId) => {
    const id = Number.parseInt(String(conversationId), 10);
    if (!id) {
      return;
    }

    try {
      await api.delete(`/realtime/conversations/${id}/presence`);
    } catch (_error) {
    }
  }, []);

  const loadOlderMessages = useCallback(async () => {
    const id = selectedIdRef.current;
    const currentPage = Number(messagesPagination?.current_page ?? 1);

    if (!id || messagesLoadingOlder || currentPage <= 1) return;

    const previousMetrics = chatListRef.current
      ? {
          conversationId: id,
          scrollHeight: chatListRef.current.scrollHeight,
          scrollTop: chatListRef.current.scrollTop,
        }
      : null;

    setMessagesLoadingOlder(true);

    try {
      const response = await api.get(
        `/minha-conta/conversas/${id}?messages_page=${currentPage - 1}&messages_per_page=${MSG_PER_PAGE}`
      );
      const conversation = response.data?.conversation ?? null;
      if (!conversation || Number(selectedIdRef.current) !== id) return;

      pendingMessageScrollRestoreRef.current = previousMetrics;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...conversation,
        messages: mergeMessagesChronologically(conversation.messages ?? [], prev?.messages ?? []),
        transfer_history: response.data?.transfer_history ?? prev?.transfer_history ?? [],
      }));
      setMessagesPagination(response.data?.messages_pagination ?? null);
    } catch (_err) {
    } finally {
      setMessagesLoadingOlder(false);
    }
  }, [messagesLoadingOlder, messagesPagination]);

  const loadMessagesPage = useCallback(
    async (page) => {
      if (page < Number(messagesPagination?.current_page ?? 1)) {
        await loadOlderMessages();
      }
    },
    [loadOlderMessages, messagesPagination]
  );

  const refreshConversationDetail = useCallback(async (conversationId) => {
    const id = Number.parseInt(String(conversationId), 10);
    if (!id) return;

    try {
      const response = await api.get(`/minha-conta/conversas/${id}`);
      const conversation = response.data?.conversation ?? null;
      if (!conversation || Number(selectedIdRef.current) !== id) return;

      setDetail((prev) => ({
        ...(prev ?? {}),
        ...conversation,
        messages: mergeMessagesChronologically(prev?.messages ?? [], conversation.messages ?? []),
        transfer_history: response.data?.transfer_history ?? prev?.transfer_history ?? [],
      }));
      setMessagesPagination((prev) => {
        const incoming = response.data?.messages_pagination ?? null;
        if (!incoming) {
          return prev;
        }

        if (!prev) {
          return incoming;
        }

        return {
          ...incoming,
          current_page: Math.min(
            Number(prev.current_page ?? incoming.current_page),
            Number(incoming.current_page ?? prev.current_page)
          ),
        };
      });
      setTransferOptions(response.data?.transfer_options ?? EMPTY_TRANSFER_OPTIONS);
      setContactNameInput(conversation.customer_name ?? '');
    } catch (_error) {
    }
  }, []);

  const openConversation = useCallback(
    async (conversationId) => {
      const previousSelected = selectedIdRef.current;
      if (previousSelected && Number(previousSelected) !== Number(conversationId)) {
        realtimeClient.leaveConversation(previousSelected);
        void clearConversationPresence(previousSelected);
      }

      setSelectedId(conversationId);
      setDetailLoading(true);
      setDetailError('');
      setDetail(null);
      setTransferOptions(EMPTY_TRANSFER_OPTIONS);
      setContactNameInput('');
      pendingMessageScrollRestoreRef.current = null;
      shouldScrollChatToBottomRef.current = true;
      wasChatNearBottomRef.current = true;

      try {
        const response = await api.get(`/minha-conta/conversas/${conversationId}`);
        const conversation = response.data?.conversation ?? null;
        setDetail(
          conversation
            ? {
                ...conversation,
                transfer_history: response.data?.transfer_history ?? [],
              }
            : null
        );
        setMessagesPagination(response.data?.messages_pagination ?? null);
        setContactNameInput(conversation?.customer_name ?? '');
        setTransferOptions(response.data?.transfer_options ?? EMPTY_TRANSFER_OPTIONS);
        await realtimeClient.joinConversation(conversationId);
        await touchConversationPresence(conversationId);
        await markReadByReference(
          NOTIFICATION_MODULE.INBOX,
          NOTIFICATION_REFERENCE_TYPE.CONVERSATION,
          conversationId
        );
      } catch (err) {
        setDetailError(err.response?.data?.message || 'Falha ao carregar conversa.');
      } finally {
        setDetailLoading(false);
      }
    },
    [clearConversationPresence, markReadByReference, touchConversationPresence]
  );

  useEffect(() => {
    if (queryConversationHandledRef.current || loading || !data?.authenticated) {
      return;
    }

    const params = new URLSearchParams(window.location.search);
    const conversationId = Number.parseInt(String(params.get('conversationId') ?? ''), 10);
    if (!conversationId) {
      queryConversationHandledRef.current = true;
      return;
    }

    queryConversationHandledRef.current = true;
    void openConversation(conversationId);
  }, [data, loading, openConversation]);

  useEffect(() => {
    if (!selectedId) {
      return undefined;
    }

    void touchConversationPresence(selectedId);
    const heartbeat = setInterval(() => {
      void touchConversationPresence(selectedId);
    }, 15000);

    return () => {
      clearInterval(heartbeat);
      void clearConversationPresence(selectedId);
    };
  }, [clearConversationPresence, selectedId, touchConversationPresence]);

  const handleChatScroll = useCallback(
    (event) => {
      const element = event.currentTarget;
      const remaining = element.scrollHeight - element.scrollTop - element.clientHeight;
      wasChatNearBottomRef.current = remaining <= 72;

      if (element.scrollTop <= 80) {
        void loadOlderMessages();
      }
    },
    [loadOlderMessages]
  );

  useEffect(() => {
    const chatElement = chatListRef.current;
    const pendingRestore = pendingMessageScrollRestoreRef.current;

    if (!chatElement || !detail?.id) {
      return;
    }

    if (pendingRestore && Number(pendingRestore.conversationId) === Number(detail.id)) {
      pendingMessageScrollRestoreRef.current = null;
      requestAnimationFrame(() => {
        const element = chatListRef.current;
        if (!element) {
          return;
        }

        element.scrollTop =
          element.scrollHeight - pendingRestore.scrollHeight + pendingRestore.scrollTop;
        wasChatNearBottomRef.current = false;
      });
      return;
    }

    if (shouldScrollChatToBottomRef.current || wasChatNearBottomRef.current) {
      shouldScrollChatToBottomRef.current = false;
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

  return {
    chatListRef,
    clearConversationPresence,
    contactNameInput,
    detail,
    detailError,
    detailLoading,
    handleChatScroll,
    loadMessagesPage,
    messagesLoadingOlder,
    messagesPagination,
    openConversation,
    refreshConversationDetail,
    selectedId,
    selectedIdRef,
    setContactNameInput,
    setDetail,
    setDetailError,
    setSelectedId,
    shouldScrollChatToBottomRef,
    touchConversationPresence,
    transferOptions,
    wasChatNearBottomRef,
  };
}
