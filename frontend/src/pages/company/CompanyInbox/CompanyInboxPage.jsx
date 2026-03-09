import './CompanyInboxPage.css';
import { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import InboxBackButton from '@/components/ui/InboxBackButton/InboxBackButton.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import realtimeClient from '@/services/realtimeClient';
import { useNotificationsContext } from '@/contexts/NotificationsContext';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import {
  appendUniqueMessage,
  mergeMessagesChronologically,
  sortConversationsByActivity,
} from './inboxRealtimeUtils';
import useInboxRealtimeSync from './useInboxRealtimeSync';
import ConversationsSidebar from './components/ConversationsSidebar.jsx';
import ConversationToolbar from './components/ConversationToolbar.jsx';
import MessagesPanel from './components/MessagesPanel.jsx';
import ReplyComposer from './components/ReplyComposer.jsx';
import TagsModal from './components/TagsModal.jsx';

const EMPTY_TRANSFER_OPTIONS = { areas: [], users: [] };

const CONV_PER_PAGE = 25;
const MSG_PER_PAGE = 25;

function CompanyInboxPage() {
  const [, setConvPage] = useState(1);
  const [convSearch, setConvSearch] = useState('');
  const [convSearchInput, setConvSearchInput] = useState('');
  const buildConversationsUrl = useCallback(
    (page = 1, search = convSearch) =>
      `/minha-conta/conversas?page=${page}&per_page=${CONV_PER_PAGE}${
        search ? `&search=${encodeURIComponent(search)}` : ''
      }`,
    [convSearch]
  );
  const { data, loading, error } = usePageData(`/minha-conta/conversas?page=1&per_page=${CONV_PER_PAGE}`);
  const { logout } = useLogout();
  const { markReadByReference, unreadConversationIds } = useNotificationsContext();
  const [conversations, setConversations] = useState([]);
  const [conversationsPagination, setConversationsPagination] = useState(null);
  const [conversationsLoadingMore, setConversationsLoadingMore] = useState(false);
  const [selectedId, setSelectedId] = useState(null);
  const [messagesPagination, setMessagesPagination] = useState(null);
  const [messagesLoadingOlder, setMessagesLoadingOlder] = useState(false);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [manualText, setManualText] = useState('');
  const [manualImageFile, setManualImageFile] = useState(null);
  const [manualImagePreviewUrl, setManualImagePreviewUrl] = useState('');
  const [manualBusy, setManualBusy] = useState(false);
  const [manualError, setManualError] = useState('');
  const [contactNameInput, setContactNameInput] = useState('');
  const [contactBusy, setContactBusy] = useState(false);
  const [contactError, setContactError] = useState('');
  const [contactSuccess, setContactSuccess] = useState('');
  const [actionBusy, setActionBusy] = useState(false);
  const [tagInput, setTagInput] = useState('');
  const [showTemplates, setShowTemplates] = useState(false);
  const [tagsModalOpen, setTagsModalOpen] = useState(false);
  const [transferExpanded, setTransferExpanded] = useState(false);
  const [quickReplies, setQuickReplies] = useState([]);
  const [transferOptions, setTransferOptions] = useState(EMPTY_TRANSFER_OPTIONS);
  const [transferArea, setTransferArea] = useState('');
  const [transferUserId, setTransferUserId] = useState('');
  const [transferBusy, setTransferBusy] = useState(false);
  const [transferError, setTransferError] = useState('');
  const [transferSuccess, setTransferSuccess] = useState('');
  const selectedIdRef = useRef(null);
  const queryConversationHandledRef = useRef(false);
  const conversationListRef = useRef(null);
  const chatListRef = useRef(null);
  const loadedConversationPageRef = useRef(1);
  const pendingMessageScrollRestoreRef = useRef(null);
  const shouldScrollChatToBottomRef = useRef(false);
  const wasChatNearBottomRef = useRef(true);

  const unreadConversationSet = useMemo(
    () => new Set((unreadConversationIds ?? []).map((value) => Number(value))),
    [unreadConversationIds]
  );

  useEffect(() => {
    let canceled = false;
    const handle = setTimeout(async () => {
      const search = convSearchInput.trim();
      setConvSearch(search);
      loadedConversationPageRef.current = 1;

      try {
        const response = await api.get(buildConversationsUrl(1, search));
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
        // Erro na busca não deve quebrar a inbox.
      }
    }, 350);

    return () => {
      canceled = true;
      clearTimeout(handle);
    };
  }, [buildConversationsUrl, convSearchInput]);

  useEffect(() => {
    return () => {
      if (manualImagePreviewUrl) {
        URL.revokeObjectURL(manualImagePreviewUrl);
      }
    };
  }, [manualImagePreviewUrl]);

  useEffect(() => {
    setConversations(sortConversationsByActivity(data?.conversations ?? []));
    setConversationsPagination(data?.conversations_pagination ?? null);
    setConversationsLoadingMore(false);
    loadedConversationPageRef.current = 1;

    if (conversationListRef.current) {
      conversationListRef.current.scrollTop = 0;
    }
  }, [data]);

  useEffect(() => {
    api.get('/minha-conta/respostas-rapidas').then((response) => {
      setQuickReplies(response.data?.quick_replies ?? []);
    });
  }, []);

  const availableUsers = useMemo(() => {
    const users = transferOptions.users ?? [];
    if (!transferArea) {
      return users;
    }

    return users.filter((user) =>
      (user.areas ?? []).some((area) => String(area.id) === String(transferArea))
    );
  }, [transferOptions, transferArea]);

  useEffect(() => {
    if (!transferUserId) return;
    const exists = availableUsers.some((user) => String(user.id) === String(transferUserId));
    if (!exists) {
      setTransferUserId('');
    }
  }, [availableUsers, transferUserId]);

  useEffect(() => {
    selectedIdRef.current = selectedId;
  }, [selectedId]);

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
    const response = await api.get(buildConversationsUrl(1));
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
        current_page: Math.max(loadedConversationPageRef.current, Number(incomingPagination.current_page ?? 1)),
      };
    });
  }, [buildConversationsUrl, convSearch]);

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
      const response = await api.get(buildConversationsUrl(nextPage));
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
      // Falha ao carregar mais conversas nao deve quebrar a inbox.
    } finally {
      setConversationsLoadingMore(false);
    }
  }, [buildConversationsUrl, conversationsLoadingMore, conversationsPagination, loading]);

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

  const touchConversationPresence = useCallback(async (conversationId) => {
    const id = Number.parseInt(String(conversationId), 10);
    if (!id) {
      return;
    }

    try {
      await api.post(`/realtime/conversations/${id}/presence`);
    } catch (_error) {
      // Presenca e best-effort; falha nao deve interromper inbox.
    }
  }, []);

  const clearConversationPresence = useCallback(async (conversationId) => {
    const id = Number.parseInt(String(conversationId), 10);
    if (!id) {
      return;
    }

    try {
      await api.delete(`/realtime/conversations/${id}/presence`);
    } catch (_error) {
      // Presenca e best-effort; falha nao deve interromper inbox.
    }
  }, []);

  const loadOlderMessages = useCallback(async () => {
    const id = selectedIdRef.current;
    const currentPage = Number(messagesPagination?.current_page ?? 1);

    if (!id || messagesLoadingOlder || currentPage <= 1) return;

    const previousMetrics = chatListRef.current
      ? {
          conversationId: id,
          scrollHeight: chatListRef.current.scrollHeight,
          scrollTop: chatListRef.current.scrollTop,
        }
      : null;

    setMessagesLoadingOlder(true);

    try {
      const response = await api.get(
        `/minha-conta/conversas/${id}?messages_page=${currentPage - 1}&messages_per_page=${MSG_PER_PAGE}`
      );
      const conversation = response.data?.conversation ?? null;
      if (!conversation || Number(selectedIdRef.current) !== id) return;

      pendingMessageScrollRestoreRef.current = previousMetrics;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...conversation,
        messages: mergeMessagesChronologically(conversation.messages ?? [], prev?.messages ?? []),
        transfer_history: response.data?.transfer_history ?? prev?.transfer_history ?? [],
      }));
      setMessagesPagination(response.data?.messages_pagination ?? null);
    } catch (_err) {
      // Falha ao carregar mensagens antigas nao deve interromper a conversa atual.
    } finally {
      setMessagesLoadingOlder(false);
    }
  }, [messagesLoadingOlder, messagesPagination]);

  const loadMessagesPage = useCallback(
    async (page) => {
      if (page < Number(messagesPagination?.current_page ?? 1)) {
        await loadOlderMessages();
      }
    },
    [loadOlderMessages, messagesPagination]
  );

  const refreshConversationDetail = useCallback(async (conversationId) => {
    const id = Number.parseInt(String(conversationId), 10);
    if (!id) return;

    try {
      const response = await api.get(`/minha-conta/conversas/${id}`);
      const conversation = response.data?.conversation ?? null;
      if (!conversation || Number(selectedIdRef.current) !== id) return;

      setDetail((prev) => ({
        ...(prev ?? {}),
        ...conversation,
        messages: mergeMessagesChronologically(prev?.messages ?? [], conversation.messages ?? []),
        transfer_history: response.data?.transfer_history ?? prev?.transfer_history ?? [],
      }));
      setMessagesPagination((prev) => {
        const incoming = response.data?.messages_pagination ?? null;
        if (!incoming) {
          return prev;
        }

        if (!prev) {
          return incoming;
        }

        return {
          ...incoming,
          current_page: Math.min(Number(prev.current_page ?? incoming.current_page), Number(incoming.current_page ?? prev.current_page)),
        };
      });
      setTransferOptions(response.data?.transfer_options ?? EMPTY_TRANSFER_OPTIONS);
      setContactNameInput(conversation.customer_name ?? '');
    } catch (_error) {
      // O estado local ja foi atualizado pelo evento; este refresh e apenas para garantir consistencia.
    }
  }, []);

  const openConversation = useCallback(async (conversationId) => {
    const previousSelected = selectedIdRef.current;
    if (previousSelected && Number(previousSelected) !== Number(conversationId)) {
      realtimeClient.leaveConversation(previousSelected);
      void clearConversationPresence(previousSelected);
    }

    setSelectedId(conversationId);
    setDetailLoading(true);
    setDetailError('');
    setDetail(null);
    setTransferOptions(EMPTY_TRANSFER_OPTIONS);
    setTransferArea('');
    setTransferUserId('');
    setTransferError('');
    setTransferSuccess('');
    setShowTemplates(false);
    setContactNameInput('');
    setContactError('');
    setContactSuccess('');
    pendingMessageScrollRestoreRef.current = null;
    shouldScrollChatToBottomRef.current = true;
    wasChatNearBottomRef.current = true;

    try {
      const response = await api.get(`/minha-conta/conversas/${conversationId}`);
      const conversation = response.data?.conversation ?? null;
      setDetail(
        conversation
          ? {
              ...conversation,
              transfer_history: response.data?.transfer_history ?? [],
            }
          : null
      );
      setMessagesPagination(response.data?.messages_pagination ?? null);
      setContactNameInput(conversation?.customer_name ?? '');
      setTransferOptions(response.data?.transfer_options ?? EMPTY_TRANSFER_OPTIONS);
      setTransferArea(conversation?.assigned_type === 'area' ? String(conversation.assigned_id ?? '') : '');
      await realtimeClient.joinConversation(conversationId);
      await touchConversationPresence(conversationId);
      await markReadByReference(
        NOTIFICATION_MODULE.INBOX,
        NOTIFICATION_REFERENCE_TYPE.CONVERSATION,
        conversationId
      );
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao carregar conversa.');
    } finally {
      setDetailLoading(false);
    }
  }, [clearConversationPresence, markReadByReference, touchConversationPresence]);

  useEffect(() => {
    if (queryConversationHandledRef.current || loading || !data?.authenticated) {
      return;
    }

    const params = new URLSearchParams(window.location.search);
    const conversationId = Number.parseInt(String(params.get('conversationId') ?? ''), 10);
    if (!conversationId) {
      queryConversationHandledRef.current = true;
      return;
    }

    queryConversationHandledRef.current = true;
    void openConversation(conversationId);
  }, [data, loading, openConversation]);

  useEffect(() => {
    if (!selectedId) {
      return undefined;
    }

    void touchConversationPresence(selectedId);
    const heartbeat = setInterval(() => {
      void touchConversationPresence(selectedId);
    }, 15000);

    return () => {
      clearInterval(heartbeat);
      void clearConversationPresence(selectedId);
    };
  }, [clearConversationPresence, selectedId, touchConversationPresence]);

  useInboxRealtimeSync({
    clearConversationPresence,
    refreshConversationDetail,
    refreshConversations,
    selectedIdRef,
    setConversations,
    setDetail,
  });

  const handleChatScroll = useCallback(
    (event) => {
      const element = event.currentTarget;
      const remaining = element.scrollHeight - element.scrollTop - element.clientHeight;
      wasChatNearBottomRef.current = remaining <= 72;

      if (element.scrollTop <= 80) {
        void loadOlderMessages();
      }
    },
    [loadOlderMessages]
  );

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

        element.scrollTop = element.scrollHeight - pendingRestore.scrollHeight + pendingRestore.scrollTop;
        wasChatNearBottomRef.current = false;
      });
      return;
    }

    if (shouldScrollChatToBottomRef.current || wasChatNearBottomRef.current) {
      shouldScrollChatToBottomRef.current = false;
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

  const assumeConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    setDetailError('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/assumir`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      upsertConversationInList(response.data?.conversation);
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao assumir conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const releaseConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    setDetailError('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/soltar`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      upsertConversationInList(response.data?.conversation);
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao soltar conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const closeConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    setDetailError('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/encerrar`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      upsertConversationInList(response.data?.conversation);
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao encerrar conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const transferConversation = async () => {
    if (!detail?.id) return;
    if (!transferArea && !transferUserId) {
      setTransferError('Selecione uma área ou um usuário destino.');
      setTransferSuccess('');
      return;
    }

    setTransferBusy(true);
    setTransferError('');
    setTransferSuccess('');
    try {
      const payload = transferUserId
        ? { type: 'user', id: Number(transferUserId), send_outbound: true }
        : { type: 'area', id: Number(transferArea), send_outbound: true };

      const response = await api.post(`/minha-conta/conversas/${detail.id}/transferir`, {
        ...payload,
      });

      const autoMessage = response.data?.message ?? null;
      const transferHistory = response.data?.transfer_history ?? [];
      shouldScrollChatToBottomRef.current = true;
      wasChatNearBottomRef.current = true;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        transfer_history: transferHistory.length ? transferHistory : prev?.transfer_history ?? [],
        messages: autoMessage ? appendUniqueMessage(prev?.messages ?? [], autoMessage) : prev?.messages ?? [],
      }));
      upsertConversationInList(response.data?.conversation);

      setTransferSuccess('Transferencia realizada com sucesso.');
      await refreshConversations();
    } catch (err) {
      setTransferError(err.response?.data?.message || 'Falha ao transferir conversa.');
    } finally {
      setTransferBusy(false);
    }
  };

  const addTag = async (tag) => {
    if (!detail?.id || !tag.trim()) return;
    const currentTags = detail.tags ?? [];
    const normalizedTag = tag.toLowerCase().trim();
    if (currentTags.includes(normalizedTag)) return;

    const newTags = [...currentTags, normalizedTag];
    try {
      await api.put(`/minha-conta/conversas/${detail.id}/tags`, { tags: newTags });
      setDetail((prev) => ({ ...(prev ?? {}), tags: newTags }));
      setTagInput('');
    } catch (err) {
      setDetailError('Falha ao adicionar tag.');
    }
  };

  const removeTag = async (tag) => {
    if (!detail?.id) return;
    const newTags = (detail.tags ?? []).filter((item) => item !== tag);
    try {
      await api.put(`/minha-conta/conversas/${detail.id}/tags`, { tags: newTags });
      setDetail((prev) => ({ ...(prev ?? {}), tags: newTags }));
    } catch (err) {
      setDetailError('Falha ao remover tag.');
    }
  };

  const sendManualReply = async (event) => {
    event.preventDefault();
    const trimmedText = manualText.trim();
    if (!detail?.id || (!trimmedText && !manualImageFile)) return;

    setManualBusy(true);
    setManualError('');
    try {
      let response;
      if (manualImageFile) {
        const payload = new FormData();
        if (trimmedText) {
          payload.append('text', trimmedText);
        }
        payload.append('send_outbound', '1');
        payload.append('image', manualImageFile);
        response = await api.post(`/minha-conta/conversas/${detail.id}/responder-manual`, payload);
      } else {
        response = await api.post(`/minha-conta/conversas/${detail.id}/responder-manual`, {
          text: trimmedText,
          send_outbound: true,
        });
      }

      const message = response.data?.message;
      shouldScrollChatToBottomRef.current = true;
      wasChatNearBottomRef.current = true;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        messages: appendUniqueMessage(prev?.messages ?? [], message),
      }));
      upsertConversationInList(response.data?.conversation);
      setManualText('');
      if (manualImagePreviewUrl) {
        URL.revokeObjectURL(manualImagePreviewUrl);
      }
      setManualImageFile(null);
      setManualImagePreviewUrl('');
      await refreshConversations();
    } catch (err) {
      setManualError(err.response?.data?.message || 'Falha ao enviar resposta manual.');
    } finally {
      setManualBusy(false);
    }
  };

  const handleManualImageChange = (event) => {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }

    if (manualImagePreviewUrl) {
      URL.revokeObjectURL(manualImagePreviewUrl);
    }

    setManualImageFile(file);
    setManualImagePreviewUrl(URL.createObjectURL(file));
    setManualError('');
  };

  const removeManualImage = () => {
    if (manualImagePreviewUrl) {
      URL.revokeObjectURL(manualImagePreviewUrl);
    }
    setManualImageFile(null);
    setManualImagePreviewUrl('');
  };

  const saveContactName = async () => {
    if (!detail?.id) return;
    setContactBusy(true);
    setContactError('');
    setContactSuccess('');
    try {
      const payloadName = String(contactNameInput ?? '').trim();
      const response = await api.put(`/minha-conta/conversas/${detail.id}/contato`, {
        customer_name: payloadName || null,
      });

      const updatedConversation = response.data?.conversation ?? null;
      if (updatedConversation) {
        setDetail((prev) => ({ ...(prev ?? {}), ...updatedConversation }));
        setContactNameInput(updatedConversation.customer_name ?? '');
        upsertConversationInList(updatedConversation);
      }

      setContactSuccess('Contato salvo.');
    } catch (err) {
      setContactError(err.response?.data?.message || 'Falha ao salvar contato.');
    } finally {
      setContactBusy(false);
    }
  };

  const handleConversationsSearchEnter = () => {
    setConvSearch(convSearchInput.trim());
    loadedConversationPageRef.current = 1;
  };

  const handleContactNameInputChange = (value) => {
    setContactNameInput(value);
    setContactSuccess('');
    setContactError('');
  };

  const handleTransferAreaChange = (value) => {
    setTransferArea(value);
    setTransferSuccess('');
    setTransferError('');
  };

  const handleTransferUserChange = (value) => {
    setTransferUserId(value);
    setTransferSuccess('');
    setTransferError('');

    if (value) {
      const selectedUser = (transferOptions.users ?? []).find((user) => String(user.id) === String(value));
      if (selectedUser?.areas?.length && !transferArea) {
        setTransferArea(String(selectedUser.areas[0].id));
      }
    }
  };

  const handleApplyQuickReply = (text) => {
    setManualText(text);
    setShowTemplates(false);
  };

  const getMessageImageUrl = (msg) => {
    if (!msg?.id) return '';
    return `/api/minha-conta/mensagens/${msg.id}/media`;
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando inbox...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Não foi possível carregar a inbox.</p>
      </Layout>
    );
  }

  return (
    <Layout role="company" onLogout={logout} fullWidth>
      <div className="inbox-page">
        <div className="inbox-header">
          <h1 className="inbox-title">Inbox da empresa - Acompanhe atendimento em tempo real.</h1>
        </div>
        <div className="inbox-layout grid grid-cols-1 lg:grid-cols-[minmax(200px,280px)_1fr]">
          <ConversationsSidebar
            selectedId={selectedId}
            convSearchInput={convSearchInput}
            onConvSearchInputChange={setConvSearchInput}
            onConvSearchEnter={handleConversationsSearchEnter}
            conversationListRef={conversationListRef}
            onConversationsScroll={handleConversationsScroll}
            conversations={conversations}
            unreadConversationSet={unreadConversationSet}
            onOpenConversation={openConversation}
            conversationsPagination={conversationsPagination}
            onNextConversationPage={() => {
              if (!conversationsPagination) {
                return;
              }

              setConvPage((page) => Math.min(conversationsPagination.last_page, page + 1));
            }}
            conversationsLoadingMore={conversationsLoadingMore}
            loadedConversationPage={loadedConversationPageRef.current}
          />

          <section
            className={`inbox-messages ${selectedId ? 'flex' : 'hidden lg:flex'} flex-col`}
          >
            {selectedId && (
              <InboxBackButton onClick={() => setSelectedId(null)} />
            )}
            {detailLoading && <p className="inbox-empty-state text-sm text-[#737373]">Carregando conversa...</p>}
            {detailError && <p className="inbox-empty-state text-sm text-red-600">{detailError}</p>}
            {!detailLoading && !detail && !detailError && (
              <p className="inbox-empty-state text-sm text-[#706f6c]">Selecione uma conversa.</p>
            )}
            {!!detail && (
              <div className="inbox-detail-layout">
                <ConversationToolbar
                  detail={detail}
                  contactNameInput={contactNameInput}
                  onContactNameChange={handleContactNameInputChange}
                  onSaveContactName={saveContactName}
                  contactBusy={contactBusy}
                  contactSuccess={contactSuccess}
                  contactError={contactError}
                  actionBusy={actionBusy}
                  onAssumeConversation={assumeConversation}
                  onReleaseConversation={releaseConversation}
                  onCloseConversation={closeConversation}
                  onOpenTagsModal={() => setTagsModalOpen(true)}
                  transferExpanded={transferExpanded}
                  onToggleTransferExpanded={() => setTransferExpanded((value) => !value)}
                  transferArea={transferArea}
                  onTransferAreaChange={handleTransferAreaChange}
                  transferOptions={transferOptions}
                  transferUserId={transferUserId}
                  onTransferUserChange={handleTransferUserChange}
                  availableUsers={availableUsers}
                  onTransferConversation={transferConversation}
                  transferBusy={transferBusy}
                  transferSuccess={transferSuccess}
                  transferError={transferError}
                />

                <MessagesPanel
                  detail={detail}
                  messagesPagination={messagesPagination}
                  onLoadMessagesPage={loadMessagesPage}
                  chatListRef={chatListRef}
                  onChatScroll={handleChatScroll}
                  messagesLoadingOlder={messagesLoadingOlder}
                  getMessageImageUrl={getMessageImageUrl}
                />

                <ReplyComposer
                  onSendManualReply={sendManualReply}
                  showTemplates={showTemplates}
                  onToggleTemplates={() => setShowTemplates((prev) => !prev)}
                  quickReplies={quickReplies}
                  onApplyQuickReply={handleApplyQuickReply}
                  onManualImageChange={handleManualImageChange}
                  manualImageFile={manualImageFile}
                  onRemoveManualImage={removeManualImage}
                  manualText={manualText}
                  onManualTextChange={setManualText}
                  manualBusy={manualBusy}
                  manualImagePreviewUrl={manualImagePreviewUrl}
                  manualError={manualError}
                />
              </div>
            )}
          </section>
        </div>
      </div>

      <TagsModal
        open={tagsModalOpen}
        detail={detail}
        tagInput={tagInput}
        onTagInputChange={setTagInput}
        onAddTag={() => addTag(tagInput)}
        onRemoveTag={removeTag}
        onClose={() => setTagsModalOpen(false)}
      />
    </Layout>
  );
}

export default CompanyInboxPage;




