import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import usePageData from '../../hooks/usePageData';
import useLogout from '../../hooks/useLogout';
import api from '../../lib/api';
import { DAY_KEYS, DAY_LABELS, DEFAULT_SETTINGS, normalizeSettings } from '../../constants/botSettings';

function AdminCompanyShowPage({ companyId }) {
  const { data, loading, error } = usePageData(`/admin/empresas/${companyId}`);
  const { logout } = useLogout();
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [saveState, setSaveState] = useState('idle');
  const [saveError, setSaveError] = useState('');
  const [companyForm, setCompanyForm] = useState({
    name: '',
    meta_phone_number_id: '',
    meta_access_token: '',
  });
  const [companySaveState, setCompanySaveState] = useState('idle');
  const [companySaveError, setCompanySaveError] = useState('');
  const { data: metricsData, loading: metricsLoading } = usePageData(
    `/admin/empresas/${companyId}/metricas`
  );

  useEffect(() => {
    if (!data?.company) return;
    setSettings(normalizeSettings(data.company.bot_setting));
    setCompanyForm({
      name: data.company.name ?? '',
      meta_phone_number_id: data.company.meta_phone_number_id ?? '',
      meta_access_token: '',
    });
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
      const response = await api.put(`/admin/empresas/${companyId}/bot`, payload);
      setSettings(normalizeSettings(response.data?.settings));
      setSaveState('saved');
      setTimeout(() => setSaveState('idle'), 2500);
    } catch (err) {
      setSaveState('error');
      setSaveError(err.response?.data?.message || 'Falha ao salvar configuracoes.');
    }
  };

  const saveCompanyData = async (event) => {
    event.preventDefault();
    setCompanySaveState('saving');
    setCompanySaveError('');

    try {
      const payload = {
        name: companyForm.name,
        meta_phone_number_id: companyForm.meta_phone_number_id || null,
      };
      if (companyForm.meta_access_token.trim() !== '') {
        payload.meta_access_token = companyForm.meta_access_token;
      }
      await api.put(`/admin/empresas/${companyId}`, payload);
      setCompanySaveState('saved');
      setCompanyForm((prev) => ({ ...prev, meta_access_token: '' }));
      setTimeout(() => {
        setCompanySaveState('idle');
        window.location.reload();
      }, 600);
    } catch (err) {
      setCompanySaveState('error');
      setCompanySaveError(err.response?.data?.message || 'Falha ao salvar dados da empresa.');
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando empresa...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !data.company) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar a empresa.</p>
      </Layout>
    );
  }

  const company = data.company;
  const setting = company.bot_setting;

  return (
    <Layout role="admin" onLogout={logout}>
      <div className="mb-4">
        <a href="/admin/empresas" className="text-sm text-[#706f6c] dark:text-[#A1A09A] hover:underline">
          {'<-'} Empresas
        </a>
      </div>
      <h1 className="text-xl font-medium mb-2">{company.name}</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">Informacoes e uso da empresa.</p>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">Informacoes</h2>
        <ul className="text-sm space-y-1">
          <li>ID: {company.id}</li>
          <li>Nome: {company.name}</li>
          <li>Meta Phone Number ID: {company.meta_phone_number_id ? company.meta_phone_number_id : '-'}</li>
          <li>Token configurado: {company.has_meta_credentials ? 'Sim' : 'Nao'}</li>
          <li>Bot ativo: {setting?.is_active ? 'Sim' : 'Nao'}</li>
          <li>Timezone: {setting?.timezone ?? 'America/Sao_Paulo'}</li>
        </ul>
      </section>

      {metricsLoading && <p className="text-sm text-[#706f6c]">Carregando métricas...</p>}
      {metricsData?.metrics && (
        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 mb-6">
          <h2 className="font-medium mb-4">Métricas</h2>

          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div className="border rounded-lg p-3 text-center">
              <p className="text-2xl font-medium">{metricsData.metrics.total}</p>
              <p className="text-xs text-[#706f6c]">Total de conversas</p>
            </div>
            <div className="border rounded-lg p-3 text-center">
              <p className="text-2xl font-medium">{metricsData.metrics.by_status?.open ?? 0}</p>
              <p className="text-xs text-[#706f6c]">Abertas</p>
            </div>
            <div className="border rounded-lg p-3 text-center">
              <p className="text-2xl font-medium">{metricsData.metrics.by_status?.closed ?? 0}</p>
              <p className="text-xs text-[#706f6c]">Encerradas</p>
            </div>
            <div className="border rounded-lg p-3 text-center">
              <p className="text-2xl font-medium">{metricsData.metrics.avg_response_minutes} min</p>
              <p className="text-xs text-[#706f6c]">Tempo médio de resposta</p>
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
                  <span>{metricsData.metrics.by_mode?.manual ?? 0}</span>
                </div>
              </div>
            </div>

            <div>
              <h3 className="text-sm font-medium mb-2">Últimos 30 dias</h3>
              <ul className="text-xs text-[#706f6c] space-y-1 max-h-32 overflow-y-auto">
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

      <section className="mb-8 border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
        <h2 className="font-medium mb-3">Dados da empresa (admin)</h2>
        <form onSubmit={saveCompanyData} className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="block text-sm md:col-span-2">
            Nome da empresa
            <input
              type="text"
              value={companyForm.name}
              onChange={(e) => setCompanyForm((p) => ({ ...p, name: e.target.value }))}
              required
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Meta Phone Number ID
            <input
              type="text"
              value={companyForm.meta_phone_number_id}
              onChange={(e) => setCompanyForm((p) => ({ ...p, meta_phone_number_id: e.target.value }))}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Novo Meta Access Token (opcional)
            <input
              type="password"
              value={companyForm.meta_access_token}
              onChange={(e) => setCompanyForm((p) => ({ ...p, meta_access_token: e.target.value }))}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <div className="md:col-span-2">
            <button
              type="submit"
              disabled={companySaveState === 'saving'}
              className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
            >
              {companySaveState === 'saving' ? 'Salvando dados...' : 'Salvar dados da empresa'}
            </button>
          </div>
        </form>
        {companySaveState === 'saved' && <p className="text-sm text-green-700 mt-2">Dados salvos.</p>}
        {companySaveState === 'error' && <p className="text-sm text-red-600 mt-2">{companySaveError}</p>}
      </section>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">Regras do bot</h2>
        {!setting ? (
          <p className="text-sm text-[#706f6c]">Empresa ainda usando configuracao padrao.</p>
        ) : (
          <ul className="text-sm space-y-1">
            <li>Mensagem de boas-vindas: {setting.welcome_message || '-'}</li>
            <li>Mensagem fallback: {setting.fallback_message || '-'}</li>
            <li>Mensagem fora de horario: {setting.out_of_hours_message || '-'}</li>
            <li>Respostas por palavra-chave: {Array.isArray(setting.keyword_replies) ? setting.keyword_replies.length : 0}</li>
            <li>Areas de atendimento: {Array.isArray(setting.service_areas) ? setting.service_areas.join(', ') || '-' : '-'}</li>
          </ul>
        )}
      </section>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">Editar configuracoes (admin)</h2>
        <form onSubmit={saveSettings} className="space-y-8 max-w-4xl">
          <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
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
                className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
            </label>
          </section>

          <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
            <h3 className="font-medium">Mensagens</h3>
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
          </section>

          <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
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
              <h3 className="font-medium">Respostas por palavra-chave</h3>
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

          <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="font-medium">Areas de atendimento</h3>
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

          <div className="flex items-center gap-3">
            <button
              type="submit"
              disabled={saveState === 'saving'}
              className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
            >
              {saveState === 'saving' ? 'Salvando...' : 'Salvar configuracoes (admin)'}
            </button>

            {saveState === 'saved' && <p className="text-sm text-green-700">Configuracoes salvas com sucesso.</p>}
            {saveState === 'error' && <p className="text-sm text-red-600">{saveError}</p>}
          </div>
        </form>
      </section>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">Uso</h2>
        <p className="text-sm">Total de conversas: <strong>{company.conversations_count ?? 0}</strong></p>
        {Array.isArray(company.conversations) && company.conversations.length > 0 && (
          <>
            <p className="text-sm text-[#706f6c] mt-2">Ultimas conversas (ate 10):</p>
            <ul className="mt-1 text-sm space-y-1">
              {company.conversations.map((conv) => (
                <li key={conv.id}>
                  {conv.customer_phone} - {conv.status} ({conv.created_at})
                </li>
              ))}
            </ul>
          </>
        )}
      </section>
    </Layout>
  );
}

export default AdminCompanyShowPage;
