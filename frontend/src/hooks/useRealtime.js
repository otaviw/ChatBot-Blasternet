import { useMemo } from 'react';
import realtimeClient from '@/services/realtimeClient';

function useRealtime() {
  return useMemo(
    () => ({
      on: (eventName, handler) => realtimeClient.on(eventName, handler),
      off: (eventName, handler) => realtimeClient.off(eventName, handler),
      ensureConnected: (forceRefresh = false) => realtimeClient.ensureConnected(forceRefresh),
      joinConversation: (conversationId) => realtimeClient.joinConversation(conversationId),
      leaveConversation: (conversationId) => realtimeClient.leaveConversation(conversationId),
      joinChatConversation: (conversationId) => realtimeClient.joinChatConversation(conversationId),
      leaveChatConversation: (conversationId) => realtimeClient.leaveChatConversation(conversationId),
      disconnect: () => realtimeClient.disconnect(),
    }),
    []
  );
}

export default useRealtime;
