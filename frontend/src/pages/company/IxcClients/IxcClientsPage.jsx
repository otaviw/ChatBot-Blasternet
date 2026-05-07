import Layout from '@/components/layout/Layout/Layout.jsx';
import useLogout from '@/hooks/useLogout';
import usePermissions from '@/hooks/usePermissions';
import { PERM } from '@/constants/permissions';
import { useEffect, useMemo, useState } from 'react';
import { listIxcClients } from '@/services/ixcService';
import { useNavigate } from 'react-router-dom';

function IxcClientsPage() {
  const { logout } = useLogout();
  const { can } = usePermissions();
  const navigate = useNavigate();
  const canViewInvoices = can(PERM.IXC_INVOICES_VIEW);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState({ page: 1, per_page: 30, total: 0, has_next: false });

  const canPrev = useMemo(() => page > 1 && !loading, [page, loading]);
  const canNext = useMemo(() => Boolean(pagination?.has_next) && !loading, [pagination?.has_next, loading]);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError('');

    listIxcClients({
      q: query || undefined,
      page,
      per_page: 30,
    })
      .then((data) => {
        if (canceled) return;
        setItems(Array.isArray(data?.items) ? data.items : []);
        setPagination(data?.pagination ?? { page: 1, per_page: 30, total: 0, has_next: false });
      })
      .catch((err) => {
        if (canceled) return;
        setItems([]);
        setError(err?.message || 'Falha ao carregar clientes IXC.');
      })
      .finally(() => {
        if (!canceled) setLoading(false);
      });

    return () => {
      canceled = true;
    };
  }, [page, query]);

  return (
    <Layout role="company" onLogout={logout}>
      <h1 className="app-page-title">Clientes IXC</h1>
      <p className="app-page-subtitle mb-4">Consulta de clientes vinculados à integração IXC da empresa.</p>
      <section className="app-panel">
        <div className="flex flex-wrap gap-2 mb-4">
          <input
            type="text"
            value={query}
            onChange={(event) => {
              setPage(1);
              setQuery(event.target.value);
            }}
            className="app-input max-w-md"
            placeholder="Buscar por nome, fantasia ou documento"
          />
        </div>

        {error ? <p className="text-sm text-red-600 mb-3">{error}</p> : null}
        {loading ? <p className="text-sm text-[#525252]">Carregando clientes...</p> : null}

        {!loading && !error && items.length === 0 ? (
          <p className="text-sm text-[#525252]">Nenhum cliente encontrado para os filtros atuais.</p>
        ) : null}

        {!loading && items.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left border-b border-[#e5e5e5]">
                  <th className="py-2 pr-2">ID</th>
                  <th className="py-2 pr-2">Razão</th>
                  <th className="py-2 pr-2">Fantasia</th>
                  <th className="py-2 pr-2">Documento</th>
                  <th className="py-2 pr-2">Contato</th>
                </tr>
              </thead>
              <tbody>
                {items.map((client) => (
                  <tr
                    key={client.id}
                    className="border-b border-[#f0f0f0] hover:bg-[#fafafa] cursor-pointer"
                    onClick={() => navigate(`/minha-conta/ixc/clientes/${client.id}`)}
                  >
                    <td className="py-2 pr-2">{client.id}</td>
                    <td className="py-2 pr-2">{client.razao || '-'}</td>
                    <td className="py-2 pr-2">{client.fantasia || '-'}</td>
                    <td className="py-2 pr-2">{client.cpf_cnpj || '-'}</td>
                    <td className="py-2 pr-2">{client.email || client.telefone_celular || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}

        <div className="mt-4 flex items-center gap-2">
          <button type="button" className="app-btn-secondary" disabled={!canPrev} onClick={() => setPage((prev) => prev - 1)}>
            Anterior
          </button>
          <button type="button" className="app-btn-secondary" disabled={!canNext} onClick={() => setPage((prev) => prev + 1)}>
            Próxima
          </button>
          <span className="text-xs text-[#737373]">
            Página {pagination?.page || page} - total {pagination?.total ?? 0}
          </span>
        </div>

        {!canViewInvoices ? <p className="text-xs text-[#737373] mt-3">Seu usuário não possui permissão para visualizar boletos.</p> : null}
      </section>
    </Layout>
  );
}

export default IxcClientsPage;
