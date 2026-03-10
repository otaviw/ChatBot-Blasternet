import { useCallback, useEffect, useRef, useState } from 'react';
import { listInternalChatConversations } from '@/services/internalChatService';
import { parseErrorMessage } from '../internalChatUtils';

const POLL_CONVERSATIONS_MS = 20000;

export default function useInternalChatConversations({ authenticated, role }) {
  const [conversationSearchInput, setConversationSearchInput] = useState('');
  const [conversationSearch, setConversationSearch] = useState('');
  const [conversations, setConversations] = useState([]);
  const [conversationsLoading, setConversationsLoading] = useState(false);
  const [conversationsError, setConversationsError] = useState('');
  const refreshTimerRef = useRef(null);

  const scheduleConversationsRefresh = useCallback((callback) => {
    if (refreshTimerRef.current) {
      clearTimeout(refreshTimerRef.current);
    }

    refreshTimerRef.current = setTimeout(() => {
      refreshTimerRef.current = null;
      callback();
    }, 500);
  }, []);

  const loadConversations = useCallback(
    async ({ silent = false } = {}) => {
      if (!authenticated) {
        return;
      }

      if (!silent) {
        setConversationsLoading(true);
      }
      setConversationsError('');

      try {
        const response = await listInternalChatConversations({
          role,
          search: conversationSearch,
        });
        const incoming = response.conversations ?? [];

        setConversations((previous) => {
          const previousById = new Map(
            previous.map((item) => [Number(item.id), Number(item.unread_count ?? 0)])
          );

          return incoming.map((item) => {
            const previousUnread = previousById.get(Number(item.id)) ?? 0;
            return {
              ...item,
              unread_count: Math.max(Number(item.unread_count ?? 0), previousUnread),
            };
          });
        });
      } catch (requestError) {
        setConversationsError(
          parseErrorMessage(
            requestError,
            'Nao foi possivel carregar as conversas de chat interno.'
          )
        );
      } finally {
        if (!silent) {
          setConversationsLoading(false);
        }
      }
    },
    [authenticated, conversationSearch, role]
  );

  useEffect(() => {
    const handle = setTimeout(() => {
      setConversationSearch(conversationSearchInput.trim());
    }, 350);

    return () => clearTimeout(handle);
  }, [conversationSearchInput]);

  useEffect(() => {
    if (!authenticated) {
      return undefined;
    }

    void loadConversations();
    const timer = setInterval(() => {
      void loadConversations({ silent: true });
    }, POLL_CONVERSATIONS_MS);

    return () => {
      clearInterval(timer);
    };
  }, [authenticated, loadConversations]);

  useEffect(() => {
    return () => {
      if (refreshTimerRef.current) {
        clearTimeout(refreshTimerRef.current);
        refreshTimerRef.current = null;
      }
    };
  }, []);

  return {
    conversationSearchInput,
    conversations,
    conversationsError,
    conversationsLoading,
    loadConversations,
    scheduleConversationsRefresh,
    setConversationSearchInput,
    setConversations,
  };
}
