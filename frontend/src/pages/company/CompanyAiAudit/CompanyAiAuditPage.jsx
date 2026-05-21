import { useEffect, useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import usePageData from '@/hooks/usePageData';
import useAuth from '@/hooks/useAuth';
import useLogout from '@/hooks/useLogout';
import useAdminCompanySelector from '@/hooks/useAdminCompanySelector';
import api from '@/services/api';

const PAGE_SIZE_OPTIONS = [10, 20, 50, 100];
const FILTER_LABEL_CLASS = 'text-sm text-[var(--ui-text-muted)]';
const FIELD_CLASS = 'mt-1 w-full rounded-lg border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2 text-sm text-[var(--ui-text)] outline-none focus:border-[var(--ui-accent)] focus:ring-2 focus:ring-[var(--ui-ring)]';
const COMPACT_FIELD_CLASS = 'rounded-md border border-[var(--ui-border)] bg-[var(--ui-surface)] px-2 py-1 text-xs text-[var(--ui-text)] outline-none focus:border-[var(--ui-accent)] focus:ring-2 focus:ring-[var(--ui-ring)]';
const TABLE_HEAD_CLASS = 'bg-[var(--ui-surface-elevated)]';
const TABLE_HEAD_ROW_CLASS = 'border-b border-[var(--ui-border)] text-left text-[var(--ui-text-muted)]';
const TABLE_ROW_CLASS = 'border-b border-[var(--ui-border)] align-top';
const TABLE_TEXT_CLASS = 'text-[var(--ui-text)]';
const TABLE_MUTED_CLASS = 'text-[var(--ui-text-muted)]';

function formatDateTime(value) {
  if (!value) return '-';
  const ts = new Date(value).getTime();
  if (!Number.isFinite(ts)) return '-';
  return new Date(ts).toLocaleString('pt-BR');
}

function toPositiveInt(value, fallback = null) {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function humanizeAiAction(action) {
  const normalized = String(action || '').trim();
  if (!normalized) return 'Ação não informada';

  const known = {
    message_sent: 'Mensagem enviada para IA',
    tool_executed: 'Ferramenta executada',
    tool_failed: 'Falha ao executar ferramenta',
    safety_blocked: 'Bloqueada por segurança',
  };

  if (known[normalized]) return known[normalized];

  return normalized
    .replaceAll('_', ' ')
    .replace(/^./, (char) => char.toUpperCase());
}

function buildAuditUrl(filters, companyId, page, perPage) {
  const params = new URLSearchParams();
  if (filters.userId) params.set('user_id', String(filters.userId));
  if (filters.type && filters.type !== 'all') params.set('type', filters.type);
  if (filters.source && filters.source !== 'all') params.set('source', filters.source);
  if (filters.contact) params.set('contact', filters.contact);
  if (filters.startDate) params.set('start_date', filters.startDate);
  if (filters.endDate) params.set('end_date', filters.endDate);
  if (companyId) params.set('company_id', String(companyId));
  params.set('page', String(Math.max(1, toPositiveInt(page, 1))));
  params.set('per_page', String(Math.max(1, toPositiveInt(perPage, 20))));
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
    source: 'all',
    contact: '',
    startDate: '',
    endDate: '',
  });
  const [draftFilters, setDraftFilters] = useState({
    userId: '',
    type: 'all',
    source: 'all',
    contact: '',
    startDate: '',
    endDate: '',
  });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [detailItem, setDetailItem] = useState(null);

  const adminCompanyId = isAdmin && selectedCompanyId ? selectedCompanyId : '';
  const dataUrl = useMemo(
    () => buildAuditUrl(filters, adminCompanyId, page, perPage),
    [filters, adminCompanyId, page, perPage]
  );
  const { data, loading, error } = usePageData(dataUrl);

  const items = Array.isArray(data?.items) ? data.items : [];
  const users = Array.isArray(data?.users) ? data.users : [];

  const rawPagination =
    data?.pagination && typeof data.pagination === 'object' ? data.pagination : null;
  const apiCurrentPage = toPositiveInt(rawPagination?.current_page);
  const apiLastPage = toPositiveInt(rawPagination?.last_page);
  const apiPerPage = toPositiveInt(rawPagination?.per_page);
  const apiTotal = toPositiveInt(rawPagination?.total, 0);
  const hasApiPagination = Boolean(apiCurrentPage && apiLastPage && apiPerPage);

  const fallbackLastPage = Math.max(1, Math.ceil(items.length / perPage));
  const fallbackCurrentPage = Math.min(Math.max(page, 1), fallbackLastPage);
  const fallbackStart = (fallbackCurrentPage - 1) * perPage;
  const fallbackItems = items.slice(fallbackStart, fallbackStart + perPage);

  const visibleItems = hasApiPagination ? items : fallbackItems;
  const currentPage = hasApiPagination ? apiCurrentPage : fallbackCurrentPage;
  const lastPage = hasApiPagination ? apiLastPage : fallbackLastPage;
  const effectivePerPage = hasApiPagination ? apiPerPage : perPage;
  const totalItems = hasApiPagination ? Math.max(apiTotal, items.length) : items.length;
  const rangeStart = totalItems > 0 ? (currentPage - 1) * effectivePerPage + 1 : 0;
  const rangeEnd = totalItems > 0 ? Math.min(rangeStart + visibleItems.length - 1, totalItems) : 0;
  const canGoPrev = currentPage > 1;
  const canGoNext = currentPage < lastPage;

  useEffect(() => {
    if (hasApiPagination && apiLastPage && page > apiLastPage) {
      setPage(apiLastPage);
      return;
    }

    if (!hasApiPagination && page > fallbackLastPage) {
      setPage(fallbackLastPage);
    }
  }, [hasApiPagination, apiLastPage, page, fallbackLastPage]);

  const applyFilters = () => {
    setFilters({ ...draftFilters });
    setPage(1);
  };

  const clearFilters = () => {
    const cleared = { userId: '', type: 'all', source: 'all', contact: '', startDate: '', endDate: '' };
    setDraftFilters(cleared);
    setFilters(cleared);
    setPage(1);
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
        <PageLoading rows={2} cards={1} />
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
        subtitle="Acompanhe tudo o que a IA fez com logs completos para depuração e confiança."
        action={isAdmin && companies.length > 0 ? (
          <select
            value={selectedCompanyId}
            onChange={(e) => {
              setSelectedCompanyId(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2 text-sm text-[var(--ui-text)] outline-none focus:border-[var(--ui-accent)] focus:ring-2 focus:ring-[var(--ui-ring)]"
          >
            {companies.map((c) => (
              <option key={c.id} value={String(c.id)}>{c.name}</option>
            ))}
          </select>
        ) : undefined}
      />

      <Card className="mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-7">
          <label className={FILTER_LABEL_CLASS}>
            Usuário
            <select
              value={draftFilters.userId}
              onChange={(event) =>
                setDraftFilters((prev) => ({ ...prev, userId: event.target.value }))
              }
              className={FIELD_CLASS}
            >
              <option value="">Todos</option>
              {users.map((listUser) => (
                <option key={listUser.id} value={String(listUser.id)}>
                  {listUser.name}
                </option>
              ))}
            </select>
          </label>

          <label className={FILTER_LABEL_CLASS}>
            Tipo
            <select
              value={draftFilters.type}
              onChange={(event) =>
                setDraftFilters((prev) => ({ ...prev, type: event.target.value }))
              }
              className={FIELD_CLASS}
            >
              <option value="all">Todos</option>
              <option value="message">Mensagem</option>
              <option value="tool">Ferramenta</option>
              <option value="safety">Seguranca</option>
            </select>
          </label>

          <label className={FILTER_LABEL_CLASS}>
            Origem
            <select
              value={draftFilters.source}
              onChange={(event) =>
                setDraftFilters((prev) => ({ ...prev, source: event.target.value }))
              }
              className={FIELD_CLASS}
            >
              <option value="all">Todas</option>
              <option value="chatbot_whatsapp">Bot WhatsApp</option>
              <option value="internal_chat">Chat interno</option>
              <option value="conversation_suggestion">Sugestao</option>
            </select>
          </label>

          <label className={FILTER_LABEL_CLASS}>
            Contato
            <input
              type="search"
              value={draftFilters.contact}
              onChange={(event) =>
                setDraftFilters((prev) => ({ ...prev, contact: event.target.value }))
              }
              placeholder="Nome do contato"
              className={FIELD_CLASS}
            />
          </label>

          <label className={FILTER_LABEL_CLASS}>
            Data inicial
            <input
              type="date"
              value={draftFilters.startDate}
              onChange={(event) =>
                setDraftFilters((prev) => ({ ...prev, startDate: event.target.value }))
              }
              className={FIELD_CLASS}
            />
          </label>

          <label className={FILTER_LABEL_CLASS}>
            Data final
            <input
              type="date"
              value={draftFilters.endDate}
              onChange={(event) =>
                setDraftFilters((prev) => ({ ...prev, endDate: event.target.value }))
              }
              className={FIELD_CLASS}
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
        {totalItems <= 0 ? (
          <p className="p-6 text-sm text-[var(--ui-text-muted)]">Nenhum log encontrado com os filtros aplicados.</p>
        ) : (
          <div>
            <div className="overflow-x-auto app-responsive-table-wrap">
              <table className="min-w-full text-sm app-responsive-table">
                <thead className={TABLE_HEAD_CLASS}>
                  <tr className={TABLE_HEAD_ROW_CLASS}>
                    <th className="px-4 py-3 font-medium">Contato</th>
                    <th className="px-4 py-3 font-medium">Origem</th>
                    <th className="px-4 py-3 font-medium">Usuário</th>
                    <th className="px-4 py-3 font-medium">Mensagem enviada</th>
                    <th className="px-4 py-3 font-medium">Resposta da IA</th>
                    <th className="px-4 py-3 font-medium">Ferramenta</th>
                    <th className="px-4 py-3 font-medium">Tipo</th>
                    <th className="px-4 py-3 font-medium">Ação</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="px-4 py-3 font-medium">Conversa</th>
                    <th className="px-4 py-3 font-medium">Data/hora</th>
                    <th className="px-4 py-3 font-medium">Detalhes</th>
                  </tr>
                </thead>
                <tbody>
                  {!visibleItems.length ? (
                    <tr className="border-b border-[var(--ui-border)]">
                      <td data-label="Info" colSpan={12} className="px-4 py-4 text-sm text-[var(--ui-text-muted)]">
                        Nenhum item nesta página.
                      </td>
                    </tr>
                  ) : null}

                  {visibleItems.map((item) => (
                    <tr key={item.id} className={TABLE_ROW_CLASS}>
                      <td data-label="Contato" className={`px-4 py-3 ${TABLE_TEXT_CLASS}`}>
                        <p>{item.contact_name || '-'}</p>
                        {item.contact_phone_hash ? (
                          <p className="mt-1 text-xs text-[var(--ui-text-muted)]">hash {String(item.contact_phone_hash).slice(0, 10)}</p>
                        ) : null}
                      </td>
                      <td data-label="Origem" className={`px-4 py-3 ${TABLE_MUTED_CLASS}`}>{item.source_label || item.source || '-'}</td>
                      <td data-label="Usuário" className={`px-4 py-3 ${TABLE_TEXT_CLASS}`}>{item.user_name || '-'}</td>
                      <td data-label="Mensagem enviada" className={`px-4 py-3 max-w-[260px] ${TABLE_MUTED_CLASS}`}>
                        <p className="line-clamp-3">{item.message || '-'}</p>
                      </td>
                      <td data-label="Resposta da IA" className={`px-4 py-3 max-w-[260px] ${TABLE_MUTED_CLASS}`}>
                        <p className="line-clamp-3">{item.assistant_response || '-'}</p>
                      </td>
                      <td data-label="Ferramenta" className={`px-4 py-3 ${TABLE_MUTED_CLASS}`}>{item.tool_used || '-'}</td>
                      <td data-label="Tipo" className={`px-4 py-3 ${TABLE_MUTED_CLASS}`}>
                        {item.type === 'tool' ? 'Ferramenta' : (item.type === 'safety' ? 'Seguranca' : 'Mensagem')}
                      </td>
                      <td data-label="Ação" className={`px-4 py-3 ${TABLE_MUTED_CLASS}`}>{humanizeAiAction(item.action)}</td>
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
                      <td data-label="Conversa" className={`px-4 py-3 ${TABLE_MUTED_CLASS}`}>
                        {item.inbox_conversation_id ? `Inbox #${item.inbox_conversation_id}` : (item.conversation_id ? `IA #${item.conversation_id}` : '-')}
                      </td>
                      <td data-label="Data/hora" className={`px-4 py-3 ${TABLE_MUTED_CLASS}`}>{formatDateTime(item.created_at)}</td>
                      <td data-label="Detalhes" className="px-4 py-3">
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

            <div className="border-t border-[var(--ui-border)] px-4 py-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-xs text-[var(--ui-text-muted)]">
                Mostrando {rangeStart}-{rangeEnd} de {totalItems}
              </p>

              <div className="flex flex-wrap items-center gap-2">
                <label className="inline-flex items-center gap-2 text-xs text-[var(--ui-text-muted)]">
                  Itens por página
                  <select
                    value={String(perPage)}
                    onChange={(event) => {
                      const nextPerPage = toPositiveInt(event.target.value, 20);
                      setPerPage(nextPerPage);
                      setPage(1);
                    }}
                    className={COMPACT_FIELD_CLASS}
                  >
                    {PAGE_SIZE_OPTIONS.map((size) => (
                      <option key={size} value={String(size)}>
                        {size}
                      </option>
                    ))}
                  </select>
                </label>

                <Button
                  variant="secondary"
                  className="px-3 py-1.5 text-xs"
                  onClick={() => setPage((prev) => Math.max(1, prev - 1))}
                  disabled={!canGoPrev}
                >
                  Anterior
                </Button>

                <span className="text-xs text-[var(--ui-text-muted)] min-w-[96px] text-center">
                  Página {currentPage} de {lastPage}
                </span>

                <Button
                  variant="secondary"
                  className="px-3 py-1.5 text-xs"
                  onClick={() => setPage((prev) => prev + 1)}
                  disabled={!canGoNext}
                >
                  Próxima
                </Button>
              </div>
            </div>
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
            className="w-full max-w-4xl rounded-xl border border-[var(--ui-border)] bg-[var(--ui-surface)] p-5 text-[var(--ui-text)] shadow-lg"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-lg font-semibold text-[var(--ui-text)]">Detalhe do log</h2>
              <button
                type="button"
                className="rounded-md p-1.5 text-[var(--ui-text-muted)] hover:bg-[var(--ui-surface-elevated)]"
                onClick={closeDetail}
              >
                x
              </button>
            </div>

            {detailLoading ? <p className="text-sm text-[var(--ui-text-muted)]">Carregando detalhe...</p> : null}
            {detailError ? <p className="text-sm text-red-600">{detailError}</p> : null}

            {detailItem ? (
              <div className="space-y-4">
                <div className="grid grid-cols-1 gap-2 text-sm md:grid-cols-2">
                  <p><strong>Usuario:</strong> {detailItem.user_name || '-'}</p>
                  <p><strong>Contato:</strong> {detailItem.contact_name || '-'}</p>
                  <p><strong>Origem:</strong> {detailItem.source_label || detailItem.source || '-'}</p>
                  <p><strong>Status:</strong> {detailItem.status === 'erro' ? 'Erro' : 'OK'}</p>
                  <p><strong>Acao:</strong> {humanizeAiAction(detailItem.action)}</p>
                  <p><strong>Data/hora:</strong> {formatDateTime(detailItem.created_at)}</p>
                  <p><strong>Conversa IA:</strong> {detailItem.conversation_id || '-'}</p>
                  <p><strong>Conversa inbox:</strong> {detailItem.inbox_conversation_id || '-'}</p>
                  <p><strong>Mensagem:</strong> {detailItem.message_id || '-'}</p>
                  <p><strong>Decisao IA:</strong> {detailItem.decision_log_id || '-'}</p>
                </div>

                <div>
                  <h3 className="text-sm font-semibold text-[var(--ui-text)] mb-2">Metadata</h3>
                  <pre className="max-h-56 overflow-auto rounded-lg bg-[#0f172a] p-3 text-xs text-[#e2e8f0]">
                    {JSON.stringify(detailItem.metadata ?? {}, null, 2)}
                  </pre>
                </div>

                <div>
                  <h3 className="text-sm font-semibold text-[var(--ui-text)] mb-2">Contexto da conversa</h3>
                  {Array.isArray(detailItem.conversation_messages) && detailItem.conversation_messages.length ? (
                    <div className="max-h-64 overflow-auto rounded-lg border border-[var(--ui-border)] app-responsive-table-wrap">
                      <table className="min-w-full text-sm app-responsive-table">
                        <thead className={TABLE_HEAD_CLASS}>
                          <tr className={TABLE_HEAD_ROW_CLASS}>
                            <th className="px-3 py-2 font-medium">Papel</th>
                            <th className="px-3 py-2 font-medium">Conteúdo</th>
                            <th className="px-3 py-2 font-medium">Data</th>
                          </tr>
                        </thead>
                        <tbody>
                          {detailItem.conversation_messages.map((message) => (
                            <tr key={message.id} className={TABLE_ROW_CLASS}>
                              <td data-label="Papel" className={`px-3 py-2 ${TABLE_MUTED_CLASS}`}>{message.role}</td>
                              <td data-label="Conteúdo" className={`px-3 py-2 whitespace-pre-wrap ${TABLE_TEXT_CLASS}`}>{message.content}</td>
                              <td data-label="Data" className={`px-3 py-2 ${TABLE_MUTED_CLASS}`}>{formatDateTime(message.created_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <p className="text-sm text-[var(--ui-text-muted)]">Sem contexto de conversa disponível.</p>
                  )}
                </div>
                <div>
                  <h3 className="text-sm font-semibold text-[var(--ui-text)] mb-2">Contexto do atendimento WhatsApp</h3>
                  {Array.isArray(detailItem.inbox_messages) && detailItem.inbox_messages.length ? (
                    <div className="max-h-64 overflow-auto rounded-lg border border-[var(--ui-border)] app-responsive-table-wrap">
                      <table className="min-w-full text-sm app-responsive-table">
                        <thead className={TABLE_HEAD_CLASS}>
                          <tr className={TABLE_HEAD_ROW_CLASS}>
                            <th className="px-3 py-2 font-medium">Papel</th>
                            <th className="px-3 py-2 font-medium">Conteudo</th>
                            <th className="px-3 py-2 font-medium">Data</th>
                          </tr>
                        </thead>
                        <tbody>
                          {detailItem.inbox_messages.map((message) => (
                            <tr key={message.id} className={TABLE_ROW_CLASS}>
                              <td data-label="Papel" className={`px-3 py-2 ${TABLE_MUTED_CLASS}`}>{message.role}</td>
                              <td data-label="Conteudo" className={`px-3 py-2 whitespace-pre-wrap ${TABLE_TEXT_CLASS}`}>{message.content}</td>
                              <td data-label="Data" className={`px-3 py-2 ${TABLE_MUTED_CLASS}`}>{formatDateTime(message.created_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <p className="text-sm text-[var(--ui-text-muted)]">Sem contexto do WhatsApp disponivel.</p>
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

