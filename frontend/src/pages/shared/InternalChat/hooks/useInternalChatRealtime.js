import { useEffect } from 'react';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import { REALTIME_EVENTS } from '@/constants/realtimeEvents';
import realtimeClient from '@/services/realtimeClient';
import {
  appendUniqueChatMessage,
  markInternalChatConversationRead,
  normalizeRealtimeInternalChatMessage,
  upsertConversationInList,
} from '@/services/internalChatService';

export default function useInternalChatRealtime({
  authenticated,
  loadConversations,
  markReadByReference,
  role,
  scheduleConversationsRefresh,
  selectedConversationId,
  selectedConversationIdRef,
  setConversations,
  setDetail,
  shouldScrollToBottomRef,
  wasChatNearBottomRef,
}) {
  useEffect(() => {
    if (!authenticated) {
      return undefined;
    }

    const applyRealtimeMessage = (envelope, { incrementUnread = false } = {}) => {
      const message = normalizeRealtimeInternalChatMessage(envelope?.payload);
      if (!message?.conversation_id) {
        return;
      }

      const conversationId = Number(message.conversation_id);
      const isCurrentConversation = Number(selectedConversationIdRef.current) === conversationId;
      const activityDate = message.updated_at ?? message.created_at ?? null;

      if (!isCurrentConversation) {
        setConversations((previous) => {
          const existingConversation = previous.find(
            (conversation) => Number(conversation.id) === conversationId
          );
          const previousUnread = Number(existingConversation?.unread_count ?? 0);
          const previousLastMessageId = Number(existingConversation?.last_message?.id ?? 0);
          const incomingMessageId = Number(message.id ?? 0);
          const shouldReplaceLastMessage =
            incrementUnread || !existingConversation || previousLastMessageId === incomingMessageId;

          return upsertConversationInList(previous, {
            id: conversationId,
            last_message: shouldReplaceLastMessage
              ? message
              : existingConversation?.last_message ?? null,
            last_message_at: shouldReplaceLastMessage
              ? activityDate
              : existingConversation?.last_message_at ?? activityDate,
            unread_count: incrementUnread ? previousUnread + 1 : previousUnread,
          });
        });

        scheduleConversationsRefresh(() => {
          void loadConversations({ silent: true });
        });
        return;
      }

      if (incrementUnread) {
        shouldScrollToBottomRef.current = true;
        wasChatNearBottomRef.current = true;
      }

      setDetail((previous) => {
        if (!previous || Number(previous.id) !== conversationId) {
          return previous;
        }

        const previousLastMessageId = Number(previous.last_message?.id ?? 0);
        const incomingMessageId = Number(message.id ?? 0);
        const shouldReplaceLastMessage =
          !previousLastMessageId || previousLastMessageId === incomingMessageId || incrementUnread;

        return {
          ...previous,
          messages: appendUniqueChatMessage(previous.messages ?? [], message),
          last_message: shouldReplaceLastMessage ? message : previous.last_message,
          last_message_at: shouldReplaceLastMessage
            ? activityDate ?? previous.last_message_at
            : previous.last_message_at,
          unread_count: 0,
        };
      });

      setConversations((previous) =>
        upsertConversationInList(previous, {
          id: conversationId,
          last_message: message,
          last_message_at: activityDate,
          unread_count: 0,
        })
      );

      if (incrementUnread) {
        void markInternalChatConversationRead({
          role,
          conversationId,
        }).catch(() => {});

        void markReadByReference(
          NOTIFICATION_MODULE.INTERNAL_CHAT,
          NOTIFICATION_REFERENCE_TYPE.CHAT_CONVERSATION,
          conversationId
        ).catch(() => {});
      }
    };

    const unsubscribeCreated = realtimeClient.on(REALTIME_EVENTS.MESSAGE_CREATED, (envelope) => {
      applyRealtimeMessage(envelope, { incrementUnread: true });
    });

    const unsubscribeUpdated = realtimeClient.on(REALTIME_EVENTS.MESSAGE_UPDATED, (envelope) => {
      applyRealtimeMessage(envelope, { incrementUnread: false });
    });

    return () => {
      unsubscribeCreated();
      unsubscribeUpdated();
      if (selectedConversationId) {
        realtimeClient.leaveChatConversation(selectedConversationId);
      }
    };
  }, [
    authenticated,
    loadConversations,
    markReadByReference,
    role,
    scheduleConversationsRefresh,
    selectedConversationId,
    selectedConversationIdRef,
    setConversations,
    setDetail,
    shouldScrollToBottomRef,
    wasChatNearBottomRef,
  ]);
}
