import React, { useCallback, useEffect, useRef, useState } from 'react';
import Layout from '../../components/Layout';
import usePageData from '../../hooks/usePageData';
import useLogout from '../../hooks/useLogout';
import api from '../../lib/api';
import realtimeClient from '../../lib/realtimeClient';

function AdminInboxPage() {
  const { data, loading, error } = usePageData('/admin/empresas');
  const { logout } = useLogout();
  const [companyId, setCompanyId] = useState('');
  const [conversations, setConversations] = useState([]);
  const [listLoading, setListLoading] = useState(false);
  const [selectedId, setSelectedId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [manualText, setManualText] = useState('');
  const [manualBusy, setManualBusy] = useState(false);
  const [manualError, setManualError] = useState('');
  const [actionBusy, setActionBusy] = useState(false);
  const selectedIdRef = useRef(null);

  useEffect(() => {
    const firstCompanyId = data?.companies?.[0]?.id;
    if (!firstCompanyId || companyId) return;
    setCompanyId(String(firstCompanyId));
  }, [data, companyId]);

  useEffect(() => {
    if (!companyId) return;
    let canceled = false;
    setListLoading(true);
    api
      .get(`/admin/conversas?company_id=${companyId}`)
      .then((response) => {
        if (canceled) return;
        setConversations(response.data?.conversations ?? []);
      })
      .finally(() => {
        if (!canceled) setListLoading(false);
      });

    return () => {
      canceled = true;
    };
  }, [companyId]);

  useEffect(() => {
    selectedIdRef.current = selectedId;
  }, [selectedId]);

  const openConversation = useCallback(async (conversationId) => {
    const previousSelected = selectedIdRef.current;
    if (previousSelected && Number(previousSelected) !== Number(conversationId)) {
      realtimeClient.leaveConversation(previousSelected);
    }

    setSelectedId(conversationId);
    setDetailLoading(true);
    setDetailError('');
    setDetail(null);
    try {
      const response = await api.get(`/admin/conversas/${conversationId}`);
      setDetail(response.data?.conversation ?? null);
      await realtimeClient.joinConversation(conversationId);
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao carregar conversa.');
    } finally {
      setDetailLoading(false);
    }
  }, []);

  const refreshConversations = useCallback(async (forcedCompanyId = null) => {
    const targetCompanyId = forcedCompanyId ?? companyId;
    if (!targetCompanyId) return;
    const response = await api.get(`/admin/conversas?company_id=${targetCompanyId}`);
    setConversations(response.data?.conversations ?? []);
  }, [companyId]);

  useEffect(() => {
    const unsubscribeMessageCreated = realtimeClient.on('message.created', (envelope) => {
      const payload = envelope?.payload ?? {};
      const conversationId = Number.parseInt(String(payload.conversationId ?? ''), 10);
      const messageId = Number.parseInt(String(payload.messageId ?? ''), 10);
      const payloadCompanyId = Number.parseInt(String(payload.companyId ?? ''), 10);
      const selectedCompanyId = Number.parseInt(String(companyId ?? ''), 10);

      if (!conversationId || !messageId) {
        return;
      }

      if (selectedCompanyId && payloadCompanyId && selectedCompanyId !== payloadCompanyId) {
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
          void refreshConversations(selectedCompanyId || null);
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
              created_at: payload.createdAt ?? null,
            },
          ],
        };
      });
    });

    const unsubscribeConversationTransferred = realtimeClient.on('conversation.transferred', (envelope) => {
      const payload = envelope?.payload ?? {};
      const conversationId = Number.parseInt(String(payload.conversationId ?? ''), 10);
      const payloadCompanyId = Number.parseInt(String(payload.companyId ?? ''), 10);
      const selectedCompanyId = Number.parseInt(String(companyId ?? ''), 10);

      if (!conversationId) {
        return;
      }

      if (selectedCompanyId && payloadCompanyId && selectedCompanyId !== payloadCompanyId) {
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
  }, [companyId, openConversation, refreshConversations]);

  const assumeConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    try {
      const response = await api.post(`/admin/conversas/${detail.id}/assumir`);
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
    try {
      const response = await api.post(`/admin/conversas/${detail.id}/soltar`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao soltar conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const sendManualReply = async (event) => {
    event.preventDefault();
    if (!detail?.id || !manualText.trim()) return;

    setManualBusy(true);
    setManualError('');
    try {
      const response = await api.post(`/admin/conversas/${detail.id}/responder-manual`, {
        text: manualText.trim(),
        send_outbound: true,
      });
      const message = response.data?.message;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        messages: [...(prev?.messages ?? []), message],
      }));
      setManualText('');
      await refreshConversations();
    } catch (err) {
      setManualError(err.response?.data?.message || 'Falha ao enviar resposta manual.');
    } finally {
      setManualBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
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
    <Layout role="admin" onLogout={logout}>
      <h1 className="text-xl font-medium mb-4">Inbox (admin)</h1>
      <div className="mb-4 max-w-sm">
        <label className="block text-sm">
          Empresa
          <select
            value={companyId}
            onChange={(event) => setCompanyId(event.target.value)}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
          >
            {(data.companies ?? []).map((company) => (
              <option key={company.id} value={company.id}>
                {company.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Conversas</h2>
          {listLoading && <p className="text-sm text-[#706f6c]">Carregando conversas...</p>}
          {!listLoading && !conversations.length && <p className="text-sm text-[#706f6c]">Nenhuma conversa.</p>}
          <ul className="space-y-2 text-sm">
            {conversations.map((conv) => (
              <li key={conv.id}>
                <button
                  type="button"
                  onClick={() => openConversation(conv.id)}
                  className={`w-full text-left px-3 py-2 rounded border ${
                    selectedId === conv.id ? 'border-[#f53003]' : 'border-[#e3e3e0]'
                  }`}
                >
                  {conv.customer_phone} - {conv.status}
                  {(conv.tags ?? []).length > 0 && (
                    <span className="ml-2 text-xs text-[#706f6c]">
                      {conv.tags.join(', ')}
                    </span>
                  )}
                </button>
              </li>
            ))}
          </ul>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Mensagens</h2>
          {detailLoading && <p className="text-sm text-[#706f6c]">Carregando conversa...</p>}
          {detailError && <p className="text-sm text-red-600">{detailError}</p>}
          {!detailLoading && !detail && !detailError && (
            <p className="text-sm text-[#706f6c]">Selecione uma conversa.</p>
          )}
          {!!detail && (
            <>
              <div className="mb-3 text-xs text-[#706f6c]">
                Modo: <strong>{detail.handling_mode === 'human' ? 'Manual' : 'Bot'}</strong>{' '}
                {detail.assigned_user ? `| Assumida por: ${detail.assigned_user.name}` : ''}
              </div>

              <div className="flex gap-2 mb-3">
                <button
                  type="button"
                  onClick={assumeConversation}
                  disabled={actionBusy}
                  className="px-3 py-1 text-sm rounded border border-[#d5d5d2]"
                >
                  Assumir
                </button>
                <button
                  type="button"
                  onClick={releaseConversation}
                  disabled={actionBusy}
                  className="px-3 py-1 text-sm rounded border border-[#d5d5d2]"
                >
                  Soltar para bot
                </button>
              </div>

              <ul className="space-y-2 text-sm mb-3 max-h-80 overflow-y-auto pr-1">
                {(detail.messages ?? []).map((msg) => (
                  <li key={msg.id} className="border border-[#e3e3e0] rounded p-2">
                    <strong>{msg.direction === 'in' ? 'Cliente' : 'Atendente/Bot'}:</strong> {msg.text}
                  </li>
                ))}
              </ul>

              <form onSubmit={sendManualReply} className="space-y-2">
                <textarea
                  value={manualText}
                  onChange={(event) => setManualText(event.target.value)}
                  rows={3}
                  placeholder="Digite resposta manual..."
                  className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615] text-sm"
                />
                <button
                  type="submit"
                  disabled={manualBusy}
                  className="px-3 py-1.5 text-sm rounded bg-[#f53003] text-white disabled:opacity-60"
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

export default AdminInboxPage;
