import { useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import usePageData from '@/hooks/usePageData';
import useAuth from '@/hooks/useAuth';
import useLogout from '@/hooks/useLogout';
import useAdminCompanySelector from '@/hooks/useAdminCompanySelector';
import api from '@/services/api';

function formatDateTime(value) {
  if (!value) return '-';
  const ts = new Date(value).getTime();
  if (!Number.isFinite(ts)) return '-';
  return new Date(ts).toLocaleString('pt-BR');
}

function buildAuditUrl(filters, companyId) {
  const params = new URLSearchParams();
  if (filters.userId) params.set('user_id', String(filters.userId));
  if (filters.type && filters.type !== 'all') params.set('type', filters.type);
  if (filters.startDate) params.set('start_date', filters.startDate);
  if (filters.endDate) params.set('end_date', filters.endDate);
  if (companyId) params.set('company_id', String(companyId));
  const query = params.toString();
  return `/minha-conta/ia/auditoria${query ? `?${query}` : ''}`;
}

function CompanyAiAuditPage() {
  const { user } = useAuth();
  const { logout } = useLogout();
  const isAdmin = user?.role === 'system_admin';

  const { companies, selectedCompanyId, setSelectedCompanyId } = useAdminCompanySelector({ isAdmin });

  const [filters, setFilters] = useState({
    userId: '',
    type: 'all',
    startDate: '',
    endDate: '',
  });
  const [draftFilters, setDraftFilters] = useState({
    userId: '',
    type: 'all',
    startDate: '',
    endDate: '',
  });
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [detailItem, setDetailItem] = useState(null);

  const adminCompanyId = isAdmin && selectedCompanyId ? selectedCompanyId : '';
  const dataUrl = useMemo(() => buildAuditUrl(filters, adminCompanyId), [filters, adminCompanyId]);
  const { data, loading, error } = usePageData(dataUrl);

  const items = Array.isArray(data?.items) ? data.items : [];
  const users = Array.isArray(data?.users) ? data.users : [];

  const applyFilters = () => {
    setFilters({ ...draftFilters });
  };

  const clearFilters = () => {
    const cleared = { userId: '', type: 'all', startDate: '', endDate: '' };
    setDraftFilters(cleared);
    setFilters(cleared);
  };

  const openDetail = async (id) => {
    setDetailLoading(true);
    setDetailError('');
    setDetailItem(null);
    try {
      const detailUrl = adminCompanyId
        ? `/minha-conta/ia/auditoria/${id}?company_id=${adminCompanyId}`
        : `/minha-conta/ia/auditoria/${id}`;
      const response = await api.get(detailUrl);
      setDetailItem(response.data?.item ?? null);
    } catch (err) {
      setDetailError(err.response?.data?.message ?? 'Não foi possível carregar o detalhe.');
    } finally {
      setDetailLoading(false);
    }
  };

  const closeDetail = () => {
    if (detailLoading) return;
    setDetailItem(null);
    setDetailError('');
  };

  const layoutRole = isAdmin ? 'admin' : 'company';

  if (loading) {
    return (
      <Layout role={layoutRole} onLogout={logout}>
        <p className="text-sm text-[#64748b]">Carregando auditoria da IA...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout role={layoutRole} onLogout={logout}>
        <p className="text-sm text-red-600">Não foi possível carregar a auditoria da IA.</p>
      </Layout>
    );
  }

  return (
    <Layout role={layoutRole} onLogout={logout}>
      <PageHeader
        title="Auditoria da IA"
        subtitle="Acompanhe tudo o que a IA fez com logs completos para debug e confiança."
        action={isAdmin && companies.length > 0 ? (
          <select
            value={selectedCompanyId}
            onChange={(e) => setSelectedCompanyId(e.target.value)}
            className="rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm text-[#1f2937] outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
          >
            {companies.map((c) => (
              <option key={c.id} value={String(c.id)}>{c.name}</option>
            ))}
          </select>
        ) : undefined}
      />

      <Card className="mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-5">
          <label className="text-sm text-[#334155]">
            Usuário
            <select
              value={draftFilters.userId}
              onChange={(event) =>
                setDraftFilters((prev) => ({ ...prev, userId: event.target.value }))
              }
              className="mt-1 w-full rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
            >
              <option value="">Todos</option>
              {users.map((user) => (
                <option key={user.id} value={String(user.id)}>
                  {user.name}
                </option>
              ))}
            </select>
          </label>

          <label className="text-sm text-[#334155]">
            Tipo
            <select
              value={draftFilters.type}
              onChange={(event) =>
                setDraftFilters((prev) => ({ ...prev, type: event.target.value }))
              }
              className="mt-1 w-full rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
            >
              <option value="all">Todos</option>
              <option value="message">Mensagem</option>
              <option value="tool">Tool</option>
            </select>
          </label>

          <label className="text-sm text-[#334155]">
            Data inicial
            <input
              type="date"
              value={draftFilters.startDate}
              onChange={(event) =>
                setDraftFilters((prev) => ({ ...prev, startDate: event.target.value }))
              }
              className="mt-1 w-full rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
            />
          </label>

          <label className="text-sm text-[#334155]">
            Data final
            <input
              type="date"
              value={draftFilters.endDate}
              onChange={(event) =>
                setDraftFilters((prev) => ({ ...prev, endDate: event.target.value }))
              }
              className="mt-1 w-full rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
            />
          </label>

          <div className="flex items-end gap-2">
            <Button variant="primary" className="w-full" onClick={applyFilters}>
              Filtrar
            </Button>
            <Button variant="secondary" className="w-full" onClick={clearFilters}>
              Limpar
            </Button>
          </div>
        </div>
      </Card>

      <Card className="p-0 overflow-hidden">
        {!items.length ? (
          <p className="p-6 text-sm text-[#64748b]">Nenhum log encontrado com os filtros aplicados.</p>
        ) : (
          <div className="overflow-x-auto app-responsive-table-wrap">
            <table className="min-w-full text-sm app-responsive-table">
              <thead className="bg-[#f8fafc]">
                <tr className="border-b border-[#e2e8f0] text-left text-[#64748b]">
                  <th className="px-4 py-3 font-medium">Usuário</th>
                  <th className="px-4 py-3 font-medium">Mensagem enviada</th>
                  <th className="px-4 py-3 font-medium">Resposta da IA</th>
                  <th className="px-4 py-3 font-medium">Tool</th>
                  <th className="px-4 py-3 font-medium">Tipo</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3 font-medium">Data/hora</th>
                  <th className="px-4 py-3 font-medium">Ação</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id} className="border-b border-[#f1f5f9] align-top">
                    <td data-label="Usuário" className="px-4 py-3 text-[#0f172a]">{item.user_name || '-'}</td>
                    <td data-label="Mensagem enviada" className="px-4 py-3 text-[#334155] max-w-[260px]">
                      <p className="line-clamp-3">{item.message || '-'}</p>
                    </td>
                    <td data-label="Resposta da IA" className="px-4 py-3 text-[#334155] max-w-[260px]">
                      <p className="line-clamp-3">{item.assistant_response || '-'}</p>
                    </td>
                    <td data-label="Tool" className="px-4 py-3 text-[#334155]">{item.tool_used || '-'}</td>
                    <td data-label="Tipo" className="px-4 py-3 text-[#334155]">
                      {item.type === 'tool' ? 'Tool' : 'Mensagem'}
                    </td>
                    <td data-label="Status" className="px-4 py-3">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                          item.status === 'erro'
                            ? 'bg-red-50 text-red-700 border border-red-200'
                            : 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                        }`}
                      >
                        {item.status === 'erro' ? 'Erro' : 'OK'}
                      </span>
                    </td>
                    <td data-label="Data/hora" className="px-4 py-3 text-[#334155]">{formatDateTime(item.created_at)}</td>
                    <td data-label="Ação" className="px-4 py-3">
                      <Button
                        variant="secondary"
                        className="px-3 py-1.5 text-xs"
                        onClick={() => void openDetail(item.id)}
                      >
                        Ver detalhe
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {(detailLoading || detailItem || detailError) && (
        <div
          className="fixed inset-0 z-[90] flex items-center justify-center bg-black/40 p-4"
          onClick={closeDetail}
          role="dialog"
          aria-modal="true"
        >
          <div
            className="w-full max-w-4xl rounded-xl border border-[#e5e7eb] bg-white p-5 shadow-lg"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-lg font-semibold text-[#0f172a]">Detalhe do log</h2>
              <button
                type="button"
                className="rounded-md p-1.5 text-[#64748b] hover:bg-[#f1f5f9]"
                onClick={closeDetail}
              >
                ×
              </button>
            </div>

            {detailLoading ? <p className="text-sm text-[#64748b]">Carregando detalhe...</p> : null}
            {detailError ? <p className="text-sm text-red-600">{detailError}</p> : null}

            {detailItem ? (
              <div className="space-y-4">
                <div className="grid grid-cols-1 gap-2 text-sm md:grid-cols-2">
                  <p><strong>Usuário:</strong> {detailItem.user_name || '-'}</p>
                  <p><strong>Status:</strong> {detailItem.status === 'erro' ? 'Erro' : 'OK'}</p>
                  <p><strong>Ação:</strong> {detailItem.action}</p>
                  <p><strong>Data/hora:</strong> {formatDateTime(detailItem.created_at)}</p>
                  <p><strong>Conversa:</strong> {detailItem.conversation_id || '-'}</p>
                </div>

                <div>
                  <h3 className="text-sm font-semibold text-[#0f172a] mb-2">Metadata</h3>
                  <pre className="max-h-56 overflow-auto rounded-lg bg-[#0f172a] p-3 text-xs text-[#e2e8f0]">
                    {JSON.stringify(detailItem.metadata ?? {}, null, 2)}
                  </pre>
                </div>

                <div>
                  <h3 className="text-sm font-semibold text-[#0f172a] mb-2">Contexto da conversa</h3>
                  {Array.isArray(detailItem.conversation_messages) && detailItem.conversation_messages.length ? (
                    <div className="max-h-64 overflow-auto rounded-lg border border-[#e2e8f0] app-responsive-table-wrap">
                      <table className="min-w-full text-sm app-responsive-table">
                        <thead className="bg-[#f8fafc]">
                          <tr className="border-b border-[#e2e8f0] text-left text-[#64748b]">
                            <th className="px-3 py-2 font-medium">Papel</th>
                            <th className="px-3 py-2 font-medium">Conteúdo</th>
                            <th className="px-3 py-2 font-medium">Data</th>
                          </tr>
                        </thead>
                        <tbody>
                          {detailItem.conversation_messages.map((message) => (
                            <tr key={message.id} className="border-b border-[#f1f5f9] align-top">
                              <td data-label="Papel" className="px-3 py-2 text-[#334155]">{message.role}</td>
                              <td data-label="Conteúdo" className="px-3 py-2 text-[#0f172a] whitespace-pre-wrap">{message.content}</td>
                              <td data-label="Data" className="px-3 py-2 text-[#334155]">{formatDateTime(message.created_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <p className="text-sm text-[#64748b]">Sem contexto de conversa disponível.</p>
                  )}
                </div>
              </div>
            ) : null}
          </div>
        </div>
      )}
    </Layout>
  );
}

export default CompanyAiAuditPage;
