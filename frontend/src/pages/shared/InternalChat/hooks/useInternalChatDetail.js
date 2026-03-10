import { useCallback, useEffect, useRef, useState } from 'react';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import realtimeClient from '@/services/realtimeClient';
import {
  getInternalChatConversation,
  markInternalChatConversationRead,
  upsertConversationInList,
} from '@/services/internalChatService';
import { parseErrorMessage } from '../internalChatUtils';

export default function useInternalChatDetail({
  authenticated,
  markReadByReference,
  role,
  setConversations,
}) {
  const [selectedConversationId, setSelectedConversationId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [sidebarVisibleOnMobile, setSidebarVisibleOnMobile] = useState(true);
  const selectedConversationIdRef = useRef(null);
  const chatListRef = useRef(null);
  const shouldScrollToBottomRef = useRef(false);
  const wasChatNearBottomRef = useRef(true);

  useEffect(() => {
    selectedConversationIdRef.current = selectedConversationId;
  }, [selectedConversationId]);

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

      try {
        const response = await getInternalChatConversation({
          role,
          conversationId: id,
        });
        const conversation = response.conversation;

        if (!conversation) {
          throw new Error('Conversa nao encontrada.');
        }

        shouldScrollToBottomRef.current = true;
        wasChatNearBottomRef.current = true;
        setDetail(conversation);
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
      });

      if (!response.conversation || Number(selectedConversationIdRef.current) !== Number(id)) {
        return;
      }

      setDetail(response.conversation);
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
    setDetail(null);
    setDetailError('');

    const id = selectedConversationIdRef.current;
    if (id) {
      realtimeClient.leaveChatConversation(id);
    }
  }, []);

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

  const handleChatScroll = useCallback((event) => {
    const element = event.currentTarget;
    const remaining = element.scrollHeight - element.scrollTop - element.clientHeight;
    wasChatNearBottomRef.current = remaining <= 72;
  }, []);

  return {
    chatListRef,
    closeConversation,
    detail,
    detailError,
    detailLoading,
    handleChatScroll,
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
