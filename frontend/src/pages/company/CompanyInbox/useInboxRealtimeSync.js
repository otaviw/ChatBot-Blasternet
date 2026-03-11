import { useEffect, useRef } from 'react';
import realtimeClient from '@/services/realtimeClient';
import {
  appendUniqueMessage,
  buildRealtimeMessage,
  buildRealtimeMessageReactionsPatch,
  buildRealtimeMessageStatusPatch,
  normalizeEventConversation,
  sortConversationsByActivity,
} from './inboxRealtimeUtils';

const parsePositiveInt = (...values) => {
  for (const value of values) {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    if (parsed > 0) {
      return parsed;
    }
  }

  return null;
};

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
      const rawMessage =
        payload?.message && typeof payload.message === 'object' ? payload.message : null;
      const conversationId = parsePositiveInt(
        payload.conversationId,
        payload.conversation_id,
        payload?.conversation?.id,
        payload?.conversation?.conversation_id,
        rawMessage?.conversationId,
        rawMessage?.conversation_id
      );
      const messageId = parsePositiveInt(
        payload.messageId,
        payload.message_id,
        payload.id,
        rawMessage?.id,
        rawMessage?.messageId,
        rawMessage?.message_id
      );
      const eventConversation = normalizeEventConversation(payload.conversation);

      if (conversationId === null) {
        return;
      }

      if (messageId === null) {
        scheduleConversationsRefresh();
        if (Number(selectedIdRef.current) === conversationId) {
          scheduleConversationDetailRefresh(conversationId);
        }
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
            last_message_at:
              payload.createdAt ??
              payload.created_at ??
              eventConversation?.last_message_at ??
              conversation.last_message_at,
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
          last_message_at:
            payload.createdAt ??
            payload.created_at ??
            eventConversation?.last_message_at ??
            prev.last_message_at,
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
      const conversationId = parsePositiveInt(payload.conversationId, payload.conversation_id);
      const eventConversation = normalizeEventConversation(payload.conversation);
      if (conversationId === null) {
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
            assigned_type: payload.toAssignedType ?? payload.to_assigned_type ?? conversation.assigned_type,
            assigned_id: payload.toAssignedId ?? payload.to_assigned_id ?? conversation.assigned_id,
            last_message_at:
              eventConversation?.last_message_at ??
              payload.createdAt ??
              payload.created_at ??
              conversation.last_message_at,
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
            assigned_type: payload.toAssignedType ?? payload.to_assigned_type ?? prev.assigned_type,
            assigned_id: payload.toAssignedId ?? payload.to_assigned_id ?? prev.assigned_id,
          };
        });

        void refreshConversationDetail(conversationId);
      }
    });

    const unsubscribeMessageStatusUpdated = realtimeClient.on('message.status.updated', (envelope) => {
      const payload = envelope?.payload ?? {};
      const conversationId = parsePositiveInt(payload.conversationId, payload.conversation_id);
      const messageId = parsePositiveInt(payload.messageId, payload.message_id, payload.id);
      if (conversationId === null || messageId === null) {
        return;
      }

      if (Number(selectedIdRef.current) !== conversationId) {
        return;
      }

      const statusPatch = buildRealtimeMessageStatusPatch(payload, conversationId, messageId);
      setDetail((prev) => {
        if (!prev || Number(prev.id) !== conversationId) {
          return prev;
        }

        let hasMatch = false;
        const nextMessages = (prev.messages ?? []).map((message) => {
          if (Number(message.id) !== messageId) {
            return message;
          }

          hasMatch = true;
          return {
            ...message,
            ...statusPatch,
          };
        });

        if (!hasMatch) {
          return prev;
        }

        return {
          ...prev,
          messages: nextMessages,
        };
      });
    });

    const unsubscribeMessageReactionsUpdated = realtimeClient.on('message.reactions.updated', (envelope) => {
      const payload = envelope?.payload ?? {};
      const conversationId = parsePositiveInt(payload.conversationId, payload.conversation_id);
      const messageId = parsePositiveInt(payload.messageId, payload.message_id, payload.id);
      if (conversationId === null || messageId === null) {
        return;
      }

      if (Number(selectedIdRef.current) !== conversationId) {
        return;
      }

      const reactionsPatch = buildRealtimeMessageReactionsPatch(payload, conversationId, messageId);
      setDetail((prev) => {
        if (!prev || Number(prev.id) !== conversationId) {
          return prev;
        }

        let hasMatch = false;
        const nextMessages = (prev.messages ?? []).map((message) => {
          if (Number(message.id) !== messageId) {
            return message;
          }

          hasMatch = true;
          return {
            ...message,
            reactions: reactionsPatch.reactions,
          };
        });

        if (!hasMatch) {
          return prev;
        }

        return {
          ...prev,
          messages: nextMessages,
        };
      });
    });

    return () => {
      unsubscribeMessageCreated();
      unsubscribeConversationTransferred();
      unsubscribeMessageStatusUpdated();
      unsubscribeMessageReactionsUpdated();
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
