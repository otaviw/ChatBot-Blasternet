import './CompanyBotPage.css';
import { useCallback, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import BotConfigStep from '@/components/company/BotConfigStep/BotConfigStep.jsx';
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

  const [testState, setTestState] = useState('idle'); // idle | loading | ok | error
  const [testResult, setTestResult] = useState(null);

  const testConnection = useCallback(async () => {
    setTestState('loading');
    setTestResult(null);
    try {
      const res = await api.post('/minha-conta/bot/validar-whatsapp');
      setTestState('ok');
      setTestResult(res.data);
    } catch (err) {
      setTestState('error');
      setTestResult({ error: err.response?.data?.error || 'Erro ao testar conexão.' });
    }
  }, []);

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <PageLoading rows={2} cards={2} />
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
          Siga os passos na ordem: primeiro contexto e áreas, depois expediente e mensagens, em seguida o menu e, por
          último, atalhos por palavra-chave. Salve ao final.
        </p>
      </div>

      <nav className="bot-config-toc" aria-label="Ordem da configuração">
        <ol className="bot-config-toc-list">
          <li>Estado e fuso</li>
          <li>Áreas de atendimento</li>
          <li>Horário comercial</li>
          <li>Mensagens automáticas</li>
          <li>Menu numerado</li>
          <li>Palavras-chave</li>
        </ol>
      </nav>

      <section className="app-panel mb-6">
        <h2 className="text-sm font-semibold text-[#171717] mb-3">Conexão WhatsApp</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
          <div>
            <p className="text-xs text-[#737373] mb-0.5">ID do número (phone_number_id)</p>
            <p className="text-sm font-mono text-[#171717]">{company.meta_phone_number_id || '-'}</p>
          </div>
          <div>
            <p className="text-xs text-[#737373] mb-0.5">Token configurado</p>
            <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${company.has_meta_credentials ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
              {company.has_meta_credentials ? 'Sim' : 'Não'}
            </span>
          </div>
        </div>

        <div className="flex items-center gap-3 flex-wrap">
          <button
            type="button"
            onClick={testConnection}
            disabled={testState === 'loading' || !company.has_meta_credentials}
            className="app-btn-secondary"
          >
            {testState === 'loading' ? 'Testando...' : 'Testar conexão'}
          </button>

          {testState === 'ok' && testResult?.display_phone_number && (
            <span className="text-sm text-emerald-700 flex items-center gap-1">
              Conexão OK - Número: {testResult.display_phone_number}
              {testResult.verified_name ? ` (${testResult.verified_name})` : ''}
            </span>
          )}
          {testState === 'error' && (
            <span className="text-sm text-red-600 flex items-center gap-1">
              Erro - {testResult?.error || 'Credenciais inválidas.'}
            </span>
          )}
          {!company.has_meta_credentials && (
            <span className="text-xs text-[#a3a3a3]">Configure as credenciais com o administrador para habilitar o teste.</span>
          )}
        </div>
      </section>

      <form onSubmit={saveSettings} className="bot-config-form">
        <BotConfigStep
          step={1}
          title="Estado e contexto"
          intro="Defina se o bot responde sozinho e em qual fuso o expediente será calculado."
          rules={[
            'Ative o bot apenas quando mensagens e fluxo estiverem prontos para teste ou produção.',
            'Use um fuso válido (ex.: America/Sao_Paulo); ele afeta a mensagem de fora do expediente.',
          ]}
        >
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
              <span className="bot-config-label">IA assistiva no bot</span>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={Boolean(settings.ai_chatbot_enabled)}
                  onChange={(e) => updateMessageField('ai_chatbot_enabled', e.target.checked)}
                />
                <span className="text-sm">Permitir IA sugerir e melhorar respostas do bot</span>
              </label>
              <p className="text-xs text-[#706f6c] mt-1">
                Essa opção só funciona quando a empresa estiver autorizada pela revenda e com feature global liberada.
              </p>
            </label>
            <label className="bot-config-field">
              <span className="bot-config-label">Fuso horário</span>
              <input
                type="text"
                value={settings.timezone}
                onChange={(e) => updateMessageField('timezone', e.target.value)}
                placeholder="Ex: America/Sao_Paulo"
                className="app-input"
              />
            </label>
          </div>

          <label className="bot-config-field bot-config-field--inline mt-4">
            <span className="bot-config-label">Apagar mensagens após (dias)</span>
            <input
              type="number"
              min="1"
              max="180"
              value={settings.message_retention_days}
              onChange={(e) => {
                const value = Math.min(180, Math.max(1, Number(e.target.value) || 180));
                updateMessageField('message_retention_days', value);
              }}
              className="app-input w-24"
            />
          </label>
          <p className="text-xs text-[#706f6c] mt-1">
            Mensagens com mais de X dias serão removidas automaticamente toda madrugada. O máximo permitido é 180 dias.
          </p>
        </BotConfigStep>

        <BotConfigStep
          step={2}
          title="Áreas de atendimento"
          intro="Nomes das equipes ou setores usados em transferências e no menu (quando aplicável)."
          rules={[
            'Cadastre as áreas antes de montar o menu personalizado que transfere para um setor.',
            'Prefira nomes curtos e claros para o cliente entender na primeira leitura.',
          ]}
        >
          <div className="bot-config-section-header">
            <p className="bot-config-hint bot-config-hint--flush">Ex.: Suporte, Vendas, Financeiro</p>
            <button
              type="button"
              onClick={addServiceArea}
              className="app-btn-secondary"
            >
              Adicionar área
            </button>
          </div>
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
        </BotConfigStep>

        <BotConfigStep
          step={3}
          title="Horário comercial"
          intro="Marque os dias e intervalos em que o atendimento humano ou o bot consideram expediente."
          rules={[
            'Em dias desmarcados ou fora do intervalo, vale a mensagem de fora do expediente (passo 4).',
            'Garanta que o horário de início seja anterior ao de fim no mesmo dia.',
          ]}
        >
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
        </BotConfigStep>

        <BotConfigStep
          step={4}
          title="Mensagens automáticas"
          intro="Textos que o bot envia na entrada, quando não entende o pedido, fora do expediente e política de inatividade."
          rules={[
            'Boas-vindas: primeira impressão; fallback: quando nenhuma regra se aplica; fora de horário: depende do passo 3.',
            'Ajuste o encerramento por inatividade conforme o tempo médio do seu atendimento.',
          ]}
        >
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
        </BotConfigStep>

        <BotConfigStep
          step={5}
          title="Menu numerado do bot"
          intro="Fluxo principal: opções numeradas, blocos e transferências para as áreas do passo 2."
          rules={[
            'Com modelo automático, revise textos antes de publicar; com fluxo personalizado, mantenha opções objetivas.',
            'O editor em blocos permite navegação lateral sem empilhar tudo na mesma tela.',
          ]}
        >
          <div className="bot-config-section-header">
            <p className="bot-config-hint bot-config-hint--flush">
              Monte o fluxo em blocos, com uma navegação lateral para editar sem ficar abrindo várias áreas para baixo.
            </p>

            <div className="flex gap-2 flex-wrap">
              <button
                type="button"
                onClick={loadSuggestedMenuTemplate}
                className="app-btn-secondary"
              >
                Restaurar modelo de exemplo
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
        </BotConfigStep>

        <BotConfigStep
          step={6}
          title="Respostas por palavra-chave"
          intro="Atalhos opcionais: quando o cliente digita um termo, o bot responde com o texto associado."
          rules={[
            'Cadastre depois do menu para não duplicar o que já está no fluxo numerado.',
            'Evite a mesma palavra em duas regras; normalize termos (ex.: minúsculas) para bater com o que o cliente digita.',
          ]}
        >
          <div className="flex flex-wrap justify-end gap-2 mb-2">
            <button
              type="button"
              onClick={addKeywordReply}
              className="app-btn-secondary"
            >
              Adicionar regra
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
        </BotConfigStep>

        <div className="bot-config-actions">
          <button
            type="submit"
            disabled={saveState === 'saving'}
            className="app-btn-primary"
          >
            {saveState === 'saving' ? 'Salvando...' : 'Salvar configurações'}
          </button>

          {saveState === 'saved' && <p className="text-sm text-green-700">Configurações guardadas com sucesso.</p>}
          {saveState === 'error' && <p className="text-sm text-red-600">{saveError}</p>}
        </div>
      </form>
    </Layout>
  );
}

export default CompanyBotPage;





