import { useCallback, useEffect, useRef, useState } from 'react';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import realtimeClient from '@/services/realtimeClient';
import {
  getInternalChatConversation,
  markInternalChatConversationRead,
  upsertConversationInList,
} from '@/services/internalChatService';
import { parseErrorMessage } from '../internalChatUtils';

const MSG_PER_PAGE = 25;

const toTimestamp = (value) => {
  if (!value) {
    return 0;
  }

  const timestamp = new Date(value).getTime();
  return Number.isFinite(timestamp) ? timestamp : 0;
};

const mergeMessagesChronologically = (olderMessages, newerMessages) => {
  const merged = [];

  [...(olderMessages ?? []), ...(newerMessages ?? [])].forEach((message) => {
    merged.push(message);
  });

  const byId = new Map();
  merged.forEach((message) => {
    const messageId = Number.parseInt(String(message?.id ?? ''), 10);
    if (!messageId) {
      return;
    }

    const existing = byId.get(messageId) ?? {};
    byId.set(messageId, {
      ...existing,
      ...message,
      attachments: message?.attachments ?? existing.attachments ?? [],
      metadata: message?.metadata ?? existing.metadata ?? {},
    });
  });

  return Array.from(byId.values()).sort((left, right) => {
    const leftTimestamp = toTimestamp(left?.created_at);
    const rightTimestamp = toTimestamp(right?.created_at);

    if (leftTimestamp !== rightTimestamp) {
      return leftTimestamp - rightTimestamp;
    }

    return Number(left?.id ?? 0) - Number(right?.id ?? 0);
  });
};

