import './CompanyInboxPage.css';
import { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import realtimeClient from '@/services/realtimeClient';
import { useNotificationsContext } from '@/contexts/NotificationsContext';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import { appendUniqueMessage, sortConversationsByActivity } from './inboxRealtimeUtils';
import useInboxRealtimeSync from './useInboxRealtimeSync';

const EMPTY_TRANSFER_OPTIONS = { areas: [], users: [] };

const CONV_PER_PAGE = 15;
const MSG_PER_PAGE = 25;

function CompanyInboxPage() {
  const [convPage, setConvPage] = useState(1);
  const [convSearch, setConvSearch] = useState('');
  const convQuery = `page=${convPage}${convSearch ? `&search=${encodeURIComponent(convSearch)}` : ''}`;
  const { data, loading, error } = usePageData(`/minha-conta/conversas?${convQuery}`);
  const { logout } = useLogout();
  const { markReadByReference, unreadConversationIds } = useNotificationsContext();
  const [conversations, setConversations] = useState([]);
  const [conversationsPagination, setConversationsPagination] = useState(null);
  const [selectedId, setSelectedId] = useState(null);
  const [messagesPage, setMessagesPage] = useState(null);
  const [messagesPagination, setMessagesPagination] = useState(null);
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

  const unreadConversationSet = useMemo(
    () => new Set((unreadConversationIds ?? []).map((value) => Number(value))),
    [unreadConversationIds]
  );

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

  const refreshConversations = useCallback(async () => {
    const response = await api.get(`/minha-conta/conversas?${convQuery}`);
    setConversations(sortConversationsByActivity(response.data?.conversations ?? []));
    setConversationsPagination(response.data?.conversations_pagination ?? null);
  }, [convQuery]);

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

  const loadMessagesPage = useCallback(async (page) => {
    const id = selectedIdRef.current;
    if (!id) return;
    try {
      const response = await api.get(`/minha-conta/conversas/${id}?messages_page=${page}&messages_per_page=${MSG_PER_PAGE}`);
      const conversation = response.data?.conversation ?? null;
      if (!conversation || Number(selectedIdRef.current) !== id) return;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...conversation,
        messages: Array.isArray(conversation.messages) ? conversation.messages : prev?.messages ?? [],
        transfer_history: response.data?.transfer_history ?? prev?.transfer_history ?? [],
      }));
      setMessagesPagination(response.data?.messages_pagination ?? null);
      setMessagesPage(page);
    } catch (_err) {}
  }, []);

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
        messages: Array.isArray(conversation.messages) ? conversation.messages : prev?.messages ?? [],
        transfer_history: response.data?.transfer_history ?? prev?.transfer_history ?? [],
      }));
      setMessagesPagination(response.data?.messages_pagination ?? null);
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
      setMessagesPage(null);
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

  const assumeConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    setDetailError('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/assumir`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
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
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        transfer_history: transferHistory.length ? transferHistory : prev?.transfer_history ?? [],
        messages: autoMessage ? appendUniqueMessage(prev?.messages ?? [], autoMessage) : prev?.messages ?? [],
      }));

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
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        messages: appendUniqueMessage(prev?.messages ?? [], message),
      }));
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
        setConversations((prev) =>
          prev.map((item) =>
            Number(item.id) === Number(updatedConversation.id)
              ? { ...item, customer_name: updatedConversation.customer_name ?? null }
              : item
          )
        );
      }

      setContactSuccess('Contato salvo.');
    } catch (err) {
      setContactError(err.response?.data?.message || 'Falha ao salvar contato.');
    } finally {
      setContactBusy(false);
    }
  };

  const formatDate = (value) => {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString('pt-BR');
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
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar a inbox.</p>
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
        <aside
          className={`inbox-conversations ${selectedId ? 'hidden lg:flex' : 'flex'} flex-col`}
        >
          <div className="inbox-conversations-header">
            <h2 className="inbox-conversations-title">Conversas</h2>
            <input
              type="search"
              value={convSearch}
              onChange={(e) => { setConvSearch(e.target.value); setConvPage(1); }}
              placeholder="Buscar contatos..."
              className="inbox-search-input app-input"
            />
          </div>
          <ul className="inbox-conversations-list space-y-2 text-sm">
            {!conversations.length && <li className="text-[#706f6c] py-4">Nenhuma conversa.</li>}
            {conversations.map((conv) => {
              const hasUnread = unreadConversationSet.has(Number(conv.id));

              return (
                <li key={conv.id}>
                  <button
                    type="button"
                    onClick={() => openConversation(conv.id)}
                    className={`w-full text-left px-3 py-2.5 rounded-lg transition ${
                        selectedId === conv.id
                        ? 'bg-[#eff6ff]'
                        : conv.status === 'closed'
                          ? 'opacity-70 hover:bg-white/60'
                          : hasUnread
                            ? 'bg-red-50/80 hover:bg-red-50'
                            : 'hover:bg-white'
                    }`}
                  >
                    <div className="font-medium text-[#0f172a]">
                      {conv.customer_name ? `${conv.customer_name} (${conv.customer_phone})` : conv.customer_phone}
                      {' - '}
                      {conv.status} ({conv.messages_count ?? 0} msg)
                    </div>
                    <div className="text-xs text-[#526175] mt-1">
                      {conv.status === 'closed'
                        ? 'encerrada'
                        : conv.handling_mode === 'human'
                          ? 'manual'
                          : 'bot'}
                      {hasUnread ? (
                        <span className="ml-2 rounded-full bg-[#dc2626] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                          nova msg
                        </span>
                      ) : null}
                      {conv.current_area?.name ? <span className="ml-2">area: {conv.current_area.name}</span> : null}
                      {(conv.tags ?? []).length > 0 && (
                        <span className="ml-2">{conv.tags.join(', ')}</span>
                      )}
                    </div>
                  </button>
                </li>
              );
            })}
          </ul>
          {conversationsPagination && conversationsPagination.last_page > 1 && (
            <div className="inbox-conversations-pagination">
              <button
                type="button"
                onClick={() => setConvPage((p) => Math.max(1, p - 1))}
                disabled={conversationsPagination.current_page <= 1}
                className="app-btn-secondary text-xs"
              >
                Anterior
              </button>
              <span className="text-xs text-[#737373]">
                Pág. {conversationsPagination.current_page} / {conversationsPagination.last_page}
              </span>
              <button
                type="button"
                onClick={() => setConvPage((p) => Math.min(conversationsPagination.last_page, p + 1))}
                disabled={conversationsPagination.current_page >= conversationsPagination.last_page}
                className="app-btn-secondary text-xs"
              >
                Próxima
              </button>
            </div>
          )}
        </aside>

        <section
          className={`inbox-messages ${selectedId ? 'flex' : 'hidden lg:flex'} flex-col`}
        >
          {selectedId && (
            <button
              type="button"
              onClick={() => setSelectedId(null)}
              className="lg:hidden inbox-back-btn"
            >
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M19 12H5M12 19l-7-7 7-7" />
              </svg>
              Voltar às conversas
            </button>
          )}
          {detailLoading && <p className="inbox-empty-state text-sm text-[#737373]">Carregando conversa...</p>}
          {detailError && <p className="inbox-empty-state text-sm text-red-600">{detailError}</p>}
          {!detailLoading && !detail && !detailError && (
            <p className="inbox-empty-state text-sm text-[#706f6c]">Selecione uma conversa.</p>
          )}
          {!!detail && (
            <div className="inbox-detail-layout">
              <div className="inbox-toolbar shrink-0 flex flex-wrap items-center gap-2">
                <span className="text-xs text-[#525252]">
                  Modo: <strong>{detail.handling_mode === 'human' ? 'Manual' : 'Bot'}</strong>
                  {detail.assigned_user ? ` · ${detail.assigned_user.name}` : ''}
                  {detail.current_area?.name ? ` · ${detail.current_area.name}` : ''}
                </span>
                <div className="flex items-center gap-1.5">
                  <input
                    type="text"
                    value={contactNameInput}
                    onChange={(e) => { setContactNameInput(e.target.value); setContactSuccess(''); setContactError(''); }}
                    placeholder="Nome"
                    className="w-32 app-input text-xs py-1.5"
                  />
                  <button type="button" onClick={saveContactName} disabled={contactBusy} className="app-btn-secondary text-xs py-1.5">
                    {contactBusy ? '...' : 'Salvar'}
                  </button>
                </div>
                {contactSuccess && <span className="text-xs text-green-600">{contactSuccess}</span>}
                {contactError && <span className="text-xs text-red-600">{contactError}</span>}
                <div className="flex gap-1">
                  <button type="button" onClick={assumeConversation} disabled={actionBusy} className="app-btn-secondary text-xs py-1.5">Assumir</button>
                  <button type="button" onClick={releaseConversation} disabled={actionBusy} className="app-btn-secondary text-xs py-1.5">Soltar</button>
                  <button type="button" onClick={closeConversation} disabled={actionBusy || detail?.status === 'closed'} className="app-btn-danger text-xs py-1.5">Encerrar</button>
                </div>
                <button type="button" onClick={() => setTagsModalOpen(true)} className="app-btn-secondary text-xs py-1.5">
                  Tags {(detail.tags ?? []).length > 0 && `(${(detail.tags ?? []).length})`}
                </button>
                <div className="inbox-transfer-wrap relative">
                  <button type="button" onClick={() => setTransferExpanded((v) => !v)} className="app-btn-secondary text-xs py-1.5">
                    Transferir {transferExpanded ? '▲' : '▼'}
                  </button>
                  {transferExpanded && (
                    <div className="inbox-transfer-dropdown">
                      <div className="space-y-2 mb-2">
                        <label className="block text-xs">
                          Área
                          <select value={transferArea} onChange={(e) => { setTransferArea(e.target.value); setTransferSuccess(''); setTransferError(''); }} className="app-input text-xs mt-0.5">
                            <option value="">Selecionar</option>
                            {(transferOptions.areas ?? []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                          </select>
                        </label>
                        <label className="block text-xs">
                          Usuário (opcional)
                          <select value={transferUserId} onChange={(e) => {
                            const v = e.target.value;
                            setTransferUserId(v);
                            setTransferSuccess(''); setTransferError('');
                            if (v) {
                              const u = (transferOptions.users ?? []).find((x) => String(x.id) === v);
                              if (u?.areas?.length && !transferArea) setTransferArea(String(u.areas[0].id));
                            }
                          }} className="app-input text-xs mt-0.5">
                            <option value="">Selecionar</option>
                            {availableUsers.map((u) => (
                              <option key={u.id} value={u.id}>{u.name} {(u.areas ?? []).length ? `(${u.areas.map((a) => a.name).join(', ')})` : ''}</option>
                            ))}
                          </select>
                        </label>
                      </div>
                      <button type="button" onClick={transferConversation} disabled={transferBusy} className="app-btn-primary text-xs w-full">
                        {transferBusy ? 'Transferindo...' : 'Transferir'}
                      </button>
                      {transferSuccess && <p className="text-xs text-green-600 mt-1">{transferSuccess}</p>}
                      {transferError && <p className="text-xs text-red-600 mt-1">{transferError}</p>}
                      {(detail.transfer_history ?? []).length > 0 && (
                        <div className="mt-2 pt-2 border-t border-[#eee]">
                          <p className="text-xs text-[#737373] mb-1">Histórico</p>
                          <ul className="space-y-1 text-xs max-h-24 overflow-y-auto">
                            {detail.transfer_history.map((item) => {
                              const fromLabel = item.from_user?.name || item.from_area || '—';
                              const toLabel = item.to_user?.name || item.to_area || '—';
                              return (
                                <li key={item.id} className="text-[#525252]">
                                  {fromLabel} → {toLabel}
                                </li>
                              );
                            })}
                          </ul>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>

              {messagesPagination && messagesPagination.last_page > 1 && (
                <div className="inbox-messages-pagination shrink-0 flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => loadMessagesPage(messagesPagination.current_page - 1)}
                    disabled={messagesPagination.current_page <= 1}
                    className="app-btn-secondary text-xs"
                  >
                    Anterior
                  </button>
                  <span className="text-xs text-[#737373]">
                    Msgs pág. {messagesPagination.current_page} / {messagesPagination.last_page}
                  </span>
                  <button
                    type="button"
                    onClick={() => loadMessagesPage(messagesPagination.current_page + 1)}
                    disabled={messagesPagination.current_page >= messagesPagination.last_page}
                    className="app-btn-secondary text-xs"
                  >
                    Próxima
                  </button>
                </div>
              )}

              <ul className="inbox-chat space-y-2.5 text-sm flex-1 min-h-0 overflow-y-auto overscroll-contain">
                {(detail.messages ?? []).map((msg) => (
                  <li
                    key={msg.id}
                    className={`inbox-message-bubble inbox-message-${msg.direction === 'in' ? 'in' : 'out'}`}
                  >
                    <span className="inbox-message-label">{msg.direction === 'in' ? 'Cliente' : 'Atendente/Bot'}</span>
                    {msg.content_type === 'image' ? (
                      <div className="company-inbox-message-media">
                        <a href={getMessageImageUrl(msg)} target="_blank" rel="noreferrer">
                          <img
                            src={getMessageImageUrl(msg)}
                            alt="Imagem enviada na conversa"
                            className="company-inbox-message-image"
                          />
                        </a>
                        {msg.text ? <p className="company-inbox-message-caption">{msg.text}</p> : null}
                      </div>
                    ) : (
                      <span className="inbox-message-text">{msg.text}</span>
                    )}
                  </li>
                ))}
              </ul>

              <form onSubmit={sendManualReply} className="inbox-reply-form shrink-0 space-y-2">
                <div className="relative">
                  <button
                    type="button"
                    onClick={() => setShowTemplates((prev) => !prev)}
                    className="app-btn-secondary text-xs"
                  >
                    Respostas rapidas v
                  </button>

                  {showTemplates && (
                    <div className="absolute bottom-8 left-0 z-10 w-72 bg-white rounded-lg shadow-lg max-h-48 overflow-y-auto">
                      {!quickReplies.length && (
                        <p className="text-xs text-[#706f6c] p-3">Nenhum template cadastrado.</p>
                      )}
                      {quickReplies.map((reply) => (
                        <button
                          key={reply.id}
                          type="button"
                          onClick={() => {
                            setManualText(reply.text);
                            setShowTemplates(false);
                          }}
                          className="w-full text-left px-3 py-2 hover:bg-[#f5f5f5] border-b border-[#eeeeee] last:border-0 text-[#171717]"
                        >
                          <p className="text-xs font-medium">{reply.title}</p>
                          <p className="text-xs text-[#706f6c] truncate">{reply.text}</p>
                        </button>
                      ))}
                    </div>
                  )}
                </div>

                <textarea
                  value={manualText}
                  onChange={(event) => setManualText(event.target.value)}
                  rows={3}
                  placeholder="Digite resposta manual ou use um template..."
                  className="app-input"
                />
                <div className="company-inbox-upload-row">
                  <label className="app-btn-secondary text-xs cursor-pointer">
                    Anexar imagem
                    <input
                      type="file"
                      accept="image/*"
                      onChange={handleManualImageChange}
                      className="hidden"
                    />
                  </label>
                  {manualImageFile ? (
                    <button
                      type="button"
                      onClick={removeManualImage}
                      className="app-btn-danger text-xs"
                    >
                      Remover imagem
                    </button>
                  ) : null}
                </div>
                {manualImagePreviewUrl ? (
                  <div className="company-inbox-image-preview">
                    <img src={manualImagePreviewUrl} alt="Prévia da imagem anexada" />
                  </div>
                ) : null}
                <button
                  type="submit"
                  disabled={manualBusy || (!manualText.trim() && !manualImageFile)}
                  className="app-btn-primary"
                >
                  {manualBusy ? 'Enviando...' : 'Enviar resposta manual'}
                </button>
                {manualError && <p className="text-sm text-red-600">{manualError}</p>}
              </form>
            </div>
          )}
        </section>
      </div>
      </div>

      {tagsModalOpen && detail && (
        <div className="inbox-tags-modal-overlay" onClick={() => setTagsModalOpen(false)}>
          <div className="inbox-tags-modal" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-base font-medium">Tags</h3>
              <button type="button" onClick={() => setTagsModalOpen(false)} className="text-[#525252] hover:text-[#171717]">
                ✕
              </button>
            </div>
            <div className="flex flex-wrap gap-1 mb-3">
              {(detail.tags ?? []).length === 0 && <span className="text-xs text-[#737373]">Nenhuma tag.</span>}
              {(detail.tags ?? []).map((tag) => (
                <span key={tag} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#f0f0f0] text-xs">
                  {tag}
                  <button type="button" onClick={() => removeTag(tag)} className="text-[#706f6c] hover:text-red-600">×</button>
                </span>
              ))}
            </div>
            <div className="flex gap-2">
              <input
                type="text"
                value={tagInput}
                onChange={(e) => setTagInput(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addTag(tagInput))}
                placeholder="Nova tag..."
                className="flex-1 app-input text-sm py-1.5"
              />
              <button type="button" onClick={() => addTag(tagInput)} className="app-btn-primary text-sm py-1.5">
                Adicionar
              </button>
            </div>
          </div>
        </div>
      )}
    </Layout>
  );
}

export default CompanyInboxPage;




