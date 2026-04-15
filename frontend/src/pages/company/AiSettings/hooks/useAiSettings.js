import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  getSettings,
  getUsers,
  updateSettings,
  updateUserPermission,
} from '@/services/aiSettingsService';
import { showError, showSuccess } from '@/services/toastService';

const parseRequestError = (error, fallback) =>
  error?.response?.data?.message ??
  error?.response?.data?.errors?.ai?.[0] ??
  fallback;

export default function useAiSettings({ enabled, companyId }) {
  const [company, setCompany] = useState(null);
  const [companies, setCompanies] = useState([]);
  const [settings, setSettings] = useState(null);
  const [users, setUsers] = useState([]);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState('');
  const [permissionBusyById, setPermissionBusyById] = useState({});

  const loadData = useCallback(async () => {
    if (!enabled) {
      return;
    }

    setLoading(true);
    setError('');

    try {
      const [settingsResponse, usersResponse] = await Promise.all([
        getSettings(companyId),
        getUsers(companyId),
      ]);
      setCompany(settingsResponse.company ?? null);
      setCompanies(settingsResponse.companies ?? []);
      setSettings(settingsResponse.settings ?? null);
      setUsers(usersResponse.users ?? []);
    } catch (requestError) {
      setError(parseRequestError(requestError, 'Não foi possível carregar as configurações de IA.'));
    } finally {
      setLoading(false);
    }
  }, [enabled, companyId]);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  const updateField = useCallback((key, value) => {
    setSettings((previous) => {
      if (!previous) {
        return previous;
      }

      return {
        ...previous,
        [key]: value,
      };
    });
  }, []);

  const saveSettingsChanges = useCallback(async () => {
    if (!enabled || !settings) {
      return false;
    }

    setSaving(true);
    setSaveError('');

    try {
      const response = await updateSettings(settings, companyId);
      setSettings(response.settings ?? settings);
      showSuccess('Configurações de IA salvas com sucesso.');
      return true;
    } catch (requestError) {
      const message = parseRequestError(requestError, 'Não foi possível salvar as configurações.');
      setSaveError(message);
      showError(message);
      return false;
    } finally {
      setSaving(false);
    }
  }, [enabled, settings, companyId]);

  const toggleUserPermission = useCallback(
    async (userId, canUseAi) => {
      const normalizedUserId = Number.parseInt(String(userId ?? ''), 10);
      if (!normalizedUserId || !enabled) {
        return;
      }

      setPermissionBusyById((previous) => ({ ...previous, [normalizedUserId]: true }));

      try {
        const response = await updateUserPermission(normalizedUserId, canUseAi, companyId);
        const updatedUser = response.user;

        setUsers((previous) =>
          previous.map((user) => {
            if (Number(user.id) !== normalizedUserId) {
              return user;
            }

            return {
              ...user,
              ...(updatedUser ?? {}),
              can_use_ai: Boolean(updatedUser?.can_use_ai ?? canUseAi),
            };
          })
        );

        showSuccess('Permissão de IA atualizada.');
      } catch (requestError) {
        const message = parseRequestError(requestError, 'Não foi possível atualizar a permissão do usuário.');
        showError(message);
      } finally {
        setPermissionBusyById((previous) => ({ ...previous, [normalizedUserId]: false }));
      }
    },
    [enabled, companyId]
  );

  const canSave = useMemo(() => Boolean(settings) && !saving, [saving, settings]);

  return {
    company,
    companies,
    settings,
    users,
    loading,
    error,
    saving,
    saveError,
    canSave,
    permissionBusyById,
    updateField,
    saveSettingsChanges,
    toggleUserPermission,
    reload: loadData,
  };
}
