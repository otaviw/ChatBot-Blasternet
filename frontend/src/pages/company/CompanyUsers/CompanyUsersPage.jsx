import './CompanyUsersPage.css';
import UsersPage from "@/components/pages/UsersPage/UsersPage.jsx";
import Layout from "@/components/layout/Layout/Layout.jsx";
import usePageData from "@/hooks/usePageData";
import useLogout from "@/hooks/useLogout";
import LoadingSkeleton from "@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx";

export default function CompanyUsersPage() {
  const { data, loading, error } = usePageData('/me');
  const { logout } = useLogout();
  const canManageUsers = Boolean(
    data?.user?.can_manage_users || (data?.user?.role === 'company_admin' && data?.user?.company_id)
  );

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <div className="space-y-3">
          <LoadingSkeleton className="h-6 w-44" />
          <LoadingSkeleton className="h-4 w-80 max-w-full" />
          <LoadingSkeleton className="h-64 w-full" />
        </div>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-red-600">Nao foi possivel carregar usuarios.</p>
      </Layout>
    );
  }

  if (!canManageUsers) {
    return (
      <Layout role="company" companyName={data?.user?.company_name} onLogout={logout}>
        <p className="text-sm text-[#64748b]">Acesso restrito a admin da empresa.</p>
      </Layout>
    );
  }

  return <UsersPage scope="company" />;
}
