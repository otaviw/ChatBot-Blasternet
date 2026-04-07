import { useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import usePageData from '@/hooks/usePageData';
import useAuth from '@/hooks/useAuth';
import useLogout from '@/hooks/useLogout';
import useAdminCompanySelector from '@/hooks/useAdminCompanySelector';

const PERIOD_OPTIONS = [
  { value: 7, label: 'Últimos 7 dias' },
  { value: 30, label: 'Últimos 30 dias' },
];

function SummaryCard({ title, value }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-[#64748b]">{title}</p>
      <p className="mt-2 text-2xl font-semibold text-[#0f172a]">{value}</p>
    </Card>
  );
}

function CompanyAiAnalyticsPage() {
  const [periodDays, setPeriodDays] = useState(7);
  const { user } = useAuth();
  const { logout } = useLogout();
  const isAdmin = user?.role === 'system_admin';

  const { companies, selectedCompanyId, setSelectedCompanyId } = useAdminCompanySelector({ isAdmin });

  const companyParam = isAdmin
    ? (selectedCompanyId === 'all' || selectedCompanyId === '' ? 'all' : selectedCompanyId)
    : '';

  const url = isAdmin
    ? `/minha-conta/ia/analytics?days=${periodDays}&company_id=${companyParam}`
    : `/minha-conta/ia/analytics?days=${periodDays}`;

  const { data, loading, error } = usePageData(url);

  const layoutRole = isAdmin ? 'admin' : 'company';

  const dailyData = useMemo(() => (Array.isArray(data?.daily_messages) ? data.daily_messages : []), [data]);
  const usageByUser = useMemo(() => (Array.isArray(data?.usage_by_user) ? data.usage_by_user : []), [data]);
  const toolsUsage = useMemo(() => (Array.isArray(data?.tools_usage) ? data.tools_usage : []), [data]);
  const maxDailyCount = useMemo(
    () => dailyData.reduce((max, item) => Math.max(max, Number(item?.count ?? 0)), 0),
    [dailyData]
  );

  const showCompanyColumn = isAdmin && selectedCompanyId === 'all';

  if (loading) {
    return (
      <Layout role={layoutRole} onLogout={logout}>
        <p className="text-sm text-[#64748b]">Carregando analytics da IA...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout role={layoutRole} onLogout={logout}>
        <p className="text-sm text-red-600">Não foi possível carregar analytics da IA.</p>
      </Layout>
    );
  }

  return (
    <Layout role={layoutRole} onLogout={logout}>
      <PageHeader
        title="Analytics da IA"
        subtitle="Acompanhe o uso da IA com métricas de mensagens, usuários e tools."
        action={(
          <div className="flex items-center gap-2 flex-wrap">
            {isAdmin && companies.length > 0 && (
              <select
                value={selectedCompanyId}
                onChange={(e) => setSelectedCompanyId(e.target.value)}
                className="rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm text-[#1f2937] outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
              >
                <option value="all">Todas as empresas</option>
                {companies.map((c) => (
                  <option key={c.id} value={String(c.id)}>{c.name}</option>
                ))}
              </select>
            )}
            <select
              value={String(periodDays)}
              onChange={(event) => setPeriodDays(Number(event.target.value))}
              className="rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm text-[#1f2937] outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
            >
              {PERIOD_OPTIONS.map((option) => (
                <option key={option.value} value={String(option.value)}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        )}
      />

      <section className="grid grid-cols-1 gap-3 md:grid-cols-3">
        <SummaryCard title="Total de mensagens IA" value={Number(data?.summary?.total_messages ?? 0)} />
        <SummaryCard title="Total no mês" value={Number(data?.summary?.total_month ?? 0)} />
        <SummaryCard title="Usuários que usaram no período" value={Number(data?.summary?.total_users_period ?? 0)} />
      </section>

      <section className="mt-4">
        <Card className="p-4">
          <h2 className="text-base font-semibold text-[#0f172a]">Mensagens por dia</h2>
          <p className="mt-1 text-sm text-[#64748b]">Período selecionado: {periodDays} dias</p>

          {!dailyData.length ? (
            <p className="mt-4 text-sm text-[#64748b]">Sem dados no período.</p>
          ) : (
            <div className="mt-4 flex h-56 items-end gap-2 overflow-x-auto">
              {dailyData.map((item) => {
                const count = Number(item?.count ?? 0);
                const heightPercent = maxDailyCount > 0 ? Math.max((count / maxDailyCount) * 100, 4) : 4;
                return (
                  <div
                    key={String(item?.date ?? item?.label)}
                    className="flex min-w-[34px] flex-col items-center justify-end gap-2"
                    title={`${item?.label}: ${count}`}
                  >
                    <span className="text-[11px] text-[#475569]">{count}</span>
                    <div
                      className="w-7 rounded-t-md bg-[#3b82f6]"
                      style={{ height: `${heightPercent}%` }}
                    />
                    <span className="text-[11px] text-[#64748b]">{item?.label}</span>
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      </section>

      <section className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="p-0 overflow-hidden">
          <div className="border-b border-[#e2e8f0] px-4 py-3">
            <h2 className="text-base font-semibold text-[#0f172a]">Uso por usuário</h2>
          </div>
          {!usageByUser.length ? (
            <p className="px-4 py-4 text-sm text-[#64748b]">Nenhum uso de IA por usuário no período.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead className="bg-[#f8fafc]">
                  <tr className="border-b border-[#e2e8f0] text-left text-[#64748b]">
                    <th className="px-4 py-3 font-medium">Nome</th>
                    {showCompanyColumn && <th className="px-4 py-3 font-medium">Empresa</th>}
                    <th className="px-4 py-3 font-medium">Mensagens</th>
                  </tr>
                </thead>
                <tbody>
                  {usageByUser.map((item) => (
                    <tr key={`${item.user_id}-${item.company_name ?? ''}`} className="border-b border-[#f1f5f9]">
                      <td className="px-4 py-3 text-[#0f172a]">{item.name || '-'}</td>
                      {showCompanyColumn && <td className="px-4 py-3 text-[#334155]">{item.company_name || '-'}</td>}
                      <td className="px-4 py-3 text-[#334155]">{Number(item.count ?? 0)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card className="p-0 overflow-hidden">
          <div className="border-b border-[#e2e8f0] px-4 py-3">
            <h2 className="text-base font-semibold text-[#0f172a]">Tools mais usadas</h2>
          </div>
          {!toolsUsage.length ? (
            <p className="px-4 py-4 text-sm text-[#64748b]">Nenhuma tool usada no período.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead className="bg-[#f8fafc]">
                  <tr className="border-b border-[#e2e8f0] text-left text-[#64748b]">
                    <th className="px-4 py-3 font-medium">Tool</th>
                    <th className="px-4 py-3 font-medium">Usos</th>
                  </tr>
                </thead>
                <tbody>
                  {toolsUsage.map((item, index) => (
                    <tr key={`${item.tool}-${index}`} className="border-b border-[#f1f5f9]">
                      <td className="px-4 py-3 text-[#0f172a]">{item.tool || '-'}</td>
                      <td className="px-4 py-3 text-[#334155]">{Number(item.count ?? 0)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </section>
    </Layout>
  );
}

export default CompanyAiAnalyticsPage;
