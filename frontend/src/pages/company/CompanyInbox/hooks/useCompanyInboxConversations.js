import { useCallback, useEffect, useRef, useState } from 'react';
import api from '@/services/api';
import { sortConversationsByActivity } from '../inboxRealtimeUtils';

const CONV_PER_PAGE = 25;

const EMPTY_FILTERS = {
  status: '',
  area: '',
  attendant_id: '',
  tag_id: '',
  date_from: '',
  date_to: '',
};

export default function useCompanyInboxConversations({ data, loading }) {
  const [, setConvPage] = useState(1);
  const [convSearchInput, setConvSearchInput] = useState('');
  const [conversations, setConversations] = useState([]);
  const [searchResults, setSearchResults] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [searchLoading, setSearchLoading] = useState(false);
  const [conversationsPagination, setConversationsPagination] = useState(null);
  const [conversationsLoadingMore, setConversationsLoadingMore] = useState(false);
  const [conversationsLoading, setConversationsLoading] = useState(Boolean(loading));
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const conversationListRef = useRef(null);
  const loadedConversationPageRef = useRef(1);

  const buildConversationsUrl = useCallback((page = 1, activeFilters = EMPTY_FILTERS) => {
      const params = new URLSearchParams();
      params.set('page', String(page));
      params.set('per_page', String(CONV_PER_PAGE));
      if (activeFilters.status) params.set('status', activeFilters.status);
      if (activeFilters.area) params.set('area', activeFilters.area);
      if (activeFilters.attendant_id) params.set('attendant_id', activeFilters.attendant_id);
      if (activeFilters.tag_id) params.set('tag_id', activeFilters.tag_id);
      if (activeFilters.date_from) params.set('date_from', activeFilters.date_from);
      if (activeFilters.date_to) params.set('date_to', activeFilters.date_to);
      return `/minha-conta/conversas?${params.toString()}`;
    }, []);

  const buildSearchUrl = useCallback((term, activeFilters = EMPTY_FILTERS) => {
    const params = new URLSearchParams();
    params.set('q', term);
    if (activeFilters.status) params.set('status', activeFilters.status);
    if (activeFilters.date_from) params.set('data_inicio', activeFilters.date_from);
    if (activeFilters.date_to) params.set('data_fim', activeFilters.date_to);
    return `/minha-conta/conversas/buscar?${params.toString()}`;
  }, []);

  useEffect(() => {
    let canceled = false;
    const delay = convSearchInput.trim() === '' ? 0 : 400;
    const handle = setTimeout(async () => {
      const term = convSearchInput.trim();
      setSearchTerm(term);
      loadedConversationPageRef.current = 1;
      setConversationsLoadingMore(false);

      if (term === '') {
        setSearchResults([]);
        setSearchLoading(false);
        setConversationsLoading(true);

        try {
          const response = await api.get(buildConversationsUrl(1, filters));
          if (canceled) return;
          const incomingConversations = sortConversationsByActivity(response.data?.conversations ?? []);
          setConversations(incomingConversations);
          setConversationsPagination(response.data?.conversations_pagination ?? null);
          if (conversationListRef.current) {
            conversationListRef.current.scrollTop = 0;
          }
        } catch (_error) {
          if (canceled) return;
        } finally {
          if (!canceled) setConversationsLoading(false);
        }

        return;
      }

      setSearchLoading(true);
      try {
        const response = await api.get(buildSearchUrl(term, filters));
        if (canceled) return;
        setSearchResults(response.data?.results ?? []);
        if (conversationListRef.current) {
          conversationListRef.current.scrollTop = 0;
        }
      } catch (_error) {
        if (canceled) return;
      } finally {
        if (!canceled) setSearchLoading(false);
      }
    }, delay);

    return () => {
      canceled = true;
      clearTimeout(handle);
    };
  }, [buildConversationsUrl, buildSearchUrl, convSearchInput, filters]);

  useEffect(() => {
    setConversations(sortConversationsByActivity(data?.conversations ?? []));
    setConversationsPagination(data?.conversations_pagination ?? null);
    setConversationsLoadingMore(false);
    setConversationsLoading(false);
    loadedConversationPageRef.current = 1;

    if (conversationListRef.current) {
      conversationListRef.current.scrollTop = 0;
    }
  }, [data]);

  useEffect(() => {
    if (loading) {
      setConversationsLoading(true);
    }
  }, [loading]);

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
    const isSearching = searchTerm !== '';
    if (isSearching) {
      setSearchLoading(true);
    } else {
      setConversationsLoading(true);
    }

    try {
      if (isSearching) {
        const response = await api.get(buildSearchUrl(searchTerm, filters));
        setSearchResults(response.data?.results ?? []);
      } else {
        const response = await api.get(buildConversationsUrl(1, filters));
        const incomingConversations = sortConversationsByActivity(response.data?.conversations ?? []);
        const incomingPagination = response.data?.conversations_pagination ?? null;

        setConversations((prev) => {
          if (loadedConversationPageRef.current <= 1) {
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
      }
    } finally {
      if (isSearching) {
        setSearchLoading(false);
      } else {
        setConversationsLoading(false);
      }
    }
  }, [buildConversationsUrl, buildSearchUrl, searchTerm, filters]);

  const loadMoreConversations = useCallback(async () => {
    if (searchTerm !== '' || loading || conversationsLoadingMore || !conversationsPagination) {
      return;
    }

    const lastPage = Number(conversationsPagination.last_page ?? 1);
    if (loadedConversationPageRef.current >= lastPage) {
      return;
    }

    const nextPage = loadedConversationPageRef.current + 1;
    setConversationsLoadingMore(true);

    try {
      const response = await api.get(buildConversationsUrl(nextPage, filters));
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
  }, [buildConversationsUrl, searchTerm, filters, conversationsLoadingMore, conversationsPagination, loading]);

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
    setConvSearchInput((value) => value.trim());
    loadedConversationPageRef.current = 1;
  }, []);

  const handleNextConversationPage = useCallback(() => {
    if (!conversationsPagination) {
      return;
    }

    setConvPage((page) =>
      Math.min(Number(conversationsPagination.last_page ?? page), Number(page) + 1)
    );
  }, [conversationsPagination]);

  const isSearchMode = searchTerm !== '';
  const visibleConversations = isSearchMode ? searchResults : conversations;
  const visiblePagination = isSearchMode ? null : conversationsPagination;

  return {
    conversationListRef,
    conversations: visibleConversations,
    conversationsLoading: isSearchMode ? searchLoading : conversationsLoading,
    conversationsLoadingMore,
    conversationsPagination: visiblePagination,
    convSearchInput,
    filters,
    isSearchMode,
    searchTerm,
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
