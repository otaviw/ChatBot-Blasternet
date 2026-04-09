import { useMemo } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import {
  CheckboxField,
  Field,
  SelectInput,
  TextAreaInput,
  TextInput,
} from '@/components/ui/FormControls/FormControls.jsx';
import Notice from '@/components/ui/Notice/Notice.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import usePageData from '@/hooks/usePageData';
import useAuth from '@/hooks/useAuth';
import useLogout from '@/hooks/useLogout';
import useAdminCompanySelector from '@/hooks/useAdminCompanySelector';
import useAiSettings from './hooks/useAiSettings';

function AiSettingsPage() {
  const { data, loading: meLoading, error: meError } = usePageData('/me');
  const { user: authUser } = useAuth();
  const { logout } = useLogout();
  const isAdmin = authUser?.role === 'system_admin';

  const { companies: selectorCompanies, selectedCompanyId, setSelectedCompanyId } = useAdminCompanySelector({ isAdmin });

  const canManageUsers = Boolean(
    data?.user?.can_manage_users ||
    (data?.user?.role === 'company_admin' && data?.user?.company_id) ||
    data?.user?.role === 'system_admin'
  );

  const resolvedCompanyId = isAdmin ? selectedCompanyId : '';

  const {
    company,
    settings,
    users,
    loading,
    error,
    saving,
    canSave,
    permissionBusyById,
    updateField,
    saveSettingsChanges,
    toggleUserPermission,
  } = useAiSettings({ enabled: canManageUsers, companyId: resolvedCompanyId });

  const companyName = data?.user?.company_name ?? company?.name ?? 'Empresa';
  const layoutRole = isAdmin ? 'admin' : 'company';

  const usersRows = useMemo(() => users ?? [], [users]);

  const handleSave = async (event) => {
    event.preventDefault();
    await saveSettingsChanges();
  };

  if (meLoading) {
    return (
      <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
        <p className="text-sm text-[#64748b]">Carregando configurações de IA...</p>
      </Layout>
    );
  }

  if (meError || !data?.authenticated) {
    return (
      <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
        <p className="text-sm text-red-600">Não foi possível carregar as configurações de IA.</p>
      </Layout>
    );
  }

  if (!canManageUsers) {
    return (
      <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
        <p className="text-sm text-[#64748b]">Acesso restrito ao administrador da empresa.</p>
      </Layout>
    );
  }

  if (loading || !settings) {
    return (
      <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
        <p className="text-sm text-[#64748b]">Carregando dados de IA...</p>
      </Layout>
    );
  }

  if (error) {
    return (
      <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
        <p className="text-sm text-red-600">{error}</p>
      </Layout>
    );
  }

  return (
    <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
      <PageHeader
        title="Configurações de IA"
        subtitle="Defina ativação, comportamento, limites de uso e permissões da IA para a sua empresa."
        action={isAdmin && selectorCompanies.length > 0 ? (
          <select
            value={selectedCompanyId}
            onChange={(e) => setSelectedCompanyId(e.target.value)}
            className="rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm text-[#1f2937] outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
          >
            {selectorCompanies.map((c) => (
              <option key={c.id} value={String(c.id)}>{c.name}</option>
            ))}
          </select>
        ) : undefined}
      />

      <form onSubmit={handleSave} className="space-y-4">
        <Card>
          <h2 className="text-base font-semibold text-[#0f172a] mb-3">Ativação</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <CheckboxField
              checked={Boolean(settings.ai_enabled)}
              onChange={(event) => updateField('ai_enabled', event.target.checked)}
            >
              IA ativa
            </CheckboxField>
            <CheckboxField
              checked={Boolean(settings.ai_internal_chat_enabled)}
              onChange={(event) => updateField('ai_internal_chat_enabled', event.target.checked)}
            >
              Chat interno com IA
            </CheckboxField>
          </div>
        </Card>

        <Card>
          <h2 className="text-base font-semibold text-[#0f172a] mb-3">Comportamento da IA</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <Field label="Persona (papel da IA)">
              <TextInput
                type="text"
                value={settings.ai_persona ?? ''}
                onChange={(event) => updateField('ai_persona', event.target.value)}
                placeholder="Ex.: Especialista em atendimento"
              />
            </Field>

            <Field label="Tom de voz">
              <SelectInput
                value={settings.ai_tone ?? 'casual'}
                onChange={(event) => updateField('ai_tone', event.target.value)}
              >
                <option value="formal">Formal</option>
                <option value="casual">Descontraído</option>
                <option value="tecnico">Técnico</option>
              </SelectInput>
            </Field>

            <Field label="Idioma">
              <SelectInput
                value={settings.ai_language ?? 'pt-BR'}
                onChange={(event) => updateField('ai_language', event.target.value)}
              >
                <option value="pt-BR">Português (Brasil)</option>
                <option value="en-US">Inglês (EUA)</option>
                <option value="es-ES">Espanhol</option>
              </SelectInput>
            </Field>

            <Field label="Formalidade">
              <SelectInput
                value={settings.ai_formality ?? 'media'}
                onChange={(event) => updateField('ai_formality', event.target.value)}
              >
                <option value="baixa">Baixa</option>
                <option value="media">Média</option>
                <option value="alta">Alta</option>
              </SelectInput>
            </Field>
          </div>

          <Field label="Instruções de sistema (prompt)" className="mt-3">
            <TextAreaInput
              rows={5}
              value={settings.ai_system_prompt ?? ''}
              onChange={(event) => updateField('ai_system_prompt', event.target.value)}
              placeholder="Instruções fixas para orientar o comportamento da IA."
            />
          </Field>
        </Card>

        <Card>
          <h2 className="text-base font-semibold text-[#0f172a] mb-3">Uso e limites</h2>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <CheckboxField
              checked={Boolean(settings.ai_usage_enabled)}
              onChange={(event) => updateField('ai_usage_enabled', event.target.checked)}
            >
              Controle de uso ativo
            </CheckboxField>

            <Field label="Limite mensal de uso">
              <TextInput
                type="number"
                min="1"
                inputMode="numeric"
                value={settings.ai_usage_limit_monthly ?? ''}
                onChange={(event) => {
                  const value = event.target.value;
                  updateField('ai_usage_limit_monthly', value === '' ? null : Number(value));
                }}
                placeholder="Ex: 1000"
              />
            </Field>
          </div>

          <Notice tone="info" className="mt-3">
            Se o limite for atingido, a IA será desativada automaticamente.
          </Notice>
        </Card>

        <Card>
          <h2 className="text-base font-semibold text-[#0f172a] mb-3">Permissões dos utilizadores</h2>

          {!usersRows.length ? (
            <p className="text-sm text-[#64748b]">Nenhum utilizador encontrado.</p>
          ) : (
            <div className="overflow-x-auto app-responsive-table-wrap">
              <table className="min-w-full text-sm app-responsive-table">
                <thead>
                  <tr className="border-b border-[#e5e7eb] text-left text-[#64748b]">
                    <th className="py-2 pr-3 font-medium">Nome</th>
                    <th className="py-2 pr-3 font-medium">E-mail</th>
                    <th className="py-2 pr-3 font-medium">Pode usar IA</th>
                  </tr>
                </thead>
                <tbody>
                  {usersRows.map((user) => {
                    const isBusy = Boolean(permissionBusyById[user.id]);
                    const isAgent = String(user.role ?? '').trim() === 'agent';
                    const checked = isAgent ? Boolean(user.can_use_ai) : true;

                    return (
                      <tr key={user.id} className="border-b border-[#f1f5f9]">
                        <td data-label="Nome" className="py-2 pr-3 text-[#0f172a]">{user.name || '-'}</td>
                        <td data-label="E-mail" className="py-2 pr-3 text-[#475569]">{user.email || '-'}</td>
                        <td data-label="Pode usar IA" className="py-2 pr-3">
                          <label className="inline-flex items-center gap-2 text-[#1f2937]">
                            <input
                              type="checkbox"
                              className="h-4 w-4 rounded border-[#d4d4d4] text-[#2563eb] focus:ring-[#2563eb]/20"
                              checked={checked}
                              disabled={isBusy || !isAgent}
                              onChange={(event) =>
                                void toggleUserPermission(user.id, event.target.checked)
                              }
                            />
                            <span className="text-xs text-[#64748b]">
                              {isBusy ? 'Salvando...' : isAgent ? 'Ativo' : 'Sempre ativo para administradores'}
                            </span>
                          </label>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <div className="flex items-center gap-3">
          <Button type="submit" variant="primary" disabled={!canSave}>
            {saving ? 'Salvando...' : 'Salvar alterações'}
          </Button>
        </div>
      </form>
    </Layout>
  );
}

export default AiSettingsPage;
