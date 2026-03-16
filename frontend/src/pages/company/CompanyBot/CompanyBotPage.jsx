import './CompanyBotPage.css';
import { useCallback } from 'react';
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

function CompanyBotPage() {
  const { data, loading, error } = usePageData('/minha-conta/bot');
  const { logout } = useLogout();

  const reloadSettings = useCallback(async () => {
    const response = await api.get('/minha-conta/bot');
    return response.data?.settings ?? null;
  }, []);

  const persistSettings = useCallback(async (payload) => {
    const response = await api.put('/minha-conta/bot', payload);
    return response.data?.settings ?? null;
  }, []);

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
    initialSettings: data?.settings ?? null,
    realtimeCompanyId: data?.company?.id ?? null,
    reloadSettings,
    persistSettings,
  });

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando configurações do bot...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !data.company) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">
          Não foi possível carregar as configurações do bot.
        </p>
      </Layout>
    );
  }

  const company = data.company;

  return (
    <Layout role="company" companyName={company.name} onLogout={logout}>
      <div className="bot-config-header">
        <h1 className="app-page-title">Configurações do bot</h1>
        <p className="app-page-subtitle">
          Defina mensagens, horários e respostas por palavra-chave.
        </p>
      </div>

      <form onSubmit={saveSettings} className="bot-config-form">
        <section className="bot-config-section">
          <h2 className="bot-config-section-title">Estado e contexto</h2>
          <div className="bot-config-grid-2">
            <label className="bot-config-field">
              <span className="bot-config-label">Bot ativo</span>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={settings.is_active}
                  onChange={(e) => updateMessageField('is_active', e.target.checked)}
                />
                <span className="text-sm">Ativar respostas automáticas</span>
              </label>
            </label>
            <label className="bot-config-field">
              <span className="bot-config-label">Timezone</span>
              <input
                type="text"
                value={settings.timezone}
                onChange={(e) => updateMessageField('timezone', e.target.value)}
                placeholder="Ex: America/Sao_Paulo"
                className="app-input"
              />
            </label>
          </div>
        </section>

        <section className="bot-config-section">
          <div className="bot-config-section-header">
            <h2 className="bot-config-section-title">Áreas de atendimento</h2>
            <button
              type="button"
              onClick={addServiceArea}
              className="app-btn-secondary"
            >
              Adicionar área
            </button>
          </div>
          <p className="bot-config-hint">Ex.: Suporte, Vendas, Financeiro</p>
          {!settings.service_areas?.length && (
            <p className="text-sm text-[#706f6c]">Nenhuma área cadastrada.</p>
          )}
          <div className="bot-config-list">
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

        <section className="bot-config-section">
          <h2 className="bot-config-section-title">Mensagens</h2>
          <div className="bot-config-messages-grid">
            <label className="bot-config-field">
              <span className="bot-config-label">Boas-vindas</span>
              <textarea
                value={settings.welcome_message || ''}
                onChange={(e) => updateMessageField('welcome_message', e.target.value)}
                rows={3}
                placeholder="Primeira mensagem enviada ao cliente"
                className="app-input"
              />
            </label>
            <label className="bot-config-field">
              <span className="bot-config-label">Fallback (quando não entende)</span>
              <textarea
                value={settings.fallback_message || ''}
                onChange={(e) => updateMessageField('fallback_message', e.target.value)}
                rows={3}
                placeholder="Mensagem quando o bot não reconhece"
                className="app-input"
              />
            </label>
            <label className="bot-config-field">
              <span className="bot-config-label">Fora de horário</span>
              <textarea
                value={settings.out_of_hours_message || ''}
                onChange={(e) => updateMessageField('out_of_hours_message', e.target.value)}
                rows={3}
                placeholder="Mensagem fora do expediente"
                className="app-input"
              />
            </label>
          </div>
          <label className="bot-config-field bot-config-field--inline">
            <span className="bot-config-label">Fechar conversa inativa após (horas)</span>
            <input
              type="number"
              min="1"
              max="720"
              value={settings.inactivity_close_hours ?? 24}
              onChange={(e) => updateMessageField('inactivity_close_hours', Number(e.target.value))}
              className="app-input w-24"
            />
          </label>
        </section>

        <section className="bot-config-section">
          <div className="bot-config-section-header">
            <div>
              <h2 className="bot-config-section-title">Menu numerado do bot</h2>
              <p className="bot-config-hint">
                Monte o fluxo em blocos, com uma navegação lateral para editar sem ficar abrindo várias áreas para baixo.
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

          <div className="mt-4 rounded-lg border border-[#e3e3e0] bg-[#fafafa] p-4">
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
              Desmarque para montar um fluxo personalizado com blocos, opções, perguntas abertas e transferências.
            </p>
          </div>

          {!useDefaultStatefulMenu && (
            <div className="mt-4">
              <StatefulMenuFlowEditor
                value={statefulMenuEditor}
                onChange={setStatefulMenuEditor}
                serviceAreas={settings.service_areas ?? []}
              />
            </div>
          )}

          {menuFlowError && <p className="mt-3 text-sm text-red-600">{menuFlowError}</p>}
        </section>

        <section className="bot-config-section">
          <h2 className="bot-config-section-title">Horário por dia</h2>
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
                    Início
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

        <section className="bot-config-section">
          <div className="bot-config-section-header">
            <h2 className="bot-config-section-title">Respostas por palavra-chave</h2>
            <button
              type="button"
              onClick={addKeywordReply}
              className="app-btn-secondary"
            >
              Adicionar
            </button>
          </div>

          {!settings.keyword_replies.length && (
            <p className="text-sm text-[#706f6c]">Nenhuma regra cadastrada.</p>
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

        <div className="bot-config-actions">
          <button
            type="submit"
            disabled={saveState === 'saving'}
            className="app-btn-primary"
          >
            {saveState === 'saving' ? 'Salvando...' : 'Salvar configurações'}
          </button>

          {saveState === 'saved' && <p className="text-sm text-green-700">Configuracoes salvas com sucesso.</p>}
          {saveState === 'error' && <p className="text-sm text-red-600">{saveError}</p>}
        </div>
      </form>
    </Layout>
  );
}

export default CompanyBotPage;





