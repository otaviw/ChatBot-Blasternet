import './CompanyBotPage.css';
import { useState, useEffect } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import StatefulMenuFlowEditor from '@/components/sections/StatefulMenuFlowEditor/StatefulMenuFlowEditor.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import realtimeClient from '@/services/realtimeClient';
import {
  DAY_KEYS,
  DAY_LABELS,
  DEFAULT_SETTINGS,
  normalizeSettings,
} from '@/constants/botSettings';
import {
  editorToStatefulMenuFlow,
  statefulMenuFlowToEditor,
  validateStatefulMenuEditor,
} from '@/services/statefulMenuFlow';

function CompanyBotPage() {
  const { data, loading, error } = usePageData('/minha-conta/bot');
  const { logout } = useLogout();
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [saveState, setSaveState] = useState('idle');
  const [saveError, setSaveError] = useState('');
  const [useDefaultStatefulMenu, setUseDefaultStatefulMenu] = useState(true);
  const [statefulMenuEditor, setStatefulMenuEditor] = useState(() => statefulMenuFlowToEditor(null));
  const [menuFlowError, setMenuFlowError] = useState('');

  useEffect(() => {
    if (!data?.settings) return;
    const normalized = normalizeSettings(data.settings);
    setSettings(normalized);
    setUseDefaultStatefulMenu(!normalized.stateful_menu_flow);
    setStatefulMenuEditor(
      statefulMenuFlowToEditor(normalized.stateful_menu_flow, normalized.welcome_message)
    );
    setMenuFlowError('');
  }, [data]);

  useEffect(() => {
    if (!data?.company?.id) {
      return undefined;
    }

    const unsubscribe = realtimeClient.on('bot.updated', (envelope) => {
      const payload = envelope?.payload ?? {};
      if (Number(payload.companyId) !== Number(data.company.id)) {
        return;
      }

      api.get('/minha-conta/bot').then((response) => {
        const normalized = normalizeSettings(response.data?.settings);
        setSettings(normalized);
        setUseDefaultStatefulMenu(!normalized.stateful_menu_flow);
        setStatefulMenuEditor(
          statefulMenuFlowToEditor(normalized.stateful_menu_flow, normalized.welcome_message)
        );
        setMenuFlowError('');
      });
    });

    return () => {
      unsubscribe();
    };
  }, [data?.company?.id]);

  const updateMessageField = (key, value) => {
    setSettings((prev) => ({ ...prev, [key]: value }));
  };

  const updateDay = (day, patch) => {
    setSettings((prev) => ({
      ...prev,
      business_hours: {
        ...prev.business_hours,
        [day]: {
          ...prev.business_hours[day],
          ...patch,
        },
      },
    }));
  };

  const updateKeyword = (index, key, value) => {
    setSettings((prev) => {
      const next = [...prev.keyword_replies];
      next[index] = { ...next[index], [key]: value };
      return { ...prev, keyword_replies: next };
    });
  };

  const addKeywordReply = () => {
    setSettings((prev) => ({
      ...prev,
      keyword_replies: [...prev.keyword_replies, { keyword: '', reply: '' }],
    }));
  };

  const removeKeywordReply = (index) => {
    setSettings((prev) => ({
      ...prev,
      keyword_replies: prev.keyword_replies.filter((_, i) => i !== index),
    }));
  };

  const updateServiceArea = (index, value) => {
    setSettings((prev) => {
      const next = [...(prev.service_areas ?? [])];
      next[index] = value;
      return { ...prev, service_areas: next };
    });
  };

  const addServiceArea = () => {
    setSettings((prev) => ({
      ...prev,
      service_areas: [...(prev.service_areas ?? []), ''],
    }));
  };

  const removeServiceArea = (index) => {
    setSettings((prev) => ({
      ...prev,
      service_areas: (prev.service_areas ?? []).filter((_, i) => i !== index),
    }));
  };

  const saveSettings = async (event) => {
    event.preventDefault();
    setSaveState('saving');
    setSaveError('');
    setMenuFlowError('');

    try {
      const normalizedAreasMap = new Map();
      for (const rawArea of settings.service_areas ?? []) {
        const label = String(rawArea ?? '').trim();
        if (!label) continue;
        const key = label.toLowerCase();
        if (!normalizedAreasMap.has(key)) {
          normalizedAreasMap.set(key, label);
        }
      }

      let nextStatefulFlow = null;
      if (!useDefaultStatefulMenu) {
        const validationErrors = validateStatefulMenuEditor(statefulMenuEditor);
        if (validationErrors.length) {
          setSaveState('error');
          setMenuFlowError(validationErrors[0]);
          return;
        }

        nextStatefulFlow = editorToStatefulMenuFlow(statefulMenuEditor);
      }

      const payload = {
        ...settings,
        inactivity_close_hours: Number(settings.inactivity_close_hours ?? 24),
        keyword_replies: settings.keyword_replies.filter((item) => item.keyword?.trim() && item.reply?.trim()),
        service_areas: [...normalizedAreasMap.values()],
        stateful_menu_flow: nextStatefulFlow,
      };

      const response = await api.put('/minha-conta/bot', payload);
      const normalized = normalizeSettings(response.data?.settings);
      setSettings(normalized);
      setUseDefaultStatefulMenu(!normalized.stateful_menu_flow);
      setStatefulMenuEditor(
        statefulMenuFlowToEditor(normalized.stateful_menu_flow, normalized.welcome_message)
      );
      setSaveState('saved');
      setTimeout(() => setSaveState('idle'), 2500);
    } catch (err) {
      setSaveState('error');
      setSaveError(err.response?.data?.message || 'Falha ao salvar configurações.');
    }
  };

  const loadSuggestedMenuTemplate = () => {
    setStatefulMenuEditor(statefulMenuFlowToEditor(null, settings.welcome_message));
    setMenuFlowError('');
  };

  const enableCustomMenuBuilder = () => {
    if (useDefaultStatefulMenu) {
      setStatefulMenuEditor(statefulMenuFlowToEditor(null, settings.welcome_message));
    }
    setUseDefaultStatefulMenu(false);
    setMenuFlowError('');
  };

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
      <h1 className="app-page-title">Configurações do bot - {company.name}</h1>
      <p className="app-page-subtitle mb-6">
        Defina mensagens, horarios e respostas por palavra-chave.
      </p>

      <form onSubmit={saveSettings} className="space-y-8 max-w-4xl">
        <section className="app-panel space-y-4">
          <h2 className="font-medium">Estado e contexto</h2>
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
          <div className="flex items-center justify-between">
            <h2 className="font-medium">Áreas de atendimento</h2>
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

        <section className="app-panel space-y-4">
          <h2 className="font-medium">Mensagens</h2>
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
            Fallback (quando nao entende)
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
          <label className="block text-sm">
            Fechar conversa inativa após (horas)
            <input
              type="number"
              min="1"
              max="720"
              value={settings.inactivity_close_hours ?? 24}
              onChange={(e) => updateMessageField('inactivity_close_hours', Number(e.target.value))}
              className="app-input"
            />
          </label>
        </section>

        <section className="app-panel space-y-4">
          <div className="flex items-center justify-between gap-3">
            <h2 className="font-medium">Menu numerado (stateful)</h2>
            <div className="flex gap-2">
              <button
                type="button"
                onClick={loadSuggestedMenuTemplate}
                className="app-btn-secondary"
              >
                Recarregar modelo sugerido
              </button>
              <button
                type="button"
                onClick={() => {
                  setUseDefaultStatefulMenu(true);
                  setMenuFlowError('');
                }}
                className="app-btn-secondary"
              >
                Usar menu padrao automatico
              </button>
            </div>
          </div>

          <p className="text-sm text-[#706f6c]">
            O menu inicia automaticamente na primeira mensagem. O comando <strong>#</strong> continua resetando para o início.
          </p>

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
            Usar menu padrao automatico (sem customizacao manual)
          </label>

          {!useDefaultStatefulMenu && (
            <StatefulMenuFlowEditor
              value={statefulMenuEditor}
              onChange={setStatefulMenuEditor}
              serviceAreas={settings.service_areas ?? []}
            />
          )}

          {menuFlowError && <p className="text-sm text-red-600">{menuFlowError}</p>}
        </section>

        <section className="app-panel space-y-4">
          <h2 className="font-medium">Horario por dia</h2>
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
            <h2 className="font-medium">Respostas por palavra-chave</h2>
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

        <div className="flex items-center gap-3">
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




