import './AdminUsersPage.css';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';

function AdminUsersPage() {
  const { data, loading, error } = usePageData('/admin/users');
  const { logout } = useLogout();

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando resumo de usuários...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Não foi possível carregar o resumo de usuários.</p>
      </Layout>
    );
  }

  const summary = data.users_summary ?? { global: {}, companies: [] };
  const global = summary.global ?? {};
  const companies = summary.companies ?? [];

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Usuarios (modo privacidade)</h1>
      <p className="text-[#706f6c] text-sm mb-6">
        O superadmin visualiza apenas dados agregados. Detalhes pessoais de usuários ficam ocultos.
      </p>

      <section className="mb-6 border border-[#e3e3e0] rounded-lg p-4">
        <h2 className="font-medium mb-3">Resumo global</h2>
        <ul className="text-sm space-y-1">
          <li>Total: {global.total ?? 0}</li>
          <li>Ativos: {global.active ?? 0}</li>
          <li>Inativos: {global.inactive ?? 0}</li>
        </ul>
      </section>

      <section className="border border-[#e3e3e0] rounded-lg p-4">
        <h2 className="font-medium mb-3">Por empresa</h2>
        {!companies.length && <p className="text-sm text-[#706f6c]">Nenhuma empresa com usuários.</p>}
        {!!companies.length && (
          <ul className="space-y-2 text-sm">
            {companies.map((company) => (
              <li key={company.company_id} className="border border-[#e3e3e0] rounded p-3">
                <p className="font-medium">{company.company_name}</p>
                <p className="text-[#706f6c]">
                  Total: {company.total ?? 0} | Ativos: {company.active ?? 0} | Inativos: {company.inactive ?? 0}
                </p>
              </li>
            ))}
          </ul>
        )}
      </section>
    </Layout>
  );
}

export default AdminUsersPage;

