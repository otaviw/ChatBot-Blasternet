import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';

function GeneralTab({ company, setting, metricsLoading, metricsData }) {
  return (
    <>
      <section className="app-panel mb-8">
        <h2 className="text-sm font-semibold text-[var(--ui-text)] mb-4">Informacoes</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div className="rounded-lg bg-[var(--ui-surface-elevated)] px-4 py-3">
            <p className="text-xs font-medium text-[var(--ui-text-muted)] uppercase tracking-wider">ID</p>
            <p className="mt-0.5 text-sm font-medium text-[var(--ui-text)]">{company.id}</p>
          </div>
          <div className="rounded-lg bg-[var(--ui-surface-elevated)] px-4 py-3">
            <p className="text-xs font-medium text-[var(--ui-text-muted)] uppercase tracking-wider">Nome</p>
            <p className="mt-0.5 text-sm font-medium text-[var(--ui-text)]">{company.name}</p>
          </div>
          <div className="rounded-lg bg-[var(--ui-surface-elevated)] px-4 py-3">
            <p className="text-xs font-medium text-[var(--ui-text-muted)] uppercase tracking-wider break-words leading-snug">
              ID do numero (Meta / WhatsApp)
            </p>
            <p className="mt-0.5 text-sm font-medium text-[var(--ui-text)] font-mono break-all">
              {company.meta_phone_number_id || '-'}
            </p>
          </div>
          <div className="rounded-lg bg-[var(--ui-surface-elevated)] px-4 py-3">
            <p className="text-xs font-medium text-[var(--ui-text-muted)] uppercase tracking-wider break-words leading-snug">
              WABA ID (WhatsApp Business Account)
            </p>
            <p className="mt-0.5 text-sm font-medium text-[var(--ui-text)] font-mono break-all">
              {company.meta_waba_id || '-'}
            </p>
          </div>
          <div className="rounded-lg bg-[var(--ui-surface-elevated)] px-4 py-3">
            <p className="text-xs font-medium text-[var(--ui-text-muted)] uppercase tracking-wider">Token configurado</p>
            <span
              className={`inline-flex mt-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium ${
                company.has_meta_credentials ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'
              }`}
            >
              {company.has_meta_credentials ? 'Sim' : 'Nao'}
            </span>
          </div>
          <div className="rounded-lg bg-[var(--ui-surface-elevated)] px-4 py-3">
            <p className="text-xs font-medium text-[var(--ui-text-muted)] uppercase tracking-wider">Bot ativo</p>
            <span
              className={`inline-flex mt-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium ${
                setting?.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-600'
              }`}
            >
              {setting?.is_active ? 'Sim' : 'Nao'}
            </span>
          </div>
          <div className="rounded-lg bg-[var(--ui-surface-elevated)] px-4 py-3">
            <p className="text-xs font-medium text-[var(--ui-text-muted)] uppercase tracking-wider">Fuso horario</p>
            <p className="mt-0.5 text-sm font-medium text-[var(--ui-text)] break-all">{setting?.timezone ?? 'America/Sao_Paulo'}</p>
          </div>
        </div>
      </section>

      {metricsLoading && (
        <section className="app-panel mb-6">
          <LoadingSkeleton className="h-5 w-32" />
          <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4">
            {Array.from({ length: 5 }).map((_, index) => (
              <LoadingSkeleton key={`metrics-skeleton-${index}`} className="h-20 w-full" />
            ))}
          </div>
        </section>
      )}
      {metricsData?.metrics && (
        <section className="app-panel mb-6">
          <h2 className="font-medium mb-4">Metricas</h2>

          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div className="app-metric-card">
              <p className="app-metric-value">{metricsData.metrics.total}</p>
              <p className="app-metric-label">Total de conversas</p>
            </div>
            <div className="app-metric-card">
              <p className="app-metric-value">{metricsData.metrics.total_messages ?? 0}</p>
              <p className="app-metric-label">Total de mensagens</p>
            </div>
            <div className="app-metric-card">
              <p className="app-metric-value">{metricsData.metrics.total_users ?? 0}</p>
              <p className="app-metric-label">Total de usuários</p>
            </div>
            <div className="app-metric-card">
              <p className="app-metric-value">{metricsData.metrics.by_status?.open ?? 0}</p>
              <p className="app-metric-label">Abertas</p>
            </div>
            <div className="app-metric-card">
              <p className="app-metric-value">{metricsData.metrics.by_status?.closed ?? 0}</p>
              <p className="app-metric-label">Encerradas</p>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <h3 className="text-sm font-medium mb-2">Bot vs Humano (encerradas)</h3>
              <div className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span>Bot</span>
                  <span>{metricsData.metrics.by_mode?.bot ?? 0}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span>Humano</span>
                  <span>{metricsData.metrics.by_mode?.human ?? metricsData.metrics.by_mode?.manual ?? 0}</span>
                </div>
              </div>
            </div>

            <div>
              <h3 className="text-sm font-medium mb-2">Ultimos 30 dias</h3>
              <ul className="text-xs text-[var(--ui-text-muted)] space-y-1 max-h-32 overflow-y-auto">
                {(metricsData.metrics.by_day ?? []).map((item) => (
                  <li key={item.day} className="flex justify-between">
                    <span>{item.day}</span>
                    <span>{item.total} conversa(s)</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </section>
      )}
      {!metricsLoading && !metricsData?.metrics && (
        <section className="app-panel mb-6">
          <EmptyState
            title="Métricas indisponíveis"
            subtitle="Ainda não há dados suficientes para exibir os indicadores desta empresa."
          />
        </section>
      )}

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[var(--ui-text-muted)] mb-2">Uso</h2>
        <p className="text-sm">
          Total de conversas: <strong>{company.conversations_count ?? 0}</strong>
        </p>
        <p className="text-sm text-[var(--ui-text-muted)] mt-2">
          O modo privacidade oculta detalhes de conversas do painel de superadmin.
        </p>
      </section>
    </>
  );
}

export default GeneralTab;
