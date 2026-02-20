import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import usePageData from '../../hooks/usePageData';
import useLogout from '../../hooks/useLogout';
import api from '../../lib/api';

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

  const openConversation = async (conversationId) => {
    setSelectedId(conversationId);
    setDetailLoading(true);
    setDetailError('');
    setDetail(null);
    try {
      const response = await api.get(`/admin/conversas/${conversationId}`);
      setDetail(response.data?.conversation ?? null);
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao carregar conversa.');
    } finally {
      setDetailLoading(false);
    }
  };

  const refreshConversations = async (forcedCompanyId = null) => {
    const targetCompanyId = forcedCompanyId ?? companyId;
    if (!targetCompanyId) return;
    const response = await api.get(`/admin/conversas?company_id=${targetCompanyId}`);
    setConversations(response.data?.conversations ?? []);
  };

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
            onChange={(e) => setCompanyId(e.target.value)}
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
                <button className="w-full text-left px-3 py-2 rounded border ...">
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
                Modo: <strong>{detail.handling_mode === 'manual' ? 'Manual' : 'Bot'}</strong>{' '}
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
                  onChange={(e) => setManualText(e.target.value)}
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
