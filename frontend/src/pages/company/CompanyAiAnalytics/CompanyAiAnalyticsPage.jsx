import { useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import usePageData from '@/hooks/usePageData';
import useAuth from '@/hooks/useAuth';
import useLogout from '@/hooks/useLogout';
import useAdminCompanySelector from '@/hooks/useAdminCompanySelector';

const CHANNEL_LABELS = {
  all: 'Todos os canais',
  whatsapp: 'WhatsApp',
  internal_chat: 'Chat interno',
};

const FEATURE_LABELS = {
  internal_chat: 'Chat interno',
  conversation_suggestion: 'Sugestao de resposta',
  chatbot: 'Chatbot automatico',
};

const HANDOFF_TYPE_LABELS = {
  menu: 'Handoff por menu',
  incapacity: 'Handoff por incapacidade',
};

function todayDateString() {
  return new Date().toISOString().slice(0, 10);
}

function daysAgoDateString(days) {
  const date = new Date();
  date.setDate(date.getDate() - (days - 1));
  return date.toISOString().slice(0, 10);
}

function formatNum(value) {
  if (value === null || value === undefined || value === '') return '-';
  return Number(value).toLocaleString('pt-BR');
}

function formatPct(value) {
  if (value === null || value === undefined) return '-';
  return `${Number(value).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`;
}

function formatMs(value) {
  if (value === null || value === undefined) return '-';
  const ms = Number(value);
  return ms >= 1000 ? `${(ms / 1000).toFixed(1)}s` : `${ms}ms`;
}

function formatMoney(value, currency = 'USD') {
  const amount = Number(value ?? 0);
  return amount.toLocaleString('pt-BR', {
    style: 'currency',
    currency,
    minimumFractionDigits: 4,
    maximumFractionDigits: 4,
  });
}

function buildQuery(params) {
  const search = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '' || value === 'all') {
      return;
    }
    search.set(key, String(value));
  });

  const query = search.toString();
  return query ? `?${query}` : '';
}

function SummaryCard({ title, value, sub, tone = 'default' }) {
  const toneClass = {
    default: 'border-[var(--ui-border)]',
    good: 'border-emerald-200 bg-emerald-50/70',
    warn: 'border-amber-200 bg-amber-50/70',
    danger: 'border-red-200 bg-red-50/70',
  }[tone] ?? 'border-[var(--ui-border)]';

  return (
    <Card className={`border ${toneClass} p-4`}>
      <p className="text-sm text-[var(--ui-text-muted)]">{title}</p>
      <p className="mt-2 text-2xl font-semibold text-[var(--ui-text)]">{value}</p>
      {sub ? <p className="mt-1 text-xs text-[var(--ui-text-subtle)]">{sub}</p> : null}
    </Card>
  );
}

