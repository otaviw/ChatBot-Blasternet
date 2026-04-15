import { useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import PageState from '@/components/ui/PageState/PageState.jsx';
import usePageData from '@/hooks/usePageData';
import useAuth from '@/hooks/useAuth';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';

const PAGE_SIZE = 20;

function formatDateTime(value) {
  if (!value) return '-';
  const ts = new Date(value).getTime();
  if (!Number.isFinite(ts)) return '-';
  return new Date(ts).toLocaleString('pt-BR');
}

function toPrettyJson(value) {
  if (!value) return '{}';
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return '{}';
  }
}

function buildUrl(filters, page) {
  const params = new URLSearchParams();
  if (filters.action) params.set('action', filters.action);
  if (filters.startDate) params.set('start_date', filters.startDate);
  if (filters.endDate) params.set('end_date', filters.endDate);
  params.set('page', String(page));
  params.set('per_page', String(PAGE_SIZE));
  return `/minha-conta/audit-logs?${params.toString()}`;
}

function CompanyAuditPage() {
  const { user } = useAuth();
  const { logout } = useLogout();
  const layoutRole = user?.role === 'system_admin' ? 'admin' : 'company';

  const [filters, setFilters] = useState({ action: '', startDate: '', endDate: '' });
  const [draft, setDraft] = useState({ action: '', startDate: '', endDate: '' });
  const [page, setPage] = useState(1);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [detailItem, setDetailItem] = useState(null);

  const dataUrl = useMemo(() => buildUrl(filters, page), [filters, page]);
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

  const openDetail = async (id) => {
    setDetailLoading(true);
    setDetailError('');
    setDetailItem(null);
    try {
      const response = await api.get(`/minha-conta/audit-logs/${id}`);
      setDetailItem(response.data?.item ?? null);
    } catch (err) {
      setDetailError(err.response?.data?.message ?? 'Nao foi possivel carregar o detalhe do log.');
    } finally {
      setDetailLoading(false);
    }
  };

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
        subtitle="Consulte eventos auditados com filtros por acao e data."
      />

      <Card className="mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
          <label className="text-sm text-[#334155]">
            Acao
            <input
              type="text"
              value={draft.action}
              onChange={(event) => setDraft((prev) => ({ ...prev, action: event.target.value }))}
              placeholder="Ex.: send_message"
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

      <Card className="p-0 overflow-hidden">
        <div className="overflow-x-auto app-responsive-table-wrap">
          <table className="min-w-full text-sm app-responsive-table">
            <thead className="bg-[#f8fafc]">
              <tr className="border-b border-[#e2e8f0] text-left text-[#64748b]">
                <th className="px-4 py-3 font-medium">Acao</th>
                <th className="px-4 py-3 font-medium">Usuario</th>
                <th className="px-4 py-3 font-medium">Entidade</th>
                <th className="px-4 py-3 font-medium">Data</th>
                <th className="px-4 py-3 font-medium">Acao</th>
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
                <tr key={item.id} className="border-b border-[#f1f5f9] align-top">
                  <td data-label="Acao" className="px-4 py-3 text-[#0f172a]">{item.action || '-'}</td>
                  <td data-label="Usuario" className="px-4 py-3 text-[#334155]">{item.user_name || '-'}</td>
                  <td data-label="Entidade" className="px-4 py-3 text-[#334155]">
                    {item.entity_type || '-'}{item.entity_id ? ` #${item.entity_id}` : ''}
                  </td>
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

        <div className="border-t border-[#e2e8f0] px-4 py-3 flex items-center justify-between">
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
            <span className="text-xs text-[#475569] min-w-[96px] text-center">
              Pagina {currentPage} de {Math.max(1, lastPage)}
            </span>
            <Button
              variant="secondary"
              className="px-3 py-1.5 text-xs"
              onClick={() => setPage((prev) => prev + 1)}
              disabled={currentPage >= lastPage}
            >
              Proxima
            </Button>
          </div>
        </div>
      </Card>

      {(detailLoading || detailError || detailItem) && (
        <div
          className="fixed inset-0 z-[90] flex items-center justify-center bg-black/40 p-4"
          onClick={() => {
            if (detailLoading) return;
            setDetailItem(null);
            setDetailError('');
          }}
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
                onClick={() => {
                  if (detailLoading) return;
                  setDetailItem(null);
                  setDetailError('');
                }}
              >
                x
              </button>
            </div>

            {detailLoading ? <p className="text-sm text-[#64748b]">Carregando detalhe...</p> : null}
            {detailError ? <p className="text-sm text-red-600">{detailError}</p> : null}
            {detailItem ? (
              <>
                <div className="grid grid-cols-1 gap-2 text-sm md:grid-cols-2 mb-4">
                  <p><strong>Acao:</strong> {detailItem.action || '-'}</p>
                  <p><strong>Usuario:</strong> {detailItem.user_name || '-'}</p>
                  <p><strong>Entidade:</strong> {detailItem.entity_type || '-'}</p>
                  <p><strong>ID entidade:</strong> {detailItem.entity_id || '-'}</p>
                  <p><strong>Data:</strong> {formatDateTime(detailItem.created_at)}</p>
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div>
                    <h3 className="text-sm font-semibold text-[#0f172a] mb-2">old_data</h3>
                    <pre className="max-h-72 overflow-auto rounded-lg bg-[#0f172a] p-3 text-xs text-[#e2e8f0]">
                      {toPrettyJson(detailItem.old_data)}
                    </pre>
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold text-[#0f172a] mb-2">new_data</h3>
                    <pre className="max-h-72 overflow-auto rounded-lg bg-[#0f172a] p-3 text-xs text-[#e2e8f0]">
                      {toPrettyJson(detailItem.new_data)}
                    </pre>
                  </div>
                </div>
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
