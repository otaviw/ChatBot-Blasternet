import { useCallback, useEffect, useState } from 'react';
import { REALTIME_EVENTS } from '@/constants/realtimeEvents';
import {
  DEFAULT_SETTINGS,
  normalizeSettings,
} from '@/constants/botSettings';
import {
  coerceInactivityCloseHours,
  normalizeKeywordReplies,
  normalizeServiceAreas,
} from '@/services/botSettingsPayload';
import realtimeClient from '@/services/realtimeClient';
import {
  editorToStatefulMenuFlow,
  statefulMenuFlowToEditor,
  validateStatefulMenuEditor,
} from '@/services/statefulMenuFlow';

export default function useBotSettingsEditor({
  initialSettings = null,
  realtimeCompanyId = null,
  reloadSettings,
  persistSettings,
}) {
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [saveState, setSaveState] = useState('idle');
  const [saveError, setSaveError] = useState('');
  const [useDefaultStatefulMenu, setUseDefaultStatefulMenu] = useState(true);
  const [statefulMenuEditor, setStatefulMenuEditor] = useState(() => statefulMenuFlowToEditor(null));
  const [menuFlowError, setMenuFlowError] = useState('');

  const applySettings = useCallback((rawSettings) => {
    const normalized = normalizeSettings(rawSettings);
    setSettings(normalized);
    setUseDefaultStatefulMenu(!normalized.stateful_menu_flow);
    setStatefulMenuEditor(
      statefulMenuFlowToEditor(normalized.stateful_menu_flow, normalized.welcome_message)
    );
    setMenuFlowError('');
  }, []);

  useEffect(() => {
    if (!initialSettings) {
      return;
    }

    applySettings(initialSettings);
  }, [applySettings, initialSettings]);

  useEffect(() => {
    if (!realtimeCompanyId) {
      return undefined;
    }

    const unsubscribe = realtimeClient.on(REALTIME_EVENTS.BOT_UPDATED, async (envelope) => {
      const payload = envelope?.payload ?? {};
      if (Number(payload.companyId) !== Number(realtimeCompanyId)) {
        return;
      }

      if (typeof reloadSettings !== 'function') {
        return;
      }

      try {
        const latestSettings = await reloadSettings();
        if (latestSettings) {
          applySettings(latestSettings);
        }
      } catch {
        // Keep current settings when background sync fails.
      }
    });

    return () => {
      unsubscribe();
    };
  }, [applySettings, realtimeCompanyId, reloadSettings]);

  const updateMessageField = useCallback((key, value) => {
    setSettings((prev) => ({ ...prev, [key]: value }));
  }, []);

  const updateDay = useCallback((day, patch) => {
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
  }, []);

  const updateKeyword = useCallback((index, key, value) => {
    setSettings((prev) => {
      const next = [...prev.keyword_replies];
      next[index] = { ...next[index], [key]: value };
      return { ...prev, keyword_replies: next };
    });
  }, []);

  const addKeywordReply = useCallback(() => {
    setSettings((prev) => ({
      ...prev,
      keyword_replies: [...prev.keyword_replies, { keyword: '', reply: '' }],
    }));
  }, []);

  const removeKeywordReply = useCallback((index) => {
    setSettings((prev) => ({
      ...prev,
      keyword_replies: prev.keyword_replies.filter((_, i) => i !== index),
    }));
  }, []);

  const updateServiceArea = useCallback((index, value) => {
    setSettings((prev) => {
      const next = [...(prev.service_areas ?? [])];
      next[index] = value;
      return { ...prev, service_areas: next };
    });
  }, []);

  const addServiceArea = useCallback(() => {
    setSettings((prev) => ({
      ...prev,
      service_areas: [...(prev.service_areas ?? []), ''],
    }));
  }, []);

  const removeServiceArea = useCallback((index) => {
    setSettings((prev) => ({
      ...prev,
      service_areas: (prev.service_areas ?? []).filter((_, i) => i !== index),
    }));
  }, []);

  const loadSuggestedMenuTemplate = useCallback(() => {
    setStatefulMenuEditor(statefulMenuFlowToEditor(null, settings.welcome_message));
    setMenuFlowError('');
  }, [settings.welcome_message]);

  const enableCustomMenuBuilder = useCallback(() => {
    if (useDefaultStatefulMenu) {
      setStatefulMenuEditor(statefulMenuFlowToEditor(null, settings.welcome_message));
    }
    setUseDefaultStatefulMenu(false);
    setMenuFlowError('');
  }, [settings.welcome_message, useDefaultStatefulMenu]);

  const saveSettings = useCallback(
    async (event) => {
      event.preventDefault();
      setSaveState('saving');
      setSaveError('');
      setMenuFlowError('');

      try {
        const serviceAreas = normalizeServiceAreas(settings.service_areas);

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
          inactivity_close_hours: coerceInactivityCloseHours(settings.inactivity_close_hours, 24),
          keyword_replies: normalizeKeywordReplies(settings.keyword_replies),
          service_areas: serviceAreas,
          stateful_menu_flow: nextStatefulFlow,
        };

        const persistedSettings = await persistSettings(payload);
        applySettings(persistedSettings);
        setSaveState('saved');
        setTimeout(() => setSaveState('idle'), 2500);
      } catch (err) {
        setSaveState('error');
        setSaveError(err.response?.data?.message || 'Falha ao salvar configuracoes.');
      }
    },
    [applySettings, persistSettings, settings, statefulMenuEditor, useDefaultStatefulMenu]
  );

  return {
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
  };
}
