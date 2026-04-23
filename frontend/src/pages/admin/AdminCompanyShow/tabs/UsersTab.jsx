import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';

function UsersTab({ company, metricsLoading, metricsData }) {
  const totalUsers = metricsData?.metrics?.total_users ?? company?.users_count ?? 0;
  const users = Array.isArray(company?.users) ? company.users : [];

  return (
    <section className="app-panel mb-8">
      <h2 className="font-medium mb-4">Usuarios</h2>

      {metricsLoading ? (
        <LoadingSkeleton className="h-24 w-full" />
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
          <div className="rounded-lg bg-[#fafafa] px-4 py-3">
            <p className="text-xs font-medium text-[#737373] uppercase tracking-wider">Total de usuários</p>
            <p className="mt-1 text-2xl font-semibold text-[#171717]">{totalUsers}</p>
          </div>
          <div className="rounded-lg bg-[#fafafa] px-4 py-3">
            <p className="text-xs font-medium text-[#737373] uppercase tracking-wider">Visibilidade</p>
            <p className="mt-1 text-sm text-[#171717]">
              O modo privacidade pode ocultar dados sensiveis no painel de superadmin.
            </p>
          </div>
        </div>
      )}

      {users.length > 0 ? (
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-left text-[#737373] border-b border-[#e5e5e5]">
                <th className="py-2 pr-4 font-medium">Nome</th>
                <th className="py-2 pr-4 font-medium">Email</th>
                <th className="py-2 pr-4 font-medium">Perfil</th>
              </tr>
            </thead>
            <tbody>
              {users.map((user) => (
                <tr key={user.id ?? user.email ?? user.name} className="border-b border-[#f2f2f2]">
                  <td className="py-2 pr-4">{user.name ?? '-'}</td>
                  <td className="py-2 pr-4">{user.email ?? '-'}</td>
                  <td className="py-2 pr-4">{user.role ?? user.profile ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <p className="text-sm text-[#737373]">
          Não há lista de usuários neste payload. O total segue disponível nas métricas.
        </p>
      )}
    </section>
  );
}

export default UsersTab;
