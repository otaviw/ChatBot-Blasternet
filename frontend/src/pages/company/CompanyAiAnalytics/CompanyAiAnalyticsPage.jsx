import { useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import usePageData from '@/hooks/usePageData';
import useAuth from '@/hooks/useAuth';
import useLogout from '@/hooks/useLogout';
import useAdminCompanySelector from '@/hooks/useAdminCompanySelector';


const PERIOD_OPTIONS = [
  { value: 7, label: 'Últimos 7 dias' },
  { value: 30, label: 'Últimos 30 dias' },
];

const FEATURE_LABELS = {
  internal_chat: 'Chat interno',
  conversation_suggestion: 'Sugestão de resposta',
  chatbot: 'Chatbot automático',
};

const ERROR_LABELS = {
  timeout: 'Timeout',
  provider_unavailable: 'Provider indisponível',
  validation: 'Validação',
  unknown: 'Desconhecido',
};


function formatMs(ms) {
  if (ms == null) return '—';
  if (ms >= 1000) return `${(ms / 1000).toFixed(1)}s`;
  return `${ms}ms`;
}

function formatNum(n) {
  if (n == null) return '—';
  return Number(n).toLocaleString('pt-BR');
}

function daysAgoDateString(days) {
  const d = new Date();
  d.setDate(d.getDate() - (days - 1));
  return d.toISOString().slice(0, 10);
}

function todayDateString() {
  return new Date().toISOString().slice(0, 10);
}


function SummaryCard({ title, value, sub }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-[#64748b]">{title}</p>
      <p className="mt-2 text-2xl font-semibold text-[#0f172a]">{value}</p>
      {sub && <p className="mt-1 text-xs text-[#94a3b8]">{sub}</p>}
    </Card>
  );
}

function SimpleTable({ cols, rows, emptyText = 'Sem dados no período.' }) {
  if (!rows.length) {
    return <p className="px-4 py-4 text-sm text-[#64748b]">{emptyText}</p>;
  }
  return (
    <div className="overflow-x-auto app-responsive-table-wrap">
      <table className="min-w-full text-sm app-responsive-table">
        <thead className="bg-[#f8fafc]">
          <tr className="border-b border-[#e2e8f0] text-left text-[#64748b]">
            {cols.map((col) => (
              <th key={col.key} className="px-4 py-3 font-medium">{col.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr key={i} className="border-b border-[#f1f5f9]">
              {cols.map((col) => (
                <td key={col.key} data-label={col.label} className="px-4 py-3 text-[#334155]">
                  {col.render ? col.render(row) : (row[col.key] ?? '—')}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function BarChart({ data, valueKey, labelKey, colorClass = 'bg-[#3b82f6]' }) {
  const max = data.reduce((m, item) => Math.max(m, Number(item[valueKey] ?? 0)), 0);
  if (!data.length) return <p className="mt-4 text-sm text-[#64748b]">Sem dados no período.</p>;
  return (
    <div className="mt-4 flex h-56 items-end gap-2 overflow-x-auto">
      {data.map((item) => {
        const val = Number(item[valueKey] ?? 0);
        const heightPercent = max > 0 ? Math.max((val / max) * 100, 4) : 4;
        return (
          <div
            key={String(item[labelKey])}
            className="flex min-w-[34px] flex-col items-center justify-end gap-2"
            title={`${item[labelKey]}: ${val}`}
          >
            <span className="text-[11px] text-[#475569]">{val > 0 ? val : ''}</span>
            <div className={`w-7 rounded-t-md ${colorClass}`} style={{ height: `${heightPercent}%` }} />
            <span className="text-[11px] text-[#64748b]">{item[labelKey]}</span>
          </div>
        );
      })}
    </div>
  );
}

function ErrorBadge({ pct }) {
  const n = Number(pct ?? 0);
  const color = n === 0 ? 'text-[#16a34a]' : n < 5 ? 'text-[#d97706]' : 'text-[#dc2626]';
  return <span className={`font-medium ${color}`}>{n.toFixed(2)}%</span>;
}


function TabBar({ active, onChange }) {
  const tabs = [
    { id: 'uso', label: 'Uso' },
    { id: 'metricas', label: 'Métricas técnicas' },
  ];
  return (
    <div className="flex gap-0 border-b border-[#e2e8f0] mb-4">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          type="button"
          onClick={() => onChange(tab.id)}
          className={[
            'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
            active === tab.id
              ? 'border-[#2563eb] text-[#2563eb]'
              : 'border-transparent text-[#64748b] hover:text-[#0f172a]',
          ].join(' ')}
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}


function TabUso({ data, showCompanyColumn }) {
  const dailyData = Array.isArray(data?.daily_messages) ? data.daily_messages : [];
  const usageByUser = Array.isArray(data?.usage_by_user) ? data.usage_by_user : [];
  const toolsUsage = Array.isArray(data?.tools_usage) ? data.tools_usage : [];

  return (
    <>
      <section className="grid grid-cols-1 gap-3 md:grid-cols-3">
        <SummaryCard title="Total de mensagens IA" value={formatNum(data?.summary?.total_messages)} />
        <SummaryCard title="Total no mês" value={formatNum(data?.summary?.total_month)} />
        <SummaryCard title="Usuários no período" value={formatNum(data?.summary?.total_users_period)} />
      </section>

      <section className="mt-4">
        <Card className="p-4">
          <h2 className="text-base font-semibold text-[#0f172a]">Mensagens por dia</h2>
          <BarChart data={dailyData} valueKey="count" labelKey="label" />
        </Card>
      </section>

      <section className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="p-0 overflow-hidden">
          <div className="border-b border-[#e2e8f0] px-4 py-3">
            <h2 className="text-base font-semibold text-[#0f172a]">Uso por usuário</h2>
          </div>
          <SimpleTable
            cols={[
              { key: 'name', label: 'Nome' },
              ...(showCompanyColumn ? [{ key: 'company_name', label: 'Empresa' }] : []),
              { key: 'count', label: 'Mensagens', render: (row) => formatNum(row.count) },
            ]}
            rows={usageByUser}
            emptyText="Nenhum uso de IA por usuário no período."
          />
        </Card>

        <Card className="p-0 overflow-hidden">
          <div className="border-b border-[#e2e8f0] px-4 py-3">
            <h2 className="text-base font-semibold text-[#0f172a]">Tools mais usadas</h2>
          </div>
          <SimpleTable
            cols={[
              { key: 'tool', label: 'Tool' },
              { key: 'count', label: 'Usos', render: (row) => formatNum(row.count) },
            ]}
            rows={toolsUsage}
            emptyText="Nenhuma tool usada no período."
          />
        </Card>
      </section>
    </>
  );
}


function TabMetricas({ data }) {
  const summary = data?.summary ?? {};
  const byFeature = Array.isArray(data?.by_feature) ? data.by_feature : [];
  const byProvider = Array.isArray(data?.by_provider) ? data.by_provider : [];
  const byErrorType = Array.isArray(data?.by_error_type) ? data.by_error_type : [];
  const daily = Array.isArray(data?.daily) ? data.daily : [];

  const featureCols = [
    { key: 'feature', label: 'Feature', render: (row) => FEATURE_LABELS[row.feature] ?? row.feature },
    { key: 'total', label: 'Chamadas', render: (row) => formatNum(row.total) },
    { key: 'error', label: 'Erros', render: (row) => formatNum(row.error) },
    {
      key: 'error_rate', label: 'Taxa de erro',
      render: (row) => <ErrorBadge pct={row.total > 0 ? (row.error / row.total) * 100 : 0} />,
    },
    { key: 'avg_ms', label: 'Latência média', render: (row) => formatMs(row.avg_ms) },
    { key: 'tokens', label: 'Tokens', render: (row) => formatNum(row.tokens) },
  ];

  const providerCols = [
    { key: 'provider', label: 'Provider' },
    { key: 'total', label: 'Chamadas', render: (row) => formatNum(row.total) },
    { key: 'error', label: 'Erros', render: (row) => formatNum(row.error) },
    {
      key: 'error_rate', label: 'Taxa de erro',
      render: (row) => <ErrorBadge pct={row.total > 0 ? (row.error / row.total) * 100 : 0} />,
    },
    { key: 'avg_ms', label: 'Latência média', render: (row) => formatMs(row.avg_ms) },
    { key: 'tokens', label: 'Tokens', render: (row) => formatNum(row.tokens) },
  ];

  const errorCols = [
    { key: 'error_type', label: 'Tipo de erro', render: (row) => ERROR_LABELS[row.error_type] ?? row.error_type },
    { key: 'count', label: 'Ocorrências', render: (row) => formatNum(row.count) },
  ];

  return (
    <>
      <section className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <SummaryCard
          title="Total de chamadas"
          value={formatNum(summary.total_requests)}
          sub={`${formatNum(summary.ok_count)} ok · ${formatNum(summary.error_count)} erro`}
        />
        <SummaryCard
          title="Taxa de erro"
          value={<ErrorBadge pct={summary.error_rate_pct} />}
        />
        <SummaryCard
          title="Latência média"
          value={formatMs(summary.avg_response_time_ms)}
          sub={summary.p95_response_time_ms != null ? `P95: ${formatMs(summary.p95_response_time_ms)}` : null}
        />
        <SummaryCard
          title="Tokens consumidos"
          value={formatNum(summary.total_tokens)}
        />
      </section>

      <section className="mt-4">
        <Card className="p-4">
          <h2 className="text-base font-semibold text-[#0f172a]">Chamadas por dia</h2>
          <BarChart data={daily} valueKey="total" labelKey="label" />
        </Card>
      </section>

      <section className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="p-0 overflow-hidden">
          <div className="border-b border-[#e2e8f0] px-4 py-3">
            <h2 className="text-base font-semibold text-[#0f172a]">Por feature</h2>
          </div>
          <SimpleTable cols={featureCols} rows={byFeature} />
        </Card>

        <Card className="p-0 overflow-hidden">
          <div className="border-b border-[#e2e8f0] px-4 py-3">
            <h2 className="text-base font-semibold text-[#0f172a]">Por provider</h2>
          </div>
          <SimpleTable cols={providerCols} rows={byProvider} emptyText="Nenhum dado de provider no período." />
        </Card>
      </section>

      {byErrorType.length > 0 && (
        <section className="mt-4">
          <Card className="p-0 overflow-hidden">
            <div className="border-b border-[#e2e8f0] px-4 py-3">
              <h2 className="text-base font-semibold text-[#0f172a]">Distribuição de erros</h2>
            </div>
            <SimpleTable cols={errorCols} rows={byErrorType} />
          </Card>
        </section>
      )}
    </>
  );
}


function CompanyAiAnalyticsPage() {
  const [activeTab, setActiveTab] = useState('uso');
  const [periodDays, setPeriodDays] = useState(7);

  const { user } = useAuth();
  const { logout } = useLogout();
  const isAdmin = user?.role === 'system_admin';

  const { companies, selectedCompanyId, setSelectedCompanyId } = useAdminCompanySelector({ isAdmin });

  const companyParam = isAdmin
    ? (selectedCompanyId === 'all' || selectedCompanyId === '' ? 'all' : selectedCompanyId)
    : '';

  const analyticsUrl = isAdmin
    ? `/minha-conta/ia/analytics?days=${periodDays}&company_id=${companyParam}`
    : `/minha-conta/ia/analytics?days=${periodDays}`;

  const metricsUrl = useMemo(() => {
    const dateFrom = daysAgoDateString(periodDays);
    const dateTo = todayDateString();
    const base = `/minha-conta/ia/metricas?date_from=${dateFrom}&date_to=${dateTo}`;
    return isAdmin ? `${base}&company_id=${companyParam}` : base;
  }, [periodDays, isAdmin, companyParam]);

  const { data: analyticsData, loading: analyticsLoading, error: analyticsError } = usePageData(analyticsUrl);
  const { data: metricsData, loading: metricsLoading, error: metricsError } = usePageData(metricsUrl);

  const layoutRole = isAdmin ? 'admin' : 'company';
  const showCompanyColumn = isAdmin && selectedCompanyId === 'all';

  const loading = activeTab === 'uso' ? analyticsLoading : metricsLoading;
  const error = activeTab === 'uso' ? analyticsError : metricsError;
  const notAuth = activeTab === 'uso' ? !analyticsData?.authenticated : !metricsData?.authenticated;

  if (loading) {
    return (
      <Layout role={layoutRole} onLogout={logout}>
        <PageLoading rows={1} cards={4} cardsGridClassName="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4" />
      </Layout>
    );
  }

  if (error || notAuth) {
    return (
      <Layout role={layoutRole} onLogout={logout}>
        <p className="text-sm text-red-600">Não foi possível carregar os dados de IA.</p>
      </Layout>
    );
  }

  return (
    <Layout role={layoutRole} onLogout={logout}>
      <PageHeader
        title="Analytics da IA"
        subtitle="Acompanhe uso, latência, taxa de erro e custo de tokens."
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
              onChange={(e) => setPeriodDays(Number(e.target.value))}
              className="rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm text-[#1f2937] outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
            >
              {PERIOD_OPTIONS.map((o) => (
                <option key={o.value} value={String(o.value)}>{o.label}</option>
              ))}
            </select>
          </div>
        )}
      />

      <TabBar active={activeTab} onChange={setActiveTab} />

      {activeTab === 'uso' && (
        <TabUso data={analyticsData} showCompanyColumn={showCompanyColumn} />
      )}

      {activeTab === 'metricas' && (
        <TabMetricas data={metricsData} />
      )}
    </Layout>
  );
}

export default CompanyAiAnalyticsPage;