export default function useInternalChatDetail({
  authenticated,
  markReadByReference,
  role,
  setConversations,
}) {
  const [selectedConversationId, setSelectedConversationId] = useState(null);
  const [messagesPagination, setMessagesPagination] = useState(null);
  const [messagesLoadingOlder, setMessagesLoadingOlder] = useState(false);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [sidebarVisibleOnMobile, setSidebarVisibleOnMobile] = useState(true);
  const selectedConversationIdRef = useRef(null);
  const chatListRef = useRef(null);
  const pendingMessageScrollRestoreRef = useRef(null);
  const shouldScrollToBottomRef = useRef(false);
  const wasChatNearBottomRef = useRef(true);

  useEffect(() => {
    selectedConversationIdRef.current = selectedConversationId;
  }, [selectedConversationId]);

  const loadOlderMessages = useCallback(async () => {
    const conversationId = selectedConversationIdRef.current;
    const currentPage = Number(messagesPagination?.current_page ?? 1);

    if (!conversationId || messagesLoadingOlder || currentPage <= 1) {
      return;
    }

    const previousMetrics = chatListRef.current
      ? {
          conversationId,
          scrollHeight: chatListRef.current.scrollHeight,
          scrollTop: chatListRef.current.scrollTop,
        }
      : null;

    setMessagesLoadingOlder(true);

    try {
      const response = await getInternalChatConversation({
        role,
        conversationId,
        messagesPage: currentPage - 1,
        messagesPerPage: MSG_PER_PAGE,
      });

      if (
        !response.conversation ||
        Number(selectedConversationIdRef.current) !== Number(conversationId)
      ) {
        return;
      }

      pendingMessageScrollRestoreRef.current = previousMetrics;
      setDetail((previous) => {
        if (!previous || Number(previous.id) !== Number(conversationId)) {
          return previous;
        }

        return {
          ...previous,
          ...response.conversation,
          messages: mergeMessagesChronologically(
            response.conversation.messages ?? [],
            previous.messages ?? []
          ),
          unread_count: 0,
        };
      });

      setMessagesPagination(response.messagesPagination ?? null);
    } catch (_requestError) {
      // Falha ao carregar mensagens antigas nao deve interromper a conversa atual.
    } finally {
      setMessagesLoadingOlder(false);
    }
  }, [messagesLoadingOlder, messagesPagination, role]);

  const loadMessagesPage = useCallback(
    async (page) => {
      if (page < Number(messagesPagination?.current_page ?? 1)) {
        await loadOlderMessages();
      }
    },
    [loadOlderMessages, messagesPagination]
  );

  const openConversation = useCallback(
    async (conversationId) => {
      const id = Number.parseInt(String(conversationId ?? ''), 10);
      if (!id || !authenticated) {
        return;
      }

      const previousId = selectedConversationIdRef.current;
      if (previousId && previousId !== id) {
        realtimeClient.leaveChatConversation(previousId);
      }

      setSidebarVisibleOnMobile(false);
      setSelectedConversationId(id);
      setDetailLoading(true);
      setDetailError('');
      setMessagesPagination(null);
      setMessagesLoadingOlder(false);
      pendingMessageScrollRestoreRef.current = null;

      try {
        const response = await getInternalChatConversation({
          role,
          conversationId: id,
          messagesPerPage: MSG_PER_PAGE,
        });
        const conversation = response.conversation;

        if (!conversation) {
          throw new Error('Conversa nao encontrada.');
        }

        shouldScrollToBottomRef.current = true;
        wasChatNearBottomRef.current = true;
        setDetail(conversation);
        setMessagesPagination(response.messagesPagination ?? null);
        setConversations((previous) =>
          upsertConversationInList(previous, {
            ...conversation,
            unread_count: 0,
          })
        );

        await realtimeClient.joinChatConversation(id);

        try {
          await markInternalChatConversationRead({ role, conversationId: id });
        } catch (_markReadError) {
          // Marcar como lido e best effort.
        }

        try {
          await markReadByReference(
            NOTIFICATION_MODULE.INTERNAL_CHAT,
            NOTIFICATION_REFERENCE_TYPE.CHAT_CONVERSATION,
            id
          );
        } catch (_markReferenceReadError) {
          // Marcacao de notificacao e best effort.
        }
      } catch (requestError) {
        setDetail(null);
        setDetailError(parseErrorMessage(requestError, 'Nao foi possivel abrir a conversa.'));
      } finally {
        setDetailLoading(false);
      }
    },
    [authenticated, markReadByReference, role, setConversations]
  );

  const refreshSelectedConversation = useCallback(async () => {
    const id = selectedConversationIdRef.current;
    if (!id || !authenticated) {
      return;
    }

    try {
      const response = await getInternalChatConversation({
        role,
        conversationId: id,
        messagesPerPage: MSG_PER_PAGE,
      });

      if (!response.conversation || Number(selectedConversationIdRef.current) !== Number(id)) {
        return;
      }

      setDetail((previous) => {
        if (!previous || Number(previous.id) !== Number(id)) {
          return response.conversation;
        }

        return {
          ...response.conversation,
          messages: mergeMessagesChronologically(
            previous.messages ?? [],
            response.conversation.messages ?? []
          ),
        };
      });
      setMessagesPagination((previous) => {
        const incoming = response.messagesPagination ?? null;
        if (!incoming) {
          return previous;
        }

        if (!previous) {
          return incoming;
        }

        return {
          ...incoming,
          current_page: Math.min(
            Number(previous.current_page ?? incoming.current_page),
            Number(incoming.current_page ?? previous.current_page)
          ),
        };
      });
      setConversations((previous) =>
        upsertConversationInList(previous, {
          ...response.conversation,
          unread_count: 0,
        })
      );
    } catch (_requestError) {
      // Refresh de detalhe e apenas para manter consistencia do estado local.
    }
  }, [authenticated, role, setConversations]);

  const closeConversation = useCallback(() => {
    setSidebarVisibleOnMobile(true);
    setSelectedConversationId(null);
    setMessagesPagination(null);
    setMessagesLoadingOlder(false);
    setDetail(null);
    setDetailError('');
    pendingMessageScrollRestoreRef.current = null;

    const id = selectedConversationIdRef.current;
    if (id) {
      realtimeClient.leaveChatConversation(id);
    }
  }, []);

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

  const handleChatScroll = useCallback((event) => {
    const element = event.currentTarget;
    const remaining = element.scrollHeight - element.scrollTop - element.clientHeight;
    wasChatNearBottomRef.current = remaining <= 72;

    if (element.scrollTop <= 80) {
      void loadOlderMessages();
    }
  }, [loadOlderMessages]);

  return {
    chatListRef,
    closeConversation,
    detail,
    detailError,
    detailLoading,
    handleChatScroll,
    loadMessagesPage,
    messagesLoadingOlder,
    messagesPagination,
    openConversation,
    refreshSelectedConversation,
    selectedConversationId,
    selectedConversationIdRef,
    setDetail,
    shouldScrollToBottomRef,
    sidebarVisibleOnMobile,
    wasChatNearBottomRef,
  };
}
