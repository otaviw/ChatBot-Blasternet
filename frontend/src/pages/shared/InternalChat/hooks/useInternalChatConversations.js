import { useCallback, useEffect, useRef, useState } from 'react';
import { listInternalChatConversations } from '@/services/internalChatService';
import { parseErrorMessage } from '../internalChatUtils';

const POLL_CONVERSATIONS_MS = 20000;
const CONV_PER_PAGE = 25;

const toTimestamp = (value) => {
  if (!value) {
    return 0;
  }

  const timestamp = new Date(value).getTime();
  return Number.isFinite(timestamp) ? timestamp : 0;
};

const sortConversationsByActivity = (items) =>
  [...items].sort((left, right) => {
    const leftTimestamp = Math.max(
      toTimestamp(left?.last_message_at),
      toTimestamp(left?.updated_at),
      toTimestamp(left?.created_at)
    );
    const rightTimestamp = Math.max(
      toTimestamp(right?.last_message_at),
      toTimestamp(right?.updated_at),
      toTimestamp(right?.created_at)
    );

    if (rightTimestamp !== leftTimestamp) {
      return rightTimestamp - leftTimestamp;
    }

    return Number(right?.id ?? 0) - Number(left?.id ?? 0);
  });

export default function useInternalChatConversations({ authenticated, role }) {
  const [conversationSearchInput, setConversationSearchInput] = useState('');
  const [conversationSearch, setConversationSearch] = useState('');
  const [conversations, setConversations] = useState([]);
  const [conversationsPagination, setConversationsPagination] = useState(null);
  const [conversationsLoadingMore, setConversationsLoadingMore] = useState(false);
  const [conversationsLoading, setConversationsLoading] = useState(false);
  const [conversationsError, setConversationsError] = useState('');
  const refreshTimerRef = useRef(null);
  const conversationListRef = useRef(null);
  const loadedConversationPageRef = useRef(1);

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
          page: 1,
          perPage: CONV_PER_PAGE,
        });
        const incomingConversations = response.conversations ?? [];
        const incomingPagination = response.pagination ?? null;

        setConversations((previous) => {
          const firstPageConversations = incomingConversations.map((conversation) => {
            const existingConversation = previous.find(
              (item) => Number(item.id) === Number(conversation.id)
            );
            const previousUnread = Number(existingConversation?.unread_count ?? 0);

            return {
              ...(existingConversation ?? {}),
              ...conversation,
              unread_count: Math.max(Number(conversation.unread_count ?? 0), previousUnread),
            };
          });

          if (loadedConversationPageRef.current <= 1 || conversationSearch) {
            return firstPageConversations;
          }

          const merged = new Map(previous.map((item) => [Number(item.id), item]));
          firstPageConversations.forEach((conversation) => {
            const existingConversation = merged.get(Number(conversation.id));
            merged.set(Number(conversation.id), {
              ...(existingConversation ?? {}),
              ...conversation,
              unread_count: Math.max(
                Number(conversation.unread_count ?? 0),
                Number(existingConversation?.unread_count ?? 0)
              ),
            });
          });

          return sortConversationsByActivity(Array.from(merged.values()));
        });

        setConversationsPagination((previous) => {
          if (!incomingPagination) {
            return previous;
          }

          return {
            ...incomingPagination,
            current_page: Math.max(
              loadedConversationPageRef.current,
              Number(incomingPagination.current_page ?? 1)
            ),
          };
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

  const loadMoreConversations = useCallback(async () => {
    if (
      !authenticated ||
      conversationsLoading ||
      conversationsLoadingMore ||
      !conversationsPagination
    ) {
      return;
    }

    const lastPage = Number(conversationsPagination.last_page ?? 1);
    if (loadedConversationPageRef.current >= lastPage) {
      return;
    }

    const nextPage = loadedConversationPageRef.current + 1;
    setConversationsLoadingMore(true);

    try {
      const response = await listInternalChatConversations({
        role,
        search: conversationSearch,
        page: nextPage,
        perPage: CONV_PER_PAGE,
      });
      const incomingConversations = response.conversations ?? [];
      const incomingPagination = response.pagination ?? null;

      setConversations((previous) => {
        const merged = new Map(previous.map((item) => [Number(item.id), item]));

        incomingConversations.forEach((conversation) => {
          const existingConversation = merged.get(Number(conversation.id));
          merged.set(Number(conversation.id), {
            ...(existingConversation ?? {}),
            ...conversation,
            unread_count: Math.max(
              Number(conversation.unread_count ?? 0),
              Number(existingConversation?.unread_count ?? 0)
            ),
          });
        });

        return sortConversationsByActivity(Array.from(merged.values()));
      });

      setConversationsPagination((previous) => ({
        ...(previous ?? {}),
        ...(incomingPagination ?? {}),
        current_page: nextPage,
      }));
      loadedConversationPageRef.current = nextPage;
    } catch (_requestError) {
      
    } finally {
      setConversationsLoadingMore(false);
    }
  }, [
    authenticated,
    conversationSearch,
    conversationsLoading,
    conversationsLoadingMore,
    conversationsPagination,
    role,
  ]);

  const handleConversationsScroll = useCallback(
    (event) => {
      const element = event.currentTarget;
      const remaining = element.scrollHeight - element.scrollTop - element.clientHeight;
      if (remaining <= 120) {
        void loadMoreConversations();
      }
    },
    [loadMoreConversations]
  );

  const handleNextConversationPage = useCallback(() => {
    void loadMoreConversations();
  }, [loadMoreConversations]);

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

    loadedConversationPageRef.current = 1;
    setConversationsLoadingMore(false);
    if (conversationListRef.current) {
      conversationListRef.current.scrollTop = 0;
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
    conversationListRef,
    conversationSearchInput,
    conversations,
    conversationsError,
    conversationsLoading,
    conversationsLoadingMore,
    conversationsPagination,
    handleConversationsScroll,
    handleNextConversationPage,
    loadedConversationPageRef,
    loadConversations,
    scheduleConversationsRefresh,
    setConversationSearchInput,
    setConversations,
  };
}
