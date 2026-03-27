import './AdminCompanyShowPage.css';
import { useState, useEffect, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import Layout from '@/components/layout/Layout/Layout.jsx';
import StatefulMenuFlowEditor from '@/components/sections/StatefulMenuFlowEditor/StatefulMenuFlowEditor.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import useBotSettingsEditor from '@/hooks/useBotSettingsEditor';
import api from '@/services/api';
import {
  DAY_KEYS,
  DAY_LABELS,
} from '@/constants/botSettings';

function AdminCompanyShowPage({ companyId: companyIdProp }) {
  const { companyId: companyIdParam = '' } = useParams();
  const companyId = companyIdProp || companyIdParam;
  const { data, loading, error } = usePageData(`/admin/empresas/${companyId}`);
  const { logout } = useLogout();
  const [companyForm, setCompanyForm] = useState({
    name: '',
    meta_phone_number_id: '',
    ai_enabled: false,
    ai_internal_chat_enabled: false,
  });
  const [companyData, setCompanyData] = useState(null);
  const [companySaveState, setCompanySaveState] = useState('idle');
  const [companySaveError, setCompanySaveError] = useState('');
  const { data: metricsData, loading: metricsLoading } = usePageData(
    `/admin/empresas/${companyId}/metricas`
  );

  const reloadSettings = useCallback(async () => {
    const response = await api.get(`/admin/empresas/${companyId}`);
    const company = response.data?.company ?? null;
    if (company) {
      setCompanyData(company);
    }
    return company?.bot_setting ?? null;
  }, [companyId]);

  const persistSettings = useCallback(async (payload) => {
    const response = await api.put(`/admin/empresas/${companyId}/bot`, payload);
    return response.data?.settings ?? null;
  }, [companyId]);

  const {
    settings,
    saveState,
    saveError,
    useDefaultStatefulMenu,
    statefulMenuEditor,
    menuFlowError,
    setUseDefaultStatefulMenu,
    setStatefulMenuEditor,
    setMenuFlowError,
    updateMessageField,
    updateDay,
    updateKeyword,
    addKeywordReply,
    removeKeywordReply,
    updateServiceArea,
    addServiceArea,
    removeServiceArea,
    loadSuggestedMenuTemplate,
    enableCustomMenuBuilder,
    saveSettings,
  } = useBotSettingsEditor({
    initialSettings: data?.company?.bot_setting ?? null,
    realtimeCompanyId: companyId,
    reloadSettings,
    persistSettings,
  });

  useEffect(() => {
    if (!data?.company) {
      return;
    }

    setCompanyData(data.company);
    setCompanyForm({
      name: data.company.name ?? '',
      meta_phone_number_id: data.company.meta_phone_number_id ?? '',
      ai_enabled: Boolean(data.company.bot_setting?.ai_enabled),
      ai_internal_chat_enabled: Boolean(data.company.bot_setting?.ai_internal_chat_enabled),
    });
  }, [data]);

  const saveCompanyData = async (event) => {
    event.preventDefault();
    setCompanySaveState('saving');
    setCompanySaveError('');

    try {
      const payload = {
        name: companyForm.name,
        meta_phone_number_id: companyForm.meta_phone_number_id || null,
        ai_enabled: Boolean(companyForm.ai_enabled),
        ai_internal_chat_enabled: Boolean(companyForm.ai_internal_chat_enabled),
      };
      const response = await api.put(`/admin/empresas/${companyId}`, payload);
      const updatedCompany = response.data?.company;
      if (updatedCompany) {
        setCompanyData((previous) => ({
          ...(previous ?? {}),
          ...updatedCompany,
        }));
        setCompanyForm({
          name: updatedCompany.name ?? '',
          meta_phone_number_id: updatedCompany.meta_phone_number_id ?? '',
          ai_enabled: Boolean(updatedCompany.bot_setting?.ai_enabled ?? companyForm.ai_enabled),
          ai_internal_chat_enabled: Boolean(
            updatedCompany.bot_setting?.ai_internal_chat_enabled ?? companyForm.ai_internal_chat_enabled
          ),
        });
      }
      setCompanySaveState('saved');
      setTimeout(() => setCompanySaveState('idle'), 600);
    } catch (err) {
      setCompanySaveState('error');
      setCompanySaveError(err.response?.data?.message || 'Falha ao salvar dados da empresa.');
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#737373]">Carregando empresa...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !data.company) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Não foi possível carregar a empresa.</p>
      </Layout>
    );
  }

  const company = companyData ?? data.company;
  const setting = company.bot_setting;

  return (
    <Layout role="admin" onLogout={logout}>
      <a
        href="/admin/empresas"
        className="inline-flex items-center gap-2 text-sm text-[#525252] hover:text-[#171717] mb-6 transition-colors"
      >
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M19 12H5M12 19l-7-7 7-7" />
        </svg>
        Voltar para Empresas
      </a>

      <div className="mb-8">
        <h1 className="app-page-title">{company.name}</h1>
        <p className="app-page-subtitle">Informações e uso da empresa</p>
      </div>

      <section className="app-panel mb-8">
        <h2 className="text-sm font-semibold text-[#171717] mb-4">Informações</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div className="rounded-lg bg-[#fafafa] px-4 py-3">
            <p className="text-xs font-medium text-[#737373] uppercase tracking-wider">ID</p>
            <p className="mt-0.5 text-sm font-medium text-[#171717]">{company.id}</p>
          </div>
          <div className="rounded-lg bg-[#fafafa] px-4 py-3">
            <p className="text-xs font-medium text-[#737373] uppercase tracking-wider">Nome</p>
            <p className="mt-0.5 text-sm font-medium text-[#171717]">{company.name}</p>
          </div>
          <div className="rounded-lg bg-[#fafafa] px-4 py-3">
            <p className="text-xs font-medium text-[#737373] uppercase tracking-wider">Meta Phone Number ID</p>
            <p className="mt-0.5 text-sm font-medium text-[#171717] font-mono">
              {company.meta_phone_number_id || '—'}
            </p>
          </div>
          <div className="rounded-lg bg-[#fafafa] px-4 py-3">
            <p className="text-xs font-medium text-[#737373] uppercase tracking-wider">Token configurado</p>
            <span
              className={`inline-flex mt-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium ${company.has_meta_credentials ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'
                }`}
            >
              {company.has_meta_credentials ? 'Sim' : 'Não'}
            </span>
          </div>
          <div className="rounded-lg bg-[#fafafa] px-4 py-3">
            <p className="text-xs font-medium text-[#737373] uppercase tracking-wider">Bot ativo</p>
            <span
              className={`inline-flex mt-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium ${setting?.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-600'
                }`}
            >
              {setting?.is_active ? 'Sim' : 'Não'}
            </span>
          </div>
          <div className="rounded-lg bg-[#fafafa] px-4 py-3">
            <p className="text-xs font-medium text-[#737373] uppercase tracking-wider">Timezone</p>
            <p className="mt-0.5 text-sm font-medium text-[#171717]">{setting?.timezone ?? 'America/Sao_Paulo'}</p>
          </div>
        </div>
      </section>

      {metricsLoading && <p className="text-sm text-[#737373]">Carregando métricas...</p>}
      {metricsData?.metrics && (
        <section className="app-panel mb-6">
          <h2 className="font-medium mb-4">Métricas</h2>

          <div className="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
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
              <h3 className="text-sm font-medium mb-2">Últimos 30 dias</h3>
              <ul className="text-xs text-[#737373] space-y-1 max-h-32 overflow-y-auto">
                {metricsData.metrics.by_day.map((item) => (
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

      <section className="app-panel mb-8">
        <h2 className="font-medium mb-3">Dados da empresa (admin)</h2>
        <form onSubmit={saveCompanyData} className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="block text-sm md:col-span-2">
            Nome da empresa
            <input
              type="text"
              value={companyForm.name}
              onChange={(e) => setCompanyForm((p) => ({ ...p, name: e.target.value }))}
              required
              className="app-input"
            />
          </label>

          <label className="block text-sm">
            Meta Phone Number ID
            <input
              type="text"
              value={companyForm.meta_phone_number_id}
              onChange={(e) => setCompanyForm((p) => ({ ...p, meta_phone_number_id: e.target.value }))}
              className="app-input"
            />
          </label>

          <label className="flex items-center gap-2 text-sm md:col-span-2">
            <input
              type="checkbox"
              checked={Boolean(companyForm.ai_enabled)}
              onChange={(e) => setCompanyForm((p) => ({ ...p, ai_enabled: e.target.checked }))}
            />
            Habilitar IA para esta empresa
          </label>

          <label className="flex items-center gap-2 text-sm md:col-span-2">
            <input
              type="checkbox"
              checked={Boolean(companyForm.ai_internal_chat_enabled)}
              onChange={(e) =>
                setCompanyForm((p) => ({ ...p, ai_internal_chat_enabled: e.target.checked }))
              }
            />
            Habilitar chat interno com IA
          </label>

          <div className="md:col-span-2">
            <button
              type="submit"
              disabled={companySaveState === 'saving'}
              className="app-btn-primary"
            >
              {companySaveState === 'saving' ? 'Salvando dados...' : 'Salvar dados da empresa'}
            </button>
          </div>
        </form>
        {companySaveState === 'saved' && <p className="text-sm text-green-700 mt-2">Dados salvos.</p>}
        {companySaveState === 'error' && <p className="text-sm text-red-600 mt-2">{companySaveError}</p>}
      </section>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#737373] mb-2">Regras do bot</h2>
        {!setting ? (
          <p className="text-sm text-[#737373]">Empresa ainda usando configuração padrão.</p>
        ) : (
          <ul className="text-sm space-y-1">
            <li>Mensagem de boas-vindas: {setting.welcome_message || '-'}</li>
            <li>Mensagem fallback: {setting.fallback_message || '-'}</li>
            <li>Mensagem fora de horário: {setting.out_of_hours_message || '-'}</li>
            <li>Respostas por palavra-chave: {Array.isArray(setting.keyword_replies) ? setting.keyword_replies.length : 0}</li>
            <li>Áreas de atendimento: {Array.isArray(setting.service_areas) ? setting.service_areas.join(', ') || '-' : '-'}</li>
            <li>Menu stateful customizado: {setting.stateful_menu_flow ? 'Sim' : 'Não (usa padrão automático)'}</li>
          </ul>
        )}
      </section>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#737373] mb-2">Editar configurações (admin)</h2>
        <form onSubmit={saveSettings} className="space-y-8 max-w-4xl">
          <section className="app-panel space-y-4">
            <h3 className="font-medium">Estado e contexto</h3>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={settings.is_active}
                onChange={(e) => updateMessageField('is_active', e.target.checked)}
              />
              Bot ativo
            </label>

            <label className="block text-sm">
              Timezone
              <input
                type="text"
                value={settings.timezone}
                onChange={(e) => updateMessageField('timezone', e.target.value)}
                className="app-input"
              />
            </label>
          </section>

          <section className="app-panel space-y-4">
            <h3 className="font-medium">Mensagens</h3>
            <label className="block text-sm">
              Boas-vindas
              <textarea
                value={settings.welcome_message || ''}
                onChange={(e) => updateMessageField('welcome_message', e.target.value)}
                rows={3}
                className="app-input"
              />
            </label>

            <label className="block text-sm">
              Fallback (quando não entende)
              <textarea
                value={settings.fallback_message || ''}
                onChange={(e) => updateMessageField('fallback_message', e.target.value)}
                rows={3}
                className="app-input"
              />
            </label>

            <label className="block text-sm">
              Fora de horário
              <textarea
                value={settings.out_of_hours_message || ''}
                onChange={(e) => updateMessageField('out_of_hours_message', e.target.value)}
                rows={3}
                className="app-input"
              />
            </label>
          </section>

          <section className="app-panel space-y-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="font-medium">Menu numerado do bot</h3>
                <p className="mt-1 text-sm text-[#737373]">
                  Monte o fluxo em blocos, com uma navegação lateral para editar menus, perguntas abertas e transferências sem ficar abrindo várias áreas para baixo.
                </p>
              </div>

              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={loadSuggestedMenuTemplate}
                  className="app-btn-secondary"
                >
                  Restaurar modelo sugerido
                </button>

                <button
                  type="button"
                  onClick={() => {
                    setUseDefaultStatefulMenu(true);
                    setMenuFlowError('');
                  }}
                  className="app-btn-secondary"
                >
                  Usar modelo automático
                </button>
              </div>
            </div>

            <div className="rounded-lg border border-[#e3e3e0] bg-[#fafafa] p-4">
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={useDefaultStatefulMenu}
                  onChange={(e) => {
                    if (e.target.checked) {
                      setUseDefaultStatefulMenu(true);
                      setMenuFlowError('');
                      return;
                    }

                    enableCustomMenuBuilder();
                  }}
                />
                Usar menu padrão automático (sem edição manual)
              </label>

              <p className="mt-2 text-xs text-[#706f6c]">
                O menu pode iniciar automaticamente na primeira mensagem. O comando <strong>#</strong> faz o cliente voltar para o início.
              </p>

              <p className="mt-1 text-xs text-[#706f6c]">
                Desmarque esta opção para montar um fluxo personalizado com blocos, opções, perguntas abertas e transferências.
              </p>
            </div>

            {!useDefaultStatefulMenu && (
              <div className="pt-1">
                <StatefulMenuFlowEditor
                  value={statefulMenuEditor}
                  onChange={setStatefulMenuEditor}
                  serviceAreas={settings.service_areas ?? []}
                />
              </div>
            )}

            {menuFlowError && <p className="text-sm text-red-600">{menuFlowError}</p>}
          </section>

          <section className="app-panel space-y-4">
            <h3 className="font-medium">Horario por dia</h3>
            <div className="space-y-3">
              {DAY_KEYS.map((day) => {
                const cfg = settings.business_hours[day] || { enabled: false, start: '', end: '' };
                return (
                  <div key={day} className="grid grid-cols-1 md:grid-cols-4 gap-3 items-center border border-[#efefec] rounded p-3">
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={Boolean(cfg.enabled)}
                        onChange={(e) => updateDay(day, { enabled: e.target.checked })}
                      />
                      {DAY_LABELS[day]}
                    </label>

                    <label className="text-sm">
                      Inicio
                      <input
                        type="time"
                        value={cfg.start || ''}
                        onChange={(e) => updateDay(day, { start: e.target.value })}
                        disabled={!cfg.enabled}
                        className="app-input disabled:opacity-50"
                      />
                    </label>

                    <label className="text-sm">
                      Fim
                      <input
                        type="time"
                        value={cfg.end || ''}
                        onChange={(e) => updateDay(day, { end: e.target.value })}
                        disabled={!cfg.enabled}
                        className="app-input disabled:opacity-50"
                      />
                    </label>
                  </div>
                );
              })}
            </div>
          </section>

          <section className="app-panel space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="font-medium">Respostas por palavra-chave</h3>
              <button
                type="button"
                onClick={addKeywordReply}
                className="app-btn-secondary"
              >
                Adicionar
              </button>
            </div>

            {!settings.keyword_replies.length && (
              <p className="text-sm text-[#737373]">Nenhuma regra cadastrada.</p>
            )}

            <div className="space-y-3">
              {settings.keyword_replies.map((item, index) => (
                <div key={index} className="grid grid-cols-1 md:grid-cols-5 gap-3 border border-[#efefec] rounded p-3">
                  <label className="text-sm md:col-span-1">
                    Palavra-chave
                    <input
                      type="text"
                      value={item.keyword || ''}
                      onChange={(e) => updateKeyword(index, 'keyword', e.target.value)}
                      className="app-input"
                    />
                  </label>

                  <label className="text-sm md:col-span-3">
                    Resposta
                    <input
                      type="text"
                      value={item.reply || ''}
                      onChange={(e) => updateKeyword(index, 'reply', e.target.value)}
                      className="app-input"
                    />
                  </label>

                  <div className="md:col-span-1 flex items-end">
                    <button
                      type="button"
                      onClick={() => removeKeywordReply(index)}
                      className="app-btn-danger w-full"
                    >
                      Remover
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </section>

          <section className="app-panel space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="font-medium">Áreas de atendimento</h3>
              <button
                type="button"
                onClick={addServiceArea}
                className="app-btn-secondary"
              >
                Adicionar área
              </button>
            </div>
            {!settings.service_areas?.length && (
              <p className="text-sm text-[#737373]">Nenhuma área cadastrada.</p>
            )}
            <div className="space-y-2">
              {(settings.service_areas ?? []).map((area, index) => (
                <div key={index} className="flex gap-2">
                  <input
                    type="text"
                    value={area}
                    onChange={(e) => updateServiceArea(index, e.target.value)}
                    placeholder="Ex.: Suporte"
                    className="app-input"
                  />
                  <button
                    type="button"
                    onClick={() => removeServiceArea(index)}
                    className="app-btn-danger"
                  >
                    Remover
                  </button>
                </div>
              ))}
            </div>
          </section>

          <div className="flex items-center gap-3">
            <button
              type="submit"
              disabled={saveState === 'saving'}
              className="app-btn-primary"
            >
              {saveState === 'saving' ? 'Salvando...' : 'Salvar configurações (admin)'}
            </button>

            {saveState === 'saved' && <p className="text-sm text-green-700">Configurações salvas com sucesso.</p>}
            {saveState === 'error' && <p className="text-sm text-red-600">{saveError}</p>}
          </div>
        </form>
      </section>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#737373] mb-2">Uso</h2>
        <p className="text-sm">Total de conversas: <strong>{company.conversations_count ?? 0}</strong></p>
        <p className="text-sm text-[#737373] mt-2">
          O modo privacidade oculta detalhes de conversas do painel de superadmin.
        </p>
      </section>
    </Layout>
  );
}

export default AdminCompanyShowPage;





