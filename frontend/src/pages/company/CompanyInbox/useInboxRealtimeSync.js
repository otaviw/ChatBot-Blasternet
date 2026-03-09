import { useEffect, useRef } from 'react';
import realtimeClient from '@/services/realtimeClient';
import {
  appendUniqueMessage,
  buildRealtimeMessage,
  normalizeEventConversation,
  sortConversationsByActivity,
} from './inboxRealtimeUtils';

export default function useInboxRealtimeSync({
  clearConversationPresence,
  refreshConversationDetail,
  refreshConversations,
  selectedIdRef,
  setConversations,
  setDetail,
}) {
  const conversationsRefreshTimerRef = useRef(null);
  const detailRefreshTimerRef = useRef(null);

  useEffect(() => {
    const scheduleConversationsRefresh = () => {
      if (conversationsRefreshTimerRef.current) {
        return;
      }

      conversationsRefreshTimerRef.current = setTimeout(() => {
        conversationsRefreshTimerRef.current = null;
        void refreshConversations();
      }, 650);
    };

    const scheduleConversationDetailRefresh = (conversationId) => {
      if (detailRefreshTimerRef.current) {
        clearTimeout(detailRefreshTimerRef.current);
      }

      detailRefreshTimerRef.current = setTimeout(() => {
        detailRefreshTimerRef.current = null;
        void refreshConversationDetail(conversationId);
      }, 550);
    };

    const unsubscribeMessageCreated = realtimeClient.on('message.created', (envelope) => {
      const payload = envelope?.payload ?? {};
      const conversationId = Number.parseInt(String(payload.conversationId ?? ''), 10);
      const messageId = Number.parseInt(String(payload.messageId ?? ''), 10);
      const eventConversation = normalizeEventConversation(payload.conversation);

      if (!conversationId || !messageId) {
        return;
      }

      setConversations((prev) => {
        let found = false;
        const next = prev.map((conversation) => {
          if (Number(conversation.id) !== conversationId) {
            return conversation;
          }

          found = true;
          const merged = {
            ...conversation,
            ...(eventConversation ?? {}),
            last_message_id: messageId,
            last_message_at: payload.createdAt ?? eventConversation?.last_message_at ?? conversation.last_message_at,
          };

          if (!eventConversation || eventConversation.messages_count == null) {
            merged.messages_count = Number(conversation.messages_count ?? 0) + 1;
          }

          return merged;
        });

        if (!found) {
          void refreshConversations();
          return prev;
        }

        return sortConversationsByActivity(next);
      });

      if (Number(selectedIdRef.current) !== conversationId) {
        scheduleConversationsRefresh();
        return;
      }

      const realtimeMessage = buildRealtimeMessage(payload, conversationId, messageId);

      setDetail((prev) => {
        if (!prev || Number(prev.id) !== conversationId) {
          return prev;
        }

        const currentMessages = prev.messages ?? [];
        const nextMessages = appendUniqueMessage(currentMessages, realtimeMessage);
        const messageWasInserted = nextMessages.length !== currentMessages.length;
        const merged = {
          ...prev,
          ...(eventConversation ?? {}),
          messages: nextMessages,
          last_message_id: messageId,
          last_message_at: payload.createdAt ?? eventConversation?.last_message_at ?? prev.last_message_at,
        };

        if ((!eventConversation || eventConversation.messages_count == null) && messageWasInserted) {
          merged.messages_count = Number(prev.messages_count ?? 0) + 1;
        }

        return merged;
      });

      scheduleConversationsRefresh();
      scheduleConversationDetailRefresh(conversationId);
    });

    const unsubscribeConversationTransferred = realtimeClient.on('conversation.transferred', (envelope) => {
      const payload = envelope?.payload ?? {};
      const conversationId = Number.parseInt(String(payload.conversationId ?? ''), 10);
      const eventConversation = normalizeEventConversation(payload.conversation);
      if (!conversationId) {
        return;
      }

      setConversations((prev) => {
        let found = false;
        const next = prev.map((conversation) => {
          if (Number(conversation.id) !== conversationId) {
            return conversation;
          }

          found = true;
          return {
            ...conversation,
            ...(eventConversation ?? {}),
            handling_mode: 'human',
            status: 'in_progress',
            assigned_type: payload.toAssignedType ?? conversation.assigned_type,
            assigned_id: payload.toAssignedId ?? conversation.assigned_id,
            last_message_at:
              eventConversation?.last_message_at ?? payload.createdAt ?? conversation.last_message_at,
          };
        });

        if (!found) {
          void refreshConversations();
          return prev;
        }

        return sortConversationsByActivity(next);
      });

      if (Number(selectedIdRef.current) === conversationId) {
        setDetail((prev) => {
          if (!prev || Number(prev.id) !== conversationId) {
            return prev;
          }

          return {
            ...prev,
            ...(eventConversation ?? {}),
            handling_mode: 'human',
            status: 'in_progress',
            assigned_type: payload.toAssignedType ?? prev.assigned_type,
            assigned_id: payload.toAssignedId ?? prev.assigned_id,
          };
        });

        void refreshConversationDetail(conversationId);
      }
    });

    return () => {
      unsubscribeMessageCreated();
      unsubscribeConversationTransferred();
      if (conversationsRefreshTimerRef.current) {
        clearTimeout(conversationsRefreshTimerRef.current);
        conversationsRefreshTimerRef.current = null;
      }
      if (detailRefreshTimerRef.current) {
        clearTimeout(detailRefreshTimerRef.current);
        detailRefreshTimerRef.current = null;
      }

      if (selectedIdRef.current) {
        realtimeClient.leaveConversation(selectedIdRef.current);
        void clearConversationPresence(selectedIdRef.current);
      }
    };
  }, [
    clearConversationPresence,
    refreshConversationDetail,
    refreshConversations,
    selectedIdRef,
    setConversations,
    setDetail,
  ]);
}
