import './CompanyUsersPage.css';
import UsersPage from "@/pages/Users/UsersPage.jsx";
import Layout from "@/components/layout/Layout/Layout.jsx";
import usePageData from "@/hooks/usePageData";
import useLogout from "@/hooks/useLogout";

export default function CompanyUsersPage() {
  const { data, loading, error } = usePageData('/dashboard');
  const { logout } = useLogout();

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#64748b]">Carregando usuários...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-red-600">Não foi possível carregar usuários.</p>
      </Layout>
    );
  }

  if (!data?.can_manage_users) {
    return (
      <Layout role="company" companyName={data.companyName} onLogout={logout}>
        <p className="text-sm text-[#64748b]">Acesso restrito a admin da empresa.</p>
      </Layout>
    );
  }

  return <UsersPage scope="company" />;
}


