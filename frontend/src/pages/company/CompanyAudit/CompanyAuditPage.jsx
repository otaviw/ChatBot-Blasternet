import { useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import PageState from '@/components/ui/PageState/PageState.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import { useLocation } from 'react-router-dom';

const PAGE_SIZE = 20;

function formatDateTime(value) {
  if (!value) return '-';
  const timestamp = new Date(value).getTime();
  if (!Number.isFinite(timestamp)) return '-';
  return new Date(timestamp).toLocaleString('pt-BR');
}

function isMeaningful(value) {
  if (value === null || value === undefined) return false;

  if (typeof value === 'string') {
    return value.trim() !== '';
  }

  if (Array.isArray(value)) {
    return value.some((item) => isMeaningful(item));
  }

  if (typeof value === 'object') {
    return Object.values(value).some((item) => isMeaningful(item));
  }

  return true;
}

function cleanValue(value) {
  if (!isMeaningful(value)) return null;

  if (Array.isArray(value)) {
    const cleaned = value.map((item) => cleanValue(item)).filter((item) => item !== null);
    return cleaned.length ? cleaned : null;
  }

  if (typeof value === 'object' && value !== null) {
    const entries = Object.entries(value)
      .map(([key, item]) => [key, cleanValue(item)])
      .filter(([, item]) => item !== null);

    return entries.length ? Object.fromEntries(entries) : null;
  }

  if (typeof value === 'string') {
    const trimmed = value.trim();
    return trimmed === '' ? null : trimmed;
  }

  return value;
}

function toPrettyJson(value) {
  const cleaned = cleanValue(value);
  if (!cleaned) return '';

  try {
    return JSON.stringify(cleaned, null, 2);
  } catch {
    return '';
  }
}

function humanizeActionCode(action) {
  const normalized = String(action || '').trim();
  if (!normalized) return 'Ação não informada';

  return normalized
    .split('.')
    .filter((part) => part && !['company', 'admin', 'support', 'bot'].includes(part))
    .map((part) => part.replaceAll('_', ' '))
    .join(' ')
    .replace(/^./, (char) => char.toUpperCase()) || 'Ação não informada';
}

function getActionLabel(item) {
  return String(item?.action_label || '').trim() || humanizeActionCode(item?.action);
}

function getUserLabel(item) {
  const userName = String(item?.user_name || '').trim();
  if (userName) return userName;
  return item?.user_id ? 'Usuário removido' : 'Sistema';
}

function getEntityLabel(item) {
  const entityType = String(item?.entity_type || '').trim();
  const entityId = String(item?.entity_id || '').trim();

  if (!entityType && !entityId) return '-';
  if (!entityType) return `#${entityId}`;
  if (!entityId) return entityType;
  return `${entityType} #${entityId}`;
}

function buildUrl(basePath, filters, page) {
  const params = new URLSearchParams();
  if (filters.action) params.set('action', filters.action);
  if (filters.startDate) params.set('start_date', filters.startDate);
  if (filters.endDate) params.set('end_date', filters.endDate);
  params.set('page', String(page));
  params.set('per_page', String(PAGE_SIZE));
  return `${basePath}?${params.toString()}`;
}

function CompanyAuditPage() {
  const { logout } = useLogout();
  const location = useLocation();
  const isAdminView = location.pathname.startsWith('/admin/');
  const layoutRole = isAdminView ? 'admin' : 'company';
  const auditBasePath = isAdminView ? '/admin/audit-logs' : '/minha-conta/audit-logs';

  const [filters, setFilters] = useState({ action: '', startDate: '', endDate: '' });
  const [draft, setDraft] = useState({ action: '', startDate: '', endDate: '' });
  const [page, setPage] = useState(1);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [detailItem, setDetailItem] = useState(null);

  const dataUrl = useMemo(() => buildUrl(auditBasePath, filters, page), [auditBasePath, filters, page]);
  const { data, loading, error, refetch } = usePageData(dataUrl);

  const rows = Array.isArray(data?.data) ? data.data : [];
  const currentPage = Number(data?.current_page ?? 1);
  const lastPage = Number(data?.last_page ?? 1);
  const total = Number(data?.total ?? rows.length);
  const from = Number(data?.from ?? (rows.length ? 1 : 0));
  const to = Number(data?.to ?? rows.length);

  const applyFilters = () => {
    setFilters({ ...draft });
    setPage(1);
  };

  const clearFilters = () => {
    const empty = { action: '', startDate: '', endDate: '' };
    setDraft(empty);
    setFilters(empty);
    setPage(1);
  };

  const closeDetail = () => {
    if (detailLoading) return;
    setDetailItem(null);
    setDetailError('');
  };

  const openDetail = async (id) => {
    setDetailLoading(true);
    setDetailError('');
    setDetailItem(null);

    try {
      const response = await api.get(`${auditBasePath}/${id}`);
      setDetailItem(response.data?.item ?? null);
    } catch (err) {
      setDetailError(err.response?.data?.message ?? 'Não foi possível carregar o detalhe do log.');
    } finally {
      setDetailLoading(false);
    }
  };

  const detailOldData = toPrettyJson(detailItem?.old_data);
  const detailNewData = toPrettyJson(detailItem?.new_data);

  const detailFields = detailItem
    ? [
        ['Ação', getActionLabel(detailItem)],
        ['Usuário', getUserLabel(detailItem)],
        ['Entidade', getEntityLabel(detailItem)],
        ['Data', formatDateTime(detailItem.created_at)],
        ['IP', String(detailItem.ip_address || '').trim()],
        ['Navegador', String(detailItem.user_agent || '').trim()],
      ].filter(([, value]) => isMeaningful(value) && value !== '-')
    : [];

  return (
    <Layout role={layoutRole} onLogout={logout}>
      <PageState
        loading={loading}
        error={error}
        errorMessage="Não foi possível carregar os logs de auditoria."
        onRetry={refetch}
      >
        <PageHeader
          title="Auditoria"
          subtitle="Consulte eventos auditados com filtros por ação e data."
        />

        <Card className="mb-4 p-4">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
            <label className="text-sm text-[#334155]">
              Ação
              <input
                type="text"
                value={draft.action}
                onChange={(event) => setDraft((prev) => ({ ...prev, action: event.target.value }))}
                placeholder="Ex.: company.conversation.manual_reply"
                className="mt-1 w-full rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
              />
            </label>

            <label className="text-sm text-[#334155]">
              Data inicial
              <input
                type="date"
                value={draft.startDate}
                onChange={(event) => setDraft((prev) => ({ ...prev, startDate: event.target.value }))}
                className="mt-1 w-full rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
              />
            </label>

            <label className="text-sm text-[#334155]">
              Data final
              <input
                type="date"
                value={draft.endDate}
                onChange={(event) => setDraft((prev) => ({ ...prev, endDate: event.target.value }))}
                className="mt-1 w-full rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
              />
            </label>

            <div className="flex items-end gap-2">
              <Button variant="primary" className="w-full" onClick={applyFilters}>Filtrar</Button>
              <Button variant="secondary" className="w-full" onClick={clearFilters}>Limpar</Button>
            </div>
          </div>
        </Card>

        <Card className="overflow-hidden p-0">
          <div className="app-responsive-table-wrap overflow-x-auto">
            <table className="app-responsive-table min-w-full text-sm">
              <thead className="bg-[#f8fafc]">
                <tr className="border-b border-[#e2e8f0] text-left text-[#64748b]">
                  <th className="px-4 py-3 font-medium">Ação</th>
                  <th className="px-4 py-3 font-medium">Usuário</th>
                  <th className="px-4 py-3 font-medium">Entidade</th>
                  <th className="px-4 py-3 font-medium">Data</th>
                  <th className="px-4 py-3 font-medium">Detalhes</th>
                </tr>
              </thead>
              <tbody>
                {!rows.length ? (
                  <tr className="border-b border-[#f1f5f9]">
                    <td data-label="Info" colSpan={5} className="px-4 py-4 text-sm text-[#64748b]">
                      Nenhum log encontrado.
                    </td>
                  </tr>
                ) : null}

                {rows.map((item) => (
                  <tr key={item.id} className="align-top border-b border-[#f1f5f9]">
                    <td data-label="Ação" className="px-4 py-3 text-[#0f172a]">{getActionLabel(item)}</td>
                    <td data-label="Usuário" className="px-4 py-3 text-[#334155]">{getUserLabel(item)}</td>
                    <td data-label="Entidade" className="px-4 py-3 text-[#334155]">{getEntityLabel(item)}</td>
                    <td data-label="Data" className="px-4 py-3 text-[#334155]">{formatDateTime(item.created_at)}</td>
                    <td data-label="Detalhes" className="px-4 py-3">
                      <Button
                        variant="secondary"
                        className="px-3 py-1.5 text-xs"
                        onClick={() => void openDetail(item.id)}
                      >
                        Ver detalhes
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="flex items-center justify-between border-t border-[#e2e8f0] px-4 py-3">
            <p className="text-xs text-[#64748b]">
              Mostrando {from}-{to} de {total}
            </p>

            <div className="flex items-center gap-2">
              <Button
                variant="secondary"
                className="px-3 py-1.5 text-xs"
                onClick={() => setPage((prev) => Math.max(1, prev - 1))}
                disabled={currentPage <= 1}
              >
                Anterior
              </Button>
              <span className="min-w-[96px] text-center text-xs text-[#475569]">
                Página {currentPage} de {Math.max(1, lastPage)}
              </span>
              <Button
                variant="secondary"
                className="px-3 py-1.5 text-xs"
                onClick={() => setPage((prev) => prev + 1)}
                disabled={currentPage >= lastPage}
              >
                Próxima
              </Button>
            </div>
          </div>
        </Card>

        {(detailLoading || detailError || detailItem) && (
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
                <h2 className="text-lg font-semibold text-[#0f172a]">Detalhes do log</h2>
                <button
                  type="button"
                  className="rounded-md p-1.5 text-[#64748b] hover:bg-[#f1f5f9]"
                  onClick={closeDetail}
                >
                  x
                </button>
              </div>

              {detailLoading ? <p className="text-sm text-[#64748b]">Carregando detalhe...</p> : null}
              {detailError ? <p className="text-sm text-red-600">{detailError}</p> : null}

              {detailItem ? (
                <>
                  <div className="mb-4 grid grid-cols-1 gap-2 text-sm md:grid-cols-2">
                    {detailFields.map(([label, value]) => (
                      <p key={label}>
                        <strong>{label}:</strong> {value}
                      </p>
                    ))}
                  </div>

                  {(detailOldData || detailNewData) ? (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                      {detailOldData ? (
                        <div>
                          <h3 className="mb-2 text-sm font-semibold text-[#0f172a]">Dados anteriores</h3>
                          <pre className="max-h-72 overflow-auto rounded-lg bg-[#0f172a] p-3 text-xs text-[#e2e8f0]">
                            {detailOldData}
                          </pre>
                        </div>
                      ) : null}

                      {detailNewData ? (
                        <div>
                          <h3 className="mb-2 text-sm font-semibold text-[#0f172a]">Dados novos</h3>
                          <pre className="max-h-72 overflow-auto rounded-lg bg-[#0f172a] p-3 text-xs text-[#e2e8f0]">
                            {detailNewData}
                          </pre>
                        </div>
                      ) : null}
                    </div>
                  ) : (
                    <p className="text-sm text-[#64748b]">Sem dados adicionais para este registro.</p>
                  )}
                </>
              ) : null}
            </div>
          </div>
        )}
      </PageState>
    </Layout>
  );
}

export default CompanyAuditPage;

