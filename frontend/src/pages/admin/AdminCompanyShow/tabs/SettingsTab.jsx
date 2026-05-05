import BotConfigStep from '@/components/company/BotConfigStep/BotConfigStep.jsx';
import StatefulMenuFlowEditor from '@/components/sections/StatefulMenuFlowEditor/StatefulMenuFlowEditor.jsx';
import { DAY_KEYS, DAY_LABELS } from '@/constants/botSettings';

function SettingsTab({
  companyForm,
  setCompanyForm,
  testConnection,
  testState,
  testResult,
  setTestState,
  testIxcConnection,
  ixcTestState,
  ixcTestResult,
  setIxcTestState,
  saveCompanyData,
  companySaveState,
  companySaveError,
  setting,
  settings,
  saveSettings,
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
}) {
  return (
    <>
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

          <div className="block text-sm">
            <label className="block">
              ID do numero (Meta / WhatsApp)
              <input
                type="text"
                value={companyForm.meta_phone_number_id}
                onChange={(e) => {
                  setCompanyForm((p) => ({ ...p, meta_phone_number_id: e.target.value }));
                  setTestState('idle');
                }}
                className="app-input"
              />
            </label>
            <div className="flex items-center gap-3 flex-wrap mt-2">
              <button
                type="button"
                onClick={testConnection}
                disabled={testState === 'loading' || !companyForm.meta_phone_number_id}
                className="app-btn-secondary text-xs"
              >
                {testState === 'loading' ? 'Testando...' : 'Testar conexão'}
              </button>
              {testState === 'ok' && testResult?.display_phone_number && (
                <span className="text-xs text-emerald-700">
                  Conexao OK - {testResult.display_phone_number}
                  {testResult.verified_name ? ` (${testResult.verified_name})` : ''}
                </span>
              )}
              {testState === 'error' && (
                <span className="text-xs text-red-600">Falha: {testResult?.error || 'Credenciais invalidas.'}</span>
              )}
            </div>
          </div>

          <label className="block text-sm">
            WABA ID (WhatsApp Business Account)
            <input
              type="text"
              value={companyForm.meta_waba_id}
              onChange={(e) => setCompanyForm((p) => ({ ...p, meta_waba_id: e.target.value }))}
              className="app-input"
            />
          </label>

          <label className="block text-sm md:col-span-2">
            URL base IXC
            <input
              type="url"
              value={companyForm.ixc_base_url}
              onChange={(e) => {
                setCompanyForm((p) => ({ ...p, ixc_base_url: e.target.value }));
                setIxcTestState('idle');
              }}
              className="app-input"
              placeholder="https://ip-ou-dominio/webservice/v1"
            />
          </label>

          <label className="block text-sm md:col-span-2">
            Token IXC
            <input
              type="password"
              value={companyForm.ixc_api_token}
              onChange={(e) => {
                setCompanyForm((p) => ({ ...p, ixc_api_token: e.target.value }));
                setIxcTestState('idle');
              }}
              className="app-input"
              placeholder="Preencher apenas para atualizar"
            />
          </label>

          <label className="block text-sm">
            Timeout IXC (segundos)
            <input
              type="number"
              min={5}
              max={60}
              value={companyForm.ixc_timeout_seconds}
              onChange={(e) => setCompanyForm((p) => ({ ...p, ixc_timeout_seconds: Number(e.target.value) }))}
              className="app-input"
            />
          </label>

          <div className="block text-sm">
            <label className="flex items-center gap-2 mt-7">
              <input
                type="checkbox"
                checked={Boolean(companyForm.ixc_self_signed)}
                onChange={(e) => setCompanyForm((p) => ({ ...p, ixc_self_signed: e.target.checked }))}
              />
              Permitir certificado autoassinado (IXC)
            </label>
          </div>

          <label className="flex items-center gap-2 text-sm md:col-span-2">
            <input
              type="checkbox"
              checked={Boolean(companyForm.ixc_enabled)}
              onChange={(e) => setCompanyForm((p) => ({ ...p, ixc_enabled: e.target.checked }))}
            />
            Habilitar modulo IXC para esta empresa
          </label>

          <div className="md:col-span-2">
            <div className="flex items-center gap-3 flex-wrap mt-1">
              <button
                type="button"
                onClick={testIxcConnection}
                disabled={ixcTestState === 'loading' || !companyForm.ixc_base_url}
                className="app-btn-secondary text-xs"
              >
                {ixcTestState === 'loading' ? 'Testando IXC...' : 'Testar conexao IXC'}
              </button>
              {ixcTestState === 'ok' && (
                <span className="text-xs text-emerald-700">Conexao IXC OK.</span>
              )}
              {ixcTestState === 'error' && (
                <span className="text-xs text-red-600">Falha IXC: {ixcTestResult?.error || 'Credenciais invalidas.'}</span>
              )}
            </div>
          </div>

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
          <p className="text-sm text-[#737373]">Empresa ainda usando configuracao padrao.</p>
        ) : (
          <ul className="text-sm space-y-1">
            <li>Mensagem de boas-vindas: {setting.welcome_message || '-'}</li>
            <li>Mensagem quando nao entende (fallback): {setting.fallback_message || '-'}</li>
            <li>Mensagem fora de horario: {setting.out_of_hours_message || '-'}</li>
            <li>
              Respostas por palavra-chave: {Array.isArray(setting.keyword_replies) ? setting.keyword_replies.length : 0}
            </li>
            <li>
              Areas de atendimento: {Array.isArray(setting.service_areas) ? setting.service_areas.join(', ') || '-' : '-'}
            </li>
            <li>Menu com fluxo personalizado: {setting.stateful_menu_flow ? 'Sim' : 'Nao (usa padrao automatico)'}</li>
          </ul>
        )}
      </section>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#737373] mb-1">Editar configuracoes (admin)</h2>
        <p className="text-sm text-[#737373] mb-4 max-w-3xl">
          Mesma ordem recomendada que no painel da empresa: contexto e areas, expediente, mensagens, menu e
          palavras-chave.
        </p>

        <nav className="bot-config-toc mb-6" aria-label="Ordem da configuracao">
          <ol className="bot-config-toc-list">
            <li>Estado e fuso</li>
            <li>Areas de atendimento</li>
            <li>Horario comercial</li>
            <li>Mensagens automaticas</li>
            <li>Menu numerado</li>
            <li>Palavras-chave</li>
          </ol>
        </nav>

        <form onSubmit={saveSettings} className="bot-config-form">
          <BotConfigStep
            step={1}
            title="Estado e contexto"
            intro="Defina se o bot responde sozinho e em qual fuso o expediente sera calculado."
            rules={[
              'Ative o bot apenas quando mensagens e fluxo estiverem prontos para teste ou producao.',
              'Use um fuso valido (ex.: America/Sao_Paulo); ele afeta a mensagem de fora do expediente.',
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
                  <span className="text-sm">Ativar respostas automaticas</span>
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
                <span className="bot-config-label">Fuso horario</span>
                <input
                  type="text"
                  value={settings.timezone}
                  onChange={(e) => updateMessageField('timezone', e.target.value)}
                  placeholder="Ex: America/Sao_Paulo"
                  className="app-input"
                />
              </label>
            </div>
          </BotConfigStep>

          <BotConfigStep
            step={2}
            title="Areas de atendimento"
            intro="Nomes das equipes ou setores usados em transferencias e no menu (quando aplicavel)."
            rules={[
              'Cadastre as areas antes de montar o menu personalizado que transfere para um setor.',
              'Prefira nomes curtos e claros para o cliente entender na primeira leitura.',
            ]}
          >
            <div className="bot-config-section-header">
              <p className="bot-config-hint bot-config-hint--flush">Ex.: Suporte, Vendas, Financeiro</p>
              <button type="button" onClick={addServiceArea} className="app-btn-secondary">
                Adicionar area
              </button>
            </div>
            {!settings.service_areas?.length && (
              <p className="text-sm text-[#737373]">Nenhuma area cadastrada.</p>
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
                  <button type="button" onClick={() => removeServiceArea(index)} className="app-btn-danger">
                    Remover
                  </button>
                </div>
              ))}
            </div>
          </BotConfigStep>

          <BotConfigStep
            step={3}
            title="Horario comercial"
            intro="Marque os dias e intervalos em que o atendimento considera expediente."
            rules={[
              'Em dias desmarcados ou fora do intervalo, vale a mensagem de fora do expediente (passo 4).',
              'Garanta que o horario de inicio seja anterior ao de fim no mesmo dia.',
            ]}
          >
            <div className="space-y-3">
              {DAY_KEYS.map((day) => {
                const cfg = settings.business_hours[day] || { enabled: false, start: '', end: '' };
                return (
                  <div
                    key={day}
                    className="grid grid-cols-1 md:grid-cols-4 gap-3 items-center border border-[#efefec] rounded p-3"
                  >
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
          </BotConfigStep>

          <BotConfigStep
            step={4}
            title="Mensagens automaticas"
            intro="Textos na entrada, quando nao entende o pedido e fora do expediente."
            rules={['Boas-vindas: primeira impressao; fallback: quando nenhuma regra se aplica; fora de horario: depende do passo 3.']}
          >
            <div className="bot-config-messages-grid">
              <label className="bot-config-field">
                <span className="bot-config-label">Boas-vindas</span>
                <textarea
                  value={settings.welcome_message || ''}
                  onChange={(e) => updateMessageField('welcome_message', e.target.value)}
                  rows={3}
                  className="app-input"
                />
              </label>

              <label className="bot-config-field">
                <span className="bot-config-label">Fallback (quando nao entende)</span>
                <textarea
                  value={settings.fallback_message || ''}
                  onChange={(e) => updateMessageField('fallback_message', e.target.value)}
                  rows={3}
                  className="app-input"
                />
              </label>

              <label className="bot-config-field">
                <span className="bot-config-label">Fora de horario</span>
                <textarea
                  value={settings.out_of_hours_message || ''}
                  onChange={(e) => updateMessageField('out_of_hours_message', e.target.value)}
                  rows={3}
                  className="app-input"
                />
              </label>
            </div>
          </BotConfigStep>

          <BotConfigStep
            step={5}
            title="Menu numerado do bot"
            intro="Fluxo principal: opções numeradas, blocos e transferencias para as areas do passo 2."
            rules={[
              'Com modelo automatico, revise textos antes de publicar; com fluxo personalizado, mantenha opções objetivas.',
              'O menu pode iniciar automaticamente na primeira mensagem. O comando # faz o cliente voltar ao inicio.',
            ]}
          >
            <div className="bot-config-section-header">
              <p className="bot-config-hint bot-config-hint--flush">
                Monte o fluxo em blocos, com uma navegacao lateral para editar menus, perguntas abertas e transferencias
                sem ficar abrindo varias areas para baixo.
              </p>

              <div className="flex gap-2 flex-wrap">
                <button type="button" onClick={loadSuggestedMenuTemplate} className="app-btn-secondary">
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
                  Usar modelo automatico
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
                Usar menu padrao automatico (sem edicao manual)
              </label>

              <p className="mt-2 text-xs text-[#706f6c]">
                Desmarque para montar um fluxo personalizado com blocos, opções, perguntas abertas e transferencias.
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
              'Cadastre depois do menu para nao duplicar o que ja esta no fluxo numerado.',
              'Evite a mesma palavra em duas regras; normalize termos para bater com o que o cliente digita.',
            ]}
          >
            <div className="flex flex-wrap justify-end gap-2 mb-2">
              <button type="button" onClick={addKeywordReply} className="app-btn-secondary">
                Adicionar regra
              </button>
            </div>

            {!settings.keyword_replies.length && (
              <p className="text-sm text-[#737373]">Nenhuma regra cadastrada.</p>
            )}

            <div className="space-y-3">
              {settings.keyword_replies.map((item, index) => (
                <div
                  key={index}
                  className="grid grid-cols-1 md:grid-cols-5 gap-3 border border-[#efefec] rounded p-3"
                >
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
                    <button type="button" onClick={() => removeKeywordReply(index)} className="app-btn-danger w-full">
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
              {saveState === 'saving' ? 'Salvando...' : 'Salvar configuracoes (admin)'}
            </button>

            {saveState === 'saved' && <p className="text-sm text-green-700">Configuracoes salvas com sucesso.</p>}
            {saveState === 'error' && <p className="text-sm text-red-600">{saveError}</p>}
          </div>
        </form>
      </section>
    </>
  );
}

export default SettingsTab;
