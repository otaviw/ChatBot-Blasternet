import './CompanyInboxPage.css';
import { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import realtimeClient from '@/services/realtimeClient';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';

const EMPTY_TRANSFER_OPTIONS = { areas: [], users: [] };

function CompanyInboxPage() {
  const { data, loading, error } = usePageData('/minha-conta/conversas');
  const { logout } = useLogout();
  const [conversations, setConversations] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
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
  const [quickReplies, setQuickReplies] = useState([]);
  const [transferOptions, setTransferOptions] = useState(EMPTY_TRANSFER_OPTIONS);
  const [transferArea, setTransferArea] = useState('');
  const [transferUserId, setTransferUserId] = useState('');
  const [transferBusy, setTransferBusy] = useState(false);
  const [transferError, setTransferError] = useState('');
  const [transferSuccess, setTransferSuccess] = useState('');
  const selectedIdRef = useRef(null);

  useEffect(() => {
    return () => {
      if (manualImagePreviewUrl) {
        URL.revokeObjectURL(manualImagePreviewUrl);
      }
    };
  }, [manualImagePreviewUrl]);

  useEffect(() => {
    setConversations(data?.conversations ?? []);
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
    const response = await api.get('/minha-conta/conversas');
    setConversations(response.data?.conversations ?? []);
  }, []);

  const openConversation = useCallback(async (conversationId) => {
    const previousSelected = selectedIdRef.current;
    if (previousSelected && Number(previousSelected) !== Number(conversationId)) {
      realtimeClient.leaveConversation(previousSelected);
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
      setDetail(conversation);
      setContactNameInput(conversation?.customer_name ?? '');
      setTransferOptions(response.data?.transfer_options ?? EMPTY_TRANSFER_OPTIONS);
      setTransferArea(conversation?.assigned_type === 'area' ? String(conversation.assigned_id ?? '') : '');
      await realtimeClient.joinConversation(conversationId);
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao carregar conversa.');
    } finally {
      setDetailLoading(false);
    }
  }, []);

  useEffect(() => {
    const unsubscribeMessageCreated = realtimeClient.on('message.created', (envelope) => {
      const payload = envelope?.payload ?? {};
      const conversationId = Number.parseInt(String(payload.conversationId ?? ''), 10);
      const messageId = Number.parseInt(String(payload.messageId ?? ''), 10);

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
          return {
            ...conversation,
            messages_count: Number(conversation.messages_count ?? 0) + 1,
          };
        });

        if (!found) {
          void refreshConversations();
        }

        return next;
      });

      if (Number(selectedIdRef.current) !== conversationId) {
        return;
      }

      setDetail((prev) => {
        if (!prev || Number(prev.id) !== conversationId) {
          return prev;
        }

        const alreadyExists = (prev.messages ?? []).some((item) => Number(item.id) === messageId);
        if (alreadyExists) {
          return prev;
        }

        return {
          ...prev,
          messages: [
            ...(prev.messages ?? []),
            {
              id: messageId,
              conversation_id: conversationId,
              direction: payload.direction ?? 'out',
              type: payload.type ?? 'system',
              text: payload.text ?? '',
              content_type: payload.contentType ?? 'text',
              media_url: payload.mediaUrl ?? null,
              media_mime_type: payload.mediaMimeType ?? null,
              media_size_bytes: payload.mediaSizeBytes ?? null,
              media_width: payload.mediaWidth ?? null,
              media_height: payload.mediaHeight ?? null,
              created_at: payload.createdAt ?? null,
            },
          ],
        };
      });
    });

    const unsubscribeConversationTransferred = realtimeClient.on('conversation.transferred', (envelope) => {
      const payload = envelope?.payload ?? {};
      const conversationId = Number.parseInt(String(payload.conversationId ?? ''), 10);
      if (!conversationId) {
        return;
      }

      setConversations((prev) =>
        prev.map((conversation) => {
          if (Number(conversation.id) !== conversationId) {
            return conversation;
          }

          return {
            ...conversation,
            handling_mode: 'human',
            status: 'in_progress',
            assigned_type: payload.toAssignedType ?? conversation.assigned_type,
            assigned_id: payload.toAssignedId ?? conversation.assigned_id,
          };
        })
      );

      if (Number(selectedIdRef.current) === conversationId) {
        void openConversation(conversationId);
      }
    });

    return () => {
      unsubscribeMessageCreated();
      unsubscribeConversationTransferred();

      if (selectedIdRef.current) {
        realtimeClient.leaveConversation(selectedIdRef.current);
      }
    };
  }, [openConversation, refreshConversations]);

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
      setTransferError('Selecione uma area ou um usuario destino.');
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
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        messages: autoMessage ? [...(prev?.messages ?? []), autoMessage] : prev?.messages ?? [],
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
        messages: [...(prev?.messages ?? []), message],
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
    <Layout role="company" onLogout={logout}>
      <PageHeader
        title="Inbox da empresa"
        subtitle="Acompanhe atendimento em tempo real, organize tags e distribua conversas com mais clareza."
      />
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[0.92fr_1.08fr]">
        <section className="app-panel">
          <h2 className="text-base font-semibold mb-3">Conversas</h2>
          {!conversations.length && <p className="text-sm text-[#706f6c]">Nenhuma conversa.</p>}
          <ul className="space-y-2 text-sm">
            {conversations.map((conv) => (
              <li key={conv.id}>
                <button
                  type="button"
                  onClick={() => openConversation(conv.id)}
                  className={`w-full text-left px-3 py-2.5 rounded-lg border transition ${
                    selectedId === conv.id
                      ? 'border-[#f53003] bg-[#fff5f2] shadow-[0_10px_20px_-22px_rgba(245,48,3,0.75)]'
                      : conv.status === 'closed'
                        ? 'border-[#e3e3e0] opacity-60'
                        : 'border-[#d9e1ec] hover:border-[#c5d1e1] hover:bg-[#f8fafc]'
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
                    {conv.current_area?.name ? <span className="ml-2">area: {conv.current_area.name}</span> : null}
                    {(conv.tags ?? []).length > 0 && (
                      <span className="ml-2">{conv.tags.join(', ')}</span>
                    )}
                  </div>
                </button>
              </li>
            ))}
          </ul>
        </section>

        <section className="app-panel">
          <h2 className="text-base font-semibold mb-3">Mensagens</h2>
          {detailLoading && <p className="text-sm text-[#706f6c]">Carregando conversa...</p>}
          {detailError && <p className="text-sm text-red-600">{detailError}</p>}
          {!detailLoading && !detail && !detailError && (
            <p className="text-sm text-[#706f6c]">Selecione uma conversa.</p>
          )}
          {!!detail && (
            <>
              <div className="mb-4 text-xs text-[#526175]">
                Modo: <strong>{detail.handling_mode === 'human' ? 'Manual' : 'Bot'}</strong>
                {detail.assigned_user ? ` | Responsavel: ${detail.assigned_user.name}` : ''}
                {detail.current_area?.name ? ` | Area: ${detail.current_area.name}` : ''}
              </div>

              <div className="mb-4 border border-[#d9e1ec] rounded-lg p-3.5 bg-[#fcfdff]">
                <p className="text-xs text-[#706f6c] mb-2">Contato do cliente</p>
                <div className="flex flex-col md:flex-row gap-2">
                  <input
                    type="text"
                    value={contactNameInput}
                    onChange={(event) => {
                      setContactNameInput(event.target.value);
                      setContactSuccess('');
                      setContactError('');
                    }}
                    placeholder="Nome do contato"
                    className="flex-1 rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615] text-sm"
                  />
                  <button
                    type="button"
                    onClick={saveContactName}
                    disabled={contactBusy}
                    className="app-btn-secondary"
                  >
                    {contactBusy ? 'Salvando...' : 'Salvar contato'}
                  </button>
                </div>
                {contactSuccess && <p className="text-xs text-green-700 mt-2">{contactSuccess}</p>}
                {contactError && <p className="text-xs text-red-600 mt-2">{contactError}</p>}
              </div>

              <div className="flex flex-wrap gap-2 mb-4">
                <button
                  type="button"
                  onClick={assumeConversation}
                  disabled={actionBusy}
                  className="app-btn-secondary"
                >
                  Assumir
                </button>
                <button
                  type="button"
                  onClick={releaseConversation}
                  disabled={actionBusy}
                  className="app-btn-secondary"
                >
                  Soltar para bot
                </button>
                <button
                  type="button"
                  onClick={closeConversation}
                  disabled={actionBusy || detail?.status === 'closed'}
                  className="app-btn-danger disabled:opacity-50"
                >
                  Encerrar
                </button>
              </div>

              <div className="mb-3">
                <p className="text-xs text-[#706f6c] mb-1">Tags</p>
                <div className="flex flex-wrap gap-1 mb-2">
                  {(detail.tags ?? []).length === 0 && (
                    <span className="text-xs text-[#706f6c]">Nenhuma tag.</span>
                  )}
                  {(detail.tags ?? []).map((tag) => (
                    <span
                      key={tag}
                      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#f0f0ef] dark:bg-[#2a2a28] text-xs"
                    >
                      {tag}
                      <button
                        type="button"
                        onClick={() => removeTag(tag)}
                        className="text-[#706f6c] hover:text-red-600"
                      >
                        x
                      </button>
                    </span>
                  ))}
                </div>
                <div className="flex gap-2">
                  <input
                    type="text"
                    value={tagInput}
                    onChange={(event) => setTagInput(event.target.value)}
                    onKeyDown={(event) => event.key === 'Enter' && (event.preventDefault(), addTag(tagInput))}
                    placeholder="Nova tag..."
                    className="flex-1 rounded border border-[#d5d5d2] px-2 py-1 text-xs bg-white dark:bg-[#161615]"
                  />
                  <button
                    type="button"
                    onClick={() => addTag(tagInput)}
                    className="px-3 py-1 text-xs rounded border border-[#d5d5d2]"
                  >
                    Adicionar
                  </button>
                </div>
              </div>

              <div className="mb-5 border border-[#d9e1ec] rounded-lg p-3.5 space-y-3 bg-[#fcfdff]">
                <div>
                  <p className="text-sm font-medium">Transferir conversa</p>
                  <p className="text-xs text-[#526175]">
                    Escolha uma area ou um usuario especifico da area. A transferencia e aceita automaticamente.
                  </p>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                  <label className="text-xs text-[#706f6c]">
                    Area destino
                    <select
                      value={transferArea}
                      onChange={(event) => {
                        setTransferArea(event.target.value);
                        setTransferSuccess('');
                        setTransferError('');
                      }}
                      className="app-input text-sm"
                    >
                      <option value="">Selecionar area</option>
                      {(transferOptions.areas ?? []).map((area) => (
                        <option key={area.id} value={area.id}>{area.name}</option>
                      ))}
                    </select>
                  </label>
                  <label className="text-xs text-[#706f6c]">
                    Usuario destino (opcional)
                    <select
                      value={transferUserId}
                      onChange={(event) => {
                        const nextUserId = event.target.value;
                        setTransferUserId(nextUserId);
                        setTransferSuccess('');
                        setTransferError('');
                        if (!nextUserId) return;
                        const user = (transferOptions.users ?? []).find(
                          (item) => String(item.id) === String(nextUserId)
                        );
                        if (!transferArea && user?.areas?.length) {
                          setTransferArea(String(user.areas[0].id));
                        }
                      }}
                      className="app-input text-sm"
                    >
                      <option value="">Selecionar usuario</option>
                      {availableUsers.map((user) => (
                        <option key={user.id} value={user.id}>
                          {user.name}{' '}
                          {(user.areas ?? []).length
                            ? `(${user.areas.map((area) => area.name).join(', ')})`
                            : ''}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={transferConversation}
                    disabled={transferBusy}
                    className="app-btn-secondary"
                  >
                    {transferBusy ? 'Transferindo...' : 'Transferir'}
                  </button>
                  {transferSuccess && <p className="text-xs text-green-700">{transferSuccess}</p>}
                  {transferError && <p className="text-xs text-red-600">{transferError}</p>}
                </div>
              </div>

              <div className="mb-4">
                <p className="text-xs text-[#706f6c] mb-1">Historico de transferencias</p>
                {(detail.transfer_history ?? []).length === 0 && (
                  <p className="text-xs text-[#706f6c]">Nenhuma transferencia registrada.</p>
                )}
                {(detail.transfer_history ?? []).length > 0 && (
                  <ul className="space-y-2 text-xs max-h-40 overflow-y-auto pr-1">
                    {detail.transfer_history.map((item) => {
                      const fromLabel = item.from_user?.name || item.from_area || 'sem origem';
                      const toLabel = item.to_user?.name || item.to_area || 'sem destino';
                      return (
                        <li key={item.id} className="border border-[#e3e3e0] rounded p-2">
                          <div>
                            {fromLabel} {'->'} {toLabel}
                          </div>
                          <div className="text-[#706f6c]">
                            por {item.transferred_by_user?.name || 'sistema'} em {formatDate(item.created_at)}
                          </div>
                        </li>
                      );
                    })}
                  </ul>
                )}
              </div>

              <ul className="space-y-2.5 text-sm mb-4 max-h-80 overflow-y-auto pr-1">
                {(detail.messages ?? []).map((msg) => (
                  <li key={msg.id} className="rounded-lg border border-[#d9e1ec] bg-white p-2.5">
                    <strong>{msg.direction === 'in' ? 'Cliente' : 'Atendente/Bot'}:</strong>{' '}
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
                      <span>{msg.text}</span>
                    )}
                  </li>
                ))}
              </ul>

              <form onSubmit={sendManualReply} className="space-y-2">
                <div className="relative">
                  <button
                    type="button"
                    onClick={() => setShowTemplates((prev) => !prev)}
                    className="app-btn-secondary text-xs"
                  >
                    Respostas rapidas v
                  </button>

                  {showTemplates && (
                    <div className="absolute bottom-8 left-0 z-10 w-72 bg-white dark:bg-[#161615] border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg shadow-lg max-h-48 overflow-y-auto">
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
                          className="w-full text-left px-3 py-2 hover:bg-[#f5f5f3] dark:hover:bg-[#2a2a28] border-b border-[#e3e3e0] dark:border-[#3E3E3A] last:border-0"
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
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}

export default CompanyInboxPage;




