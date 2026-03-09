import './AdminInboxPage.css';
import { useState, useEffect } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import InboxBackButton from '@/components/ui/InboxBackButton/InboxBackButton.jsx';

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
  const [contactNameInput, setContactNameInput] = useState('');
  const [contactBusy, setContactBusy] = useState(false);
  const [contactError, setContactError] = useState('');
  const [contactSuccess, setContactSuccess] = useState('');
  const [privacyMessage, setPrivacyMessage] = useState('');

  useEffect(() => {
    const firstCompanyId = data?.companies?.[0]?.id;
    if (!firstCompanyId || companyId) return;
    setCompanyId(String(firstCompanyId));
  }, [data, companyId]);

  useEffect(() => {
    if (!companyId) return;
    let canceled = false;
    setListLoading(true);
    setPrivacyMessage('');
    setConversations([]);
    setSelectedId(null);
    setDetail(null);
    setContactNameInput('');
    setContactError('');
    setContactSuccess('');
    api
      .get(`/admin/conversas?company_id=${companyId}`)
      .then((response) => {
        if (canceled) return;
        setConversations(response.data?.conversations ?? []);
        if (response.data?.privacy_mode) {
          setPrivacyMessage(
            'Modo privacidade ativo: mensagens e dados pessoais do cliente não são exibidos para superadmin.'
          );
        }
      })
      .catch((err) => {
        if (canceled) return;
        setPrivacyMessage(err.response?.data?.message || 'Falha ao carregar metadados das conversas.');
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
    setContactNameInput('');
    setContactError('');
    setContactSuccess('');
    
    try {
      const response = await api.get(`/admin/conversas/${conversationId}`);
      const conversation = response.data?.conversation ?? null;
      setDetail(conversation);
      setContactNameInput(conversation?.customer_name ?? '');
      if (response.data?.privacy_mode) {
        setPrivacyMessage(
          'Modo privacidade ativo: detalhes sensíveis e histórico de mensagens permanecem ocultos.'
        );
      }
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao carregar conversa.');
    } finally {
      setDetailLoading(false);
    }
  };

  const saveContactName = async () => {
    if (!detail?.id) return;
    setContactBusy(true);
    setContactError('');
    setContactSuccess('');
    try {
      const payloadName = String(contactNameInput ?? '').trim();
      const response = await api.put(`/admin/conversas/${detail.id}/contato`, {
        customer_name: payloadName || null,
      });

      const updatedConversation = response.data?.conversation ?? null;
      if (updatedConversation) {
        setDetail(updatedConversation);
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
        <p className="text-sm text-red-600 dark:text-red-400">Não foi possível carregar a inbox.</p>
      </Layout>
    );
  }

  return (
    <Layout role="admin" onLogout={logout} fullWidth>
      <div className="inbox-page">
      <div className="inbox-header px-4 py-4 lg:px-6 shrink-0">
        <PageHeader
          title="Inbox (admin)"
          subtitle="Monitore conversas por empresa, assuma atendimentos críticos e responda rapidamente."
        />
      <div className="mb-4 max-w-sm mt-2">
        <label className="block text-sm">
          Empresa
          <select
            value={companyId}
            onChange={(event) => setCompanyId(event.target.value)}
            className="app-input"
          >
            {(data.companies ?? []).map((company) => (
              <option key={company.id} value={company.id}>
                {company.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {privacyMessage && (
        <p className="mb-4 text-sm text-[#525252] bg-[#fafafa] rounded-lg px-4 py-3">
          {privacyMessage}
        </p>
      )}
      </div>

      <div className="inbox-layout grid grid-cols-1 lg:grid-cols-[minmax(200px,280px)_1fr] flex-1 min-h-0">
        <aside
          className={`inbox-conversations min-h-0 overflow-y-auto bg-[#fafafa] px-4 py-4 lg:px-5 ${
            selectedId ? 'hidden lg:block' : 'block'
          }`}
        >
          <h2 className="text-base font-semibold mb-3">Conversas</h2>
          {listLoading && <p className="text-sm text-[#737373]">Carregando conversas...</p>}
          {!listLoading && !conversations.length && <p className="text-sm text-[#737373]">Nenhuma conversa.</p>}
          <ul className="space-y-2 text-sm">
            {conversations.map((conv) => (
              <li key={conv.id}>
                <button
                  type="button"
                  onClick={() => openConversation(conv.id)}
                  className={`w-full text-left px-3 py-2.5 rounded-lg transition ${
                    selectedId === conv.id
                      ? 'bg-[#eff6ff]'
                      : 'hover:bg-white'
                  }`}
                >
                  <div className="font-medium">
                    {conv.customer_phone_masked} - {conv.status}
                  </div>
                  <div className="text-xs text-[#526175] mt-1">
                    msgs: {conv.messages_count ?? 0} | tags: {conv.tags_count ?? 0} | modo:{' '}
                    {conv.handling_mode === 'human' ? 'manual' : 'bot'}
                  </div>
                </button>
              </li>
            ))}
          </ul>
        </aside>

        <section
          className={`inbox-messages flex flex-col min-h-[400px] lg:min-h-[600px] bg-white px-4 py-4 lg:px-6 overflow-y-auto ${
            selectedId ? 'block' : 'hidden lg:flex'
          }`}
        >
          {selectedId && (
            <InboxBackButton
              onClick={() => setSelectedId(null)}
              className="lg:hidden flex items-center gap-2 text-sm text-[#525252] hover:text-[#171717] mb-4"
              label="Voltar às conversas"
            />
          )}
          <h2 className="text-base font-semibold mb-3">Mensagens</h2>
          {detailLoading && <p className="text-sm text-[#737373]">Carregando conversa...</p>}
          {detailError && <p className="text-sm text-red-600">{detailError}</p>}
          {!detailLoading && !detail && !detailError && (
            <p className="text-sm text-[#737373]">Selecione uma conversa.</p>
          )}
          {!!detail && (
            <>
              <ul className="text-sm space-y-1 mb-3">
                <li>ID da conversa: {detail.id}</li>
                <li>Empresa ID: {detail.company_id}</li>
                <li>Telefone mascarado: {detail.customer_phone_masked}</li>
                <li>Status: {detail.status}</li>
                <li>Modo: {detail.handling_mode === 'human' ? 'manual' : 'bot'}</li>
                <li>Mensagens: {detail.messages_count ?? 0}</li>
                <li>Tags: {detail.tags_count ?? 0}</li>
              </ul>

              <div className="mb-4 rounded-lg p-3.5 bg-[#fafafa]">
                <p className="text-xs text-[#526175] mb-2">Contato do cliente</p>
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
                    className="app-input flex-1"
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
            </>
          )}
        </section>
      </div>
      </div>
    </Layout>
  );
}

export default AdminInboxPage;
