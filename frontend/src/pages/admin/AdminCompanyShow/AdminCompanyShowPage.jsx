import './AdminCompanyShowPage.css';
import '@/styles/botConfigShared.css';
import { useState, useEffect, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import Layout from '@/components/layout/Layout/Layout.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import useBotSettingsEditor from '@/hooks/useBotSettingsEditor';
import api from '@/services/api';
import GeneralTab from './tabs/GeneralTab.jsx';
import UsersTab from './tabs/UsersTab.jsx';
import SettingsTab from './tabs/SettingsTab.jsx';

const ADMIN_COMPANY_TABS = [
  { key: 'general', label: 'Geral' },
  { key: 'users', label: 'Usuarios' },
  { key: 'settings', label: 'Configuracoes' },
];

function AdminCompanyShowPage({ companyId: companyIdProp }) {
  const { companyId: companyIdParam = '' } = useParams();
  const companyId = companyIdProp || companyIdParam;
  const { data, loading, error } = usePageData(`/admin/empresas/${companyId}`);
  const { data: metricsData, loading: metricsLoading } = usePageData(`/admin/empresas/${companyId}/metricas`);
  const { logout } = useLogout();
  const [activeTab, setActiveTab] = useState('general');
  const [companyForm, setCompanyForm] = useState({
    name: '',
    meta_phone_number_id: '',
    meta_waba_id: '',
    ai_enabled: false,
    ai_internal_chat_enabled: false,
  });
  const [companyData, setCompanyData] = useState(null);
  const [companySaveState, setCompanySaveState] = useState('idle');
  const [companySaveError, setCompanySaveError] = useState('');
  const [testState, setTestState] = useState('idle');
  const [testResult, setTestResult] = useState(null);

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
      meta_waba_id: data.company.meta_waba_id ?? '',
      ai_enabled: Boolean(data.company.bot_setting?.ai_enabled),
      ai_internal_chat_enabled: Boolean(data.company.bot_setting?.ai_internal_chat_enabled),
    });
  }, [data]);

  const testConnection = async () => {
    setTestState('loading');
    setTestResult(null);
    try {
      const response = await api.post(`/admin/empresas/${companyId}/validar-whatsapp`, {
        phone_number_id: companyForm.meta_phone_number_id || undefined,
      });
      setTestState('ok');
      setTestResult(response.data);
    } catch (err) {
      setTestState('error');
      setTestResult({ error: err.response?.data?.error || 'Erro ao testar conexão.' });
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
        meta_waba_id: companyForm.meta_waba_id || null,
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
          meta_waba_id: updatedCompany.meta_waba_id ?? '',
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
        <PageLoading rows={2} cards={2} />
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
        <p className="app-page-subtitle">Informacoes e uso da empresa</p>
      </div>

      <nav className="mb-6 border-b border-[#ececec]" aria-label="Abas da empresa">
        <div className="flex gap-2 overflow-x-auto pb-2">
          {ADMIN_COMPANY_TABS.map((tab) => {
            const isActive = activeTab === tab.key;
            return (
              <button
                key={tab.key}
                type="button"
                onClick={() => setActiveTab(tab.key)}
                className={`rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                  isActive ? 'bg-[#171717] text-white' : 'bg-[#f5f5f5] text-[#525252] hover:bg-[#ebebeb]'
                }`}
              >
                {tab.label}
              </button>
            );
          })}
        </div>
      </nav>

      {activeTab === 'general' && (
        <GeneralTab
          company={company}
          setting={setting}
          metricsLoading={metricsLoading}
          metricsData={metricsData}
        />
      )}

      {activeTab === 'users' && (
        <UsersTab
          company={company}
          metricsLoading={metricsLoading}
          metricsData={metricsData}
        />
      )}

      {activeTab === 'settings' && (
        <SettingsTab
          companyForm={companyForm}
          setCompanyForm={setCompanyForm}
          testConnection={testConnection}
          testState={testState}
          testResult={testResult}
          setTestState={setTestState}
          saveCompanyData={saveCompanyData}
          companySaveState={companySaveState}
          companySaveError={companySaveError}
          setting={setting}
          settings={settings}
          saveSettings={saveSettings}
          saveState={saveState}
          saveError={saveError}
          useDefaultStatefulMenu={useDefaultStatefulMenu}
          statefulMenuEditor={statefulMenuEditor}
          menuFlowError={menuFlowError}
          setUseDefaultStatefulMenu={setUseDefaultStatefulMenu}
          setStatefulMenuEditor={setStatefulMenuEditor}
          setMenuFlowError={setMenuFlowError}
          updateMessageField={updateMessageField}
          updateDay={updateDay}
          updateKeyword={updateKeyword}
          addKeywordReply={addKeywordReply}
          removeKeywordReply={removeKeywordReply}
          updateServiceArea={updateServiceArea}
          addServiceArea={addServiceArea}
          removeServiceArea={removeServiceArea}
          loadSuggestedMenuTemplate={loadSuggestedMenuTemplate}
          enableCustomMenuBuilder={enableCustomMenuBuilder}
        />
      )}
    </Layout>
  );
}

export default AdminCompanyShowPage;
