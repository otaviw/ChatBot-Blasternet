import { useCallback, useEffect, useRef, useState } from 'react';
import api from '@/services/api';
import { sortConversationsByActivity } from '../inboxRealtimeUtils';

const CONV_PER_PAGE = 25;

const EMPTY_FILTERS = {
  status: '',
  area: '',
  attendant_id: '',
  date_from: '',
  date_to: '',
};

export default function useCompanyInboxConversations({ data, loading }) {
  const [, setConvPage] = useState(1);
  const [convSearch, setConvSearch] = useState('');
  const [convSearchInput, setConvSearchInput] = useState('');
  const [conversations, setConversations] = useState([]);
  const [conversationsPagination, setConversationsPagination] = useState(null);
  const [conversationsLoadingMore, setConversationsLoadingMore] = useState(false);
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const conversationListRef = useRef(null);
  const loadedConversationPageRef = useRef(1);

  const buildConversationsUrl = useCallback(
    (page = 1, search = '', activeFilters = EMPTY_FILTERS) => {
      const params = new URLSearchParams();
      params.set('page', String(page));
      params.set('per_page', String(CONV_PER_PAGE));
      if (search) params.set('search', search);
      if (activeFilters.status) params.set('status', activeFilters.status);
      if (activeFilters.area) params.set('area', activeFilters.area);
      if (activeFilters.attendant_id) params.set('attendant_id', activeFilters.attendant_id);
      if (activeFilters.date_from) params.set('date_from', activeFilters.date_from);
      if (activeFilters.date_to) params.set('date_to', activeFilters.date_to);
      return `/minha-conta/conversas?${params.toString()}`;
    },
    []
  );

  useEffect(() => {
    let canceled = false;
    const handle = setTimeout(async () => {
      const search = convSearchInput.trim();
      setConvSearch(search);
      loadedConversationPageRef.current = 1;

      try {
        const response = await api.get(buildConversationsUrl(1, search, filters));
        if (canceled) return;
        const incomingConversations = sortConversationsByActivity(response.data?.conversations ?? []);
        setConversations(incomingConversations);
        setConversationsPagination(response.data?.conversations_pagination ?? null);
        setConversationsLoadingMore(false);
        if (conversationListRef.current) {
          conversationListRef.current.scrollTop = 0;
        }
      } catch (_error) {
        if (canceled) return;
      }
    }, 350);

    return () => {
      canceled = true;
      clearTimeout(handle);
    };
  }, [buildConversationsUrl, convSearchInput, filters]);

  useEffect(() => {
    setConversations(sortConversationsByActivity(data?.conversations ?? []));
    setConversationsPagination(data?.conversations_pagination ?? null);
    setConversationsLoadingMore(false);
    loadedConversationPageRef.current = 1;

    if (conversationListRef.current) {
      conversationListRef.current.scrollTop = 0;
    }
  }, [data]);

  const upsertConversationInList = useCallback((conversation) => {
    if (!conversation?.id) {
      return;
    }

    setConversations((prev) => {
      let found = false;
      const next = prev.map((item) => {
        if (Number(item.id) !== Number(conversation.id)) {
          return item;
        }

        found = true;
        return {
          ...item,
          ...conversation,
        };
      });

      return sortConversationsByActivity(found ? next : [conversation, ...next]);
    });
  }, []);

  const refreshConversations = useCallback(async () => {
    const response = await api.get(buildConversationsUrl(1, convSearch, filters));
    const incomingConversations = sortConversationsByActivity(response.data?.conversations ?? []);
    const incomingPagination = response.data?.conversations_pagination ?? null;

    setConversations((prev) => {
      if (loadedConversationPageRef.current <= 1 || convSearch) {
        return incomingConversations;
      }

      const merged = new Map(prev.map((item) => [Number(item.id), item]));
      incomingConversations.forEach((conversation) => {
        const existing = merged.get(Number(conversation.id));
        merged.set(Number(conversation.id), {
          ...(existing ?? {}),
          ...conversation,
        });
      });

      return sortConversationsByActivity(Array.from(merged.values()));
    });
    setConversationsPagination((prev) => {
      if (!incomingPagination) {
        return prev;
      }

      return {
        ...incomingPagination,
        current_page: Math.max(
          loadedConversationPageRef.current,
          Number(incomingPagination.current_page ?? 1)
        ),
      };
    });
  }, [buildConversationsUrl, convSearch, filters]);

  const loadMoreConversations = useCallback(async () => {
    if (loading || conversationsLoadingMore || !conversationsPagination) {
      return;
    }

    const lastPage = Number(conversationsPagination.last_page ?? 1);
    if (loadedConversationPageRef.current >= lastPage) {
      return;
    }

    const nextPage = loadedConversationPageRef.current + 1;
    setConversationsLoadingMore(true);

    try {
      const response = await api.get(buildConversationsUrl(nextPage, convSearch, filters));
      const incomingConversations = response.data?.conversations ?? [];
      const incomingPagination = response.data?.conversations_pagination ?? null;

      setConversations((prev) => {
        const merged = new Map(prev.map((item) => [Number(item.id), item]));
        incomingConversations.forEach((conversation) => {
          const existing = merged.get(Number(conversation.id));
          merged.set(Number(conversation.id), {
            ...(existing ?? {}),
            ...conversation,
          });
        });

        return sortConversationsByActivity(Array.from(merged.values()));
      });
      setConversationsPagination((prev) => ({
        ...(prev ?? {}),
        ...(incomingPagination ?? {}),
        current_page: nextPage,
      }));
      loadedConversationPageRef.current = nextPage;
    } catch (_error) {
    } finally {
      setConversationsLoadingMore(false);
    }
  }, [buildConversationsUrl, convSearch, filters, conversationsLoadingMore, conversationsPagination, loading]);

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

  const handleConversationsSearchEnter = useCallback(() => {
    setConvSearch(convSearchInput.trim());
    loadedConversationPageRef.current = 1;
  }, [convSearchInput]);

  const handleNextConversationPage = useCallback(() => {
    if (!conversationsPagination) {
      return;
    }

    setConvPage((page) =>
      Math.min(Number(conversationsPagination.last_page ?? page), Number(page) + 1)
    );
  }, [conversationsPagination]);

  return {
    conversationListRef,
    conversations,
    conversationsLoadingMore,
    conversationsPagination,
    convSearchInput,
    filters,
    handleConversationsScroll,
    handleConversationsSearchEnter,
    handleNextConversationPage,
    loadedConversationPageRef,
    refreshConversations,
    setConversations,
    setConvSearchInput,
    setFilters,
    upsertConversationInList,
  };
}
