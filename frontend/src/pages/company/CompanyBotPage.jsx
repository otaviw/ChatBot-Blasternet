import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import usePageData from '../../hooks/usePageData';
import useLogout from '../../hooks/useLogout';
import api from '../../lib/api';
import { DAY_KEYS, DAY_LABELS, DEFAULT_SETTINGS, normalizeSettings } from '../../constants/botSettings';

function CompanyBotPage() {
  const { data, loading, error } = usePageData('/minha-conta/bot');
  const { logout } = useLogout();
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [saveState, setSaveState] = useState('idle');
  const [saveError, setSaveError] = useState('');

  useEffect(() => {
    if (!data?.settings) return;
    setSettings(normalizeSettings(data.settings));
  }, [data]);

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

      const payload = {
        ...settings,
        keyword_replies: settings.keyword_replies.filter((item) => item.keyword?.trim() && item.reply?.trim()),
        service_areas: [...normalizedAreasMap.values()],
      };

      const response = await api.put('/minha-conta/bot', payload);
      setSettings(normalizeSettings(response.data?.settings));
      setSaveState('saved');
      setTimeout(() => setSaveState('idle'), 2500);
    } catch (err) {
      setSaveState('error');
      setSaveError(err.response?.data?.message || 'Falha ao salvar configuracoes.');
    }
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando configuracoes do bot...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !data.company) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">
          Nao foi possivel carregar as configuracoes do bot.
        </p>
      </Layout>
    );
  }

  const company = data.company;

  return (
    <Layout role="company" companyName={company.name} onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Configuracoes do bot - {company.name}</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
        Defina mensagens, horarios e respostas por palavra-chave.
      </p>

      <form onSubmit={saveSettings} className="space-y-8 max-w-4xl">
        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
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
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="font-medium">Areas de atendimento</h2>
            <button
              type="button"
              onClick={addServiceArea}
              className="px-3 py-1.5 text-sm rounded border border-[#d5d5d2]"
            >
              Adicionar area
            </button>
          </div>
          {!settings.service_areas?.length && (
            <p className="text-sm text-[#706f6c]">Nenhuma area cadastrada.</p>
          )}
          <div className="space-y-2">
            {(settings.service_areas ?? []).map((area, index) => (
              <div key={index} className="flex gap-2">
                <input
                  type="text"
                  value={area}
                  onChange={(e) => updateServiceArea(index, e.target.value)}
                  placeholder="Ex.: Suporte"
                  className="flex-1 rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615] text-sm"
                />
                <button
                  type="button"
                  onClick={() => removeServiceArea(index)}
                  className="px-3 py-2 text-sm rounded border border-red-300 text-red-700"
                >
                  Remover
                </button>
              </div>
            ))}
          </div>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
          <h2 className="font-medium">Mensagens</h2>
          <label className="block text-sm">
            Boas-vindas
            <textarea
              value={settings.welcome_message || ''}
              onChange={(e) => updateMessageField('welcome_message', e.target.value)}
              rows={3}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Fallback (quando nao entende)
            <textarea
              value={settings.fallback_message || ''}
              onChange={(e) => updateMessageField('fallback_message', e.target.value)}
              rows={3}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Fora de horario
            <textarea
              value={settings.out_of_hours_message || ''}
              onChange={(e) => updateMessageField('out_of_hours_message', e.target.value)}
              rows={3}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
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
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
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
                      className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615] disabled:opacity-50"
                    />
                  </label>

                  <label className="text-sm">
                    Fim
                    <input
                      type="time"
                      value={cfg.end || ''}
                      onChange={(e) => updateDay(day, { end: e.target.value })}
                      disabled={!cfg.enabled}
                      className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615] disabled:opacity-50"
                    />
                  </label>
                </div>
              );
            })}
          </div>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="font-medium">Respostas por palavra-chave</h2>
            <button
              type="button"
              onClick={addKeywordReply}
              className="px-3 py-1.5 text-sm rounded border border-[#d5d5d2]"
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
                    className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                  />
                </label>

                <label className="text-sm md:col-span-3">
                  Resposta
                  <input
                    type="text"
                    value={item.reply || ''}
                    onChange={(e) => updateKeyword(index, 'reply', e.target.value)}
                    className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                  />
                </label>

                <div className="md:col-span-1 flex items-end">
                  <button
                    type="button"
                    onClick={() => removeKeywordReply(index)}
                    className="w-full px-3 py-1.5 text-sm rounded border border-red-300 text-red-700"
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
            className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
          >
            {saveState === 'saving' ? 'Salvando...' : 'Salvar configuracoes'}
          </button>

          {saveState === 'saved' && <p className="text-sm text-green-700">Configuracoes salvas com sucesso.</p>}
          {saveState === 'error' && <p className="text-sm text-red-600">{saveError}</p>}
        </div>
      </form>
    </Layout>
  );
}

export default CompanyBotPage;