function SimpleTable({ columns, rows, emptyText = 'Sem dados no periodo.' }) {
  if (!rows.length) {
    return <p className="px-4 py-4 text-sm text-[var(--ui-text-muted)]">{emptyText}</p>;
  }

  return (
    <div className="overflow-x-auto app-responsive-table-wrap">
      <table className="min-w-full text-sm app-responsive-table">
        <thead className="bg-[var(--ui-surface-elevated)]">
          <tr className="border-b border-[var(--ui-border)] text-left text-[var(--ui-text-muted)]">
            {columns.map((column) => (
              <th key={column.key} className="px-4 py-3 font-medium">{column.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={row.id ?? `${row.intent ?? row.flow ?? row.type ?? 'row'}-${index}`} className="border-b border-[var(--ui-border)]">
              {columns.map((column) => (
                <td key={column.key} data-label={column.label} className="px-4 py-3 text-[var(--ui-text)]">
                  {column.render ? column.render(row) : (row[column.key] ?? '-')}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function BarChart({ data, bars }) {
  const max = data.reduce((current, item) => {
    const total = bars.reduce((sum, bar) => sum + Number(item[bar.key] ?? 0), 0);
    return Math.max(current, total);
  }, 0);

  if (!data.length) {
    return <p className="mt-4 text-sm text-[var(--ui-text-muted)]">Sem dados no periodo.</p>;
  }

  return (
    <div className="mt-4 flex h-64 items-end gap-3 overflow-x-auto pb-2">
      {data.map((item) => {
        const total = bars.reduce((sum, bar) => sum + Number(item[bar.key] ?? 0), 0);
        const height = max > 0 ? Math.max((total / max) * 100, 4) : 4;

        return (
          <div key={item.date} className="flex min-w-[42px] flex-col items-center justify-end gap-2">
            <span className="text-[11px] text-[var(--ui-text-muted)]">{total > 0 ? formatNum(total) : ''}</span>
            <div className="flex w-8 flex-col justify-end overflow-hidden rounded-t-lg bg-[var(--ui-surface-elevated)]" style={{ height: `${height}%` }}>
              {bars.map((bar) => {
                const value = Number(item[bar.key] ?? 0);
                const segmentHeight = total > 0 ? Math.max((value / total) * 100, value > 0 ? 8 : 0) : 0;
                return (
                  <div
                    key={bar.key}
                    title={`${bar.label}: ${value}`}
                    className={bar.className}
                    style={{ height: `${segmentHeight}%` }}
                  />
                );
              })}
            </div>
            <span className="text-[11px] text-[var(--ui-text-subtle)]">{item.label}</span>
          </div>
        );
      })}
    </div>
  );
}

function Section({ title, subtitle, children }) {
  return (
    <Card className="overflow-hidden p-0">
      <div className="border-b border-[var(--ui-border)] px-4 py-3">
        <h2 className="text-base font-semibold text-[var(--ui-text)]">{title}</h2>
        {subtitle ? <p className="mt-1 text-sm text-[var(--ui-text-muted)]">{subtitle}</p> : null}
      </div>
      {children}
    </Card>
  );
}

function FilterSelect({ label, value, onChange, children }) {
  return (
    <label className="flex min-w-[160px] flex-col gap-1 text-xs font-medium text-[var(--ui-text-muted)]">
      {label}
      <select
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="rounded-lg border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2 text-sm text-[var(--ui-text)] outline-none focus:border-[var(--ui-accent)] focus:ring-2 focus:ring-[var(--ui-ring)]"
      >
        {children}
      </select>
    </label>
  );
}

function DateInput({ label, value, onChange }) {
  return (
    <label className="flex min-w-[150px] flex-col gap-1 text-xs font-medium text-[var(--ui-text-muted)]">
      {label}
      <input
        type="date"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="rounded-lg border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2 text-sm text-[var(--ui-text)] outline-none focus:border-[var(--ui-accent)] focus:ring-2 focus:ring-[var(--ui-ring)]"
      />
    </label>
  );
}

function CompanyAiAnalyticsPage() {
  const [dateFrom, setDateFrom] = useState(() => daysAgoDateString(29));
  const [dateTo, setDateTo] = useState(() => todayDateString());
  const [channel, setChannel] = useState('all');
  const [areaId, setAreaId] = useState('all');
  const [flow, setFlow] = useState('all');

  const { user } = useAuth();
  const { logout } = useLogout();
  const isAdmin = user?.role === 'system_admin';
  const { companies, selectedCompanyId, setSelectedCompanyId } = useAdminCompanySelector({ isAdmin });

  const companyParam = isAdmin
    ? (selectedCompanyId === 'all' || selectedCompanyId === '' ? 'all' : selectedCompanyId)
    : null;

  const query = useMemo(() => buildQuery({
    company_id: companyParam,
    date_from: dateFrom,
    date_to: dateTo,
    channel,
    area_id: areaId,
    flow,
  }), [areaId, channel, companyParam, dateFrom, dateTo, flow]);

  const dashboardUrl = `/minha-conta/ia/analytics${query}`;
  const { data, loading, error } = usePageData(dashboardUrl);

  const layoutRole = isAdmin ? 'admin' : 'company';
  const summary = data?.summary ?? {};
  const options = data?.filter_options ?? {};
  const daily = Array.isArray(data?.daily) ? data.daily : [];
  const topIntents = Array.isArray(data?.top_intents) ? data.top_intents : [];
  const handoffByType = Array.isArray(data?.handoff_by_type) ? data.handoff_by_type : [];
  const handoffReasons = Array.isArray(data?.handoff_reasons) ? data.handoff_reasons : [];
  const bottlenecks = Array.isArray(data?.bottlenecks_by_flow) ? data.bottlenecks_by_flow : [];
  const byProvider = Array.isArray(data?.by_provider) ? data.by_provider : [];
  const byFeature = Array.isArray(data?.by_feature) ? data.by_feature : [];
  const areas = Array.isArray(options.areas) ? options.areas : [];
  const flows = Array.isArray(options.flows) ? options.flows : [];

  const csvUrl = data?.export_urls?.csv ?? `${dashboardUrl}${dashboardUrl.includes('?') ? '&' : '?'}export=csv`;
  const jsonUrl = data?.export_urls?.json ?? `${dashboardUrl}${dashboardUrl.includes('?') ? '&' : '?'}export=json`;

  if (loading) {
    return (
      <Layout role={layoutRole} onLogout={logout}>
        <PageLoading rows={1} cards={4} cardsGridClassName="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4" />
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout role={layoutRole} onLogout={logout}>
        <p className="text-sm text-red-600">Nao foi possivel carregar o dashboard de IA.</p>
      </Layout>
    );
  }

  return (
    <Layout role={layoutRole} onLogout={logout}>
      <PageHeader
        title="Dashboard IA"
        subtitle="Consumo, qualidade, custo estimado, handoff e gargalos por empresa."
        action={(
          <div className="flex flex-wrap items-end gap-2">
            {isAdmin && companies.length > 0 ? (
              <FilterSelect label="Empresa" value={selectedCompanyId || 'all'} onChange={setSelectedCompanyId}>
                <option value="all">Todas as empresas</option>
                {companies.map((company) => (
                  <option key={company.id} value={String(company.id)}>{company.name}</option>
                ))}
              </FilterSelect>
            ) : null}
            <a
              href={jsonUrl}
              className="rounded-lg border border-[var(--ui-border)] px-3 py-2 text-sm font-medium text-[var(--ui-text)] hover:bg-[var(--ui-surface-elevated)]"
            >
              Export JSON
            </a>
            <a
              href={csvUrl}
              className="rounded-lg bg-[var(--ui-accent)] px-3 py-2 text-sm font-medium text-white hover:opacity-90"
            >
              Export CSV
            </a>
          </div>
        )}
      />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3">
          <DateInput label="Data inicial" value={dateFrom} onChange={setDateFrom} />
          <DateInput label="Data final" value={dateTo} onChange={setDateTo} />

          <FilterSelect label="Canal" value={channel} onChange={setChannel}>
            <option value="all">Todos os canais</option>
            <option value="whatsapp">WhatsApp</option>
            <option value="internal_chat">Chat interno</option>
          </FilterSelect>

          <FilterSelect label="Area" value={areaId} onChange={setAreaId}>
            <option value="all">Todas as areas</option>
            {areas.map((area) => (
              <option key={area.id} value={String(area.id)}>{area.name}</option>
            ))}
          </FilterSelect>

          <FilterSelect label="Fluxo" value={flow} onChange={setFlow}>
            <option value="all">Todos os fluxos</option>
            {flows.map((item) => (
              <option key={item} value={item}>{item}</option>
            ))}
          </FilterSelect>

          <button
            type="button"
            onClick={() => {
              setDateFrom(daysAgoDateString(29));
              setDateTo(todayDateString());
              setChannel('all');
              setAreaId('all');
              setFlow('all');
            }}
            className="rounded-lg border border-[var(--ui-border)] px-3 py-2 text-sm text-[var(--ui-text-muted)] hover:bg-[var(--ui-surface-elevated)]"
          >
            Limpar filtros
          </button>
        </div>

        <p className="mt-3 text-xs text-[var(--ui-text-subtle)]">
          Periodo carregado: {data.range?.from} ate {data.range?.to}. Canal: {CHANNEL_LABELS[data.filters?.channel] ?? 'Todos'}.
        </p>
      </Card>

      <section className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        <SummaryCard
          title="Chamadas ao provider"
          value={formatNum(summary.provider_requests)}
          sub={`${formatNum(summary.ok_count)} ok, ${formatNum(summary.error_count)} erro`}
        />
        <SummaryCard
          title="Decisoes do bot IA"
          value={formatNum(summary.chatbot_decisions)}
          sub={`Confianca media: ${summary.avg_confidence == null ? '-' : Number(summary.avg_confidence).toFixed(2)}`}
        />
        <SummaryCard
          title="Resolucao estimada"
          value={formatPct(summary.resolution_rate_pct)}
          sub={`${formatNum(summary.resolved_count)} decisoes sem handoff`}
          tone={Number(summary.resolution_rate_pct ?? 0) >= 70 ? 'good' : 'warn'}
        />
        <SummaryCard
          title="Handoff"
          value={formatPct(summary.handoff_rate_pct)}
          sub={`${formatNum(summary.handoff_count)} total | ${formatNum(summary.handoff_incapacity_count)} por incapacidade`}
          tone={Number(summary.handoff_rate_pct ?? 0) > 30 ? 'danger' : 'default'}
        />
        <SummaryCard
          title="Tokens"
          value={formatNum(summary.total_tokens)}
          sub={`Chatbot logado: ${formatNum(summary.chatbot_decision_tokens)}`}
        />
        <SummaryCard
          title="Custo estimado"
          value={formatMoney(summary.estimated_cost, summary.estimated_cost_currency)}
          sub={`${formatMoney(summary.estimated_cost_per_1k_tokens, summary.estimated_cost_currency)} por 1k tokens`}
        />
        <SummaryCard
          title="Falhas"
          value={formatPct(summary.failure_rate_pct)}
          sub={`${formatNum(summary.failure_count)} falha(s) no periodo`}
          tone={Number(summary.failure_rate_pct ?? 0) > 5 ? 'danger' : 'good'}
        />
        <SummaryCard
          title="Latencia"
          value={formatMs(summary.avg_response_time_ms)}
          sub={`Decisao IA: ${formatMs(summary.avg_decision_latency_ms)}`}
        />
      </section>

      <section className="mt-4">
        <Section
          title="Uso diario"
          subtitle="Barras empilhadas com chamadas ao provider, decisoes IA, handoffs e falhas."
        >
          <div className="p-4">
            <BarChart
              data={daily}
              bars={[
                { key: 'provider_requests', label: 'Provider', className: 'bg-blue-500' },
                { key: 'chatbot_decisions', label: 'Decisoes', className: 'bg-emerald-500' },
                { key: 'handoffs', label: 'Handoffs', className: 'bg-amber-500' },
                { key: 'failures', label: 'Falhas', className: 'bg-red-500' },
              ]}
            />
            <div className="mt-3 flex flex-wrap gap-3 text-xs text-[var(--ui-text-muted)]">
              <span>Azul: provider</span>
              <span>Verde: decisoes</span>
              <span>Amarelo: handoff</span>
              <span>Vermelho: falhas</span>
            </div>
          </div>
        </Section>
      </section>

      <section className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
        <Section title="Principais intents" subtitle="Mostra onde o cliente mais pede ajuda e onde vira handoff.">
          <SimpleTable
            rows={topIntents}
            columns={[
              { key: 'intent', label: 'Intent' },
              { key: 'total', label: 'Total', render: (row) => formatNum(row.total) },
              { key: 'handoffs', label: 'Handoffs', render: (row) => formatNum(row.handoffs) },
              { key: 'avg_confidence', label: 'Confianca', render: (row) => row.avg_confidence == null ? '-' : Number(row.avg_confidence).toFixed(2) },
            ]}
          />
        </Section>

        <Section title="Handoff por tipo" subtitle="Separa transferencia de menu configurado e incapacidade da IA/fluxo.">
          <SimpleTable
            rows={handoffByType}
            columns={[
              { key: 'type', label: 'Tipo', render: (row) => HANDOFF_TYPE_LABELS[row.type] ?? row.type },
              { key: 'count', label: 'Total', render: (row) => formatNum(row.count) },
            ]}
          />
        </Section>

        <Section title="Motivos de transferencia" subtitle="Gargalos de atendimento que precisam de ajuste no menu ou base da empresa.">
          <SimpleTable
            rows={handoffReasons}
            columns={[
              { key: 'reason', label: 'Motivo' },
              { key: 'count', label: 'Total', render: (row) => formatNum(row.count) },
            ]}
          />
        </Section>

        <Section title="Gargalos por fluxo" subtitle="Ordenado por handoffs e falhas para priorizar melhoria operacional.">
          <SimpleTable
            rows={bottlenecks}
            columns={[
              { key: 'flow', label: 'Fluxo' },
              { key: 'total', label: 'Decisoes', render: (row) => formatNum(row.total) },
              { key: 'handoffs', label: 'Handoffs', render: (row) => formatNum(row.handoffs) },
              { key: 'failures', label: 'Falhas', render: (row) => formatNum(row.failures) },
              { key: 'avg_confidence', label: 'Confianca', render: (row) => row.avg_confidence == null ? '-' : Number(row.avg_confidence).toFixed(2) },
            ]}
          />
        </Section>
      </section>

      <section className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
        <Section title="Consumo por provider" subtitle="Base de custo e falhas tecnicas por fornecedor/modelo.">
          <SimpleTable
            rows={byProvider}
            columns={[
              { key: 'provider', label: 'Provider' },
              { key: 'total', label: 'Chamadas', render: (row) => formatNum(row.total) },
              { key: 'error', label: 'Erros', render: (row) => formatNum(row.error) },
              { key: 'avg_ms', label: 'Latencia', render: (row) => formatMs(row.avg_ms) },
              { key: 'tokens', label: 'Tokens', render: (row) => formatNum(row.tokens) },
            ]}
          />
        </Section>

        <Section title="Consumo por feature" subtitle="Ajuda a separar chat interno, sugestoes e chatbot automatico.">
          <SimpleTable
            rows={byFeature}
            columns={[
              { key: 'feature', label: 'Feature', render: (row) => FEATURE_LABELS[row.feature] ?? row.feature },
              { key: 'total', label: 'Chamadas', render: (row) => formatNum(row.total) },
              { key: 'error', label: 'Erros', render: (row) => formatNum(row.error) },
              { key: 'avg_ms', label: 'Latencia', render: (row) => formatMs(row.avg_ms) },
              { key: 'tokens', label: 'Tokens', render: (row) => formatNum(row.tokens) },
            ]}
          />
        </Section>
      </section>
    </Layout>
  );
}

export default CompanyAiAnalyticsPage;
