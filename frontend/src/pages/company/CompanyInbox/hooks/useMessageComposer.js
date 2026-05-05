import { useCallback, useEffect, useReducer, useState } from 'react';
import api from '@/services/api';
import { appendUniqueMessage } from '../inboxRealtimeUtils';
import useAttachmentUploader from './useAttachmentUploader';

const SET_MESSAGE_TEXT = 'SET_MESSAGE_TEXT';
const SET_MESSAGE_TYPE = 'SET_MESSAGE_TYPE';
const CLEAR_COMPOSER = 'CLEAR_COMPOSER';

const SET_MANUAL_STATE = 'SET_MANUAL_STATE';
const SET_AI_SUGGESTION_STATE = 'SET_AI_SUGGESTION_STATE';
const SET_USAGE_WARNING = 'SET_USAGE_WARNING';

const INITIAL_COMPOSER_STATE = {
  text: '',
  type: 'text',
};

const INITIAL_UI_STATE = {
  manualBusy: false,
  manualError: '',
  aiSuggestionBusy: false,
  aiSuggestionError: '',
  aiSuggestionStatus: '',
  aiConfidenceScore: null,
  aiSuggestionId: null,
  aiSuggestionFeedbackState: null,
  usageWarning: '',
};

const resolveNextValue = (previous, nextOrUpdater) => (
  typeof nextOrUpdater === 'function' ? nextOrUpdater(previous) : nextOrUpdater
);

function composerReducer(state, action) {
  switch (action.type) {
    case SET_MESSAGE_TEXT:
      return {
        ...state,
        text: String(resolveNextValue(state.text, action.payload) ?? ''),
      };
    case SET_MESSAGE_TYPE:
      return {
        ...state,
        type: String(action.payload ?? 'text'),
      };
    case CLEAR_COMPOSER:
      return {
        ...INITIAL_COMPOSER_STATE,
      };
    default:
      return state;
  }
}

function composerUiReducer(state, action) {
  switch (action.type) {
    case SET_MANUAL_STATE:
      return { ...state, ...action.payload };
    case SET_AI_SUGGESTION_STATE:
      return { ...state, ...action.payload };
    case SET_USAGE_WARNING:
      return { ...state, usageWarning: String(resolveNextValue(state.usageWarning, action.payload) ?? '') };
    default:
      return state;
  }
}

function resolveComposerType(file) {
  const mimeType = String(file?.type ?? '').toLowerCase();
  if (mimeType.startsWith('image/')) {
    return 'image';
  }
  if (mimeType.startsWith('audio/')) {
    return 'audio';
  }
  if (mimeType) {
    return 'document';
  }
  return 'text';
}

export default function useMessageComposer({
  detail,
  refreshConversations,
  setDetail,
  shouldScrollChatToBottomRef,
  upsertConversationInList,
  wasChatNearBottomRef,
}) {
  const [composerState, composerDispatch] = useReducer(composerReducer, INITIAL_COMPOSER_STATE);
  const [uiState, uiDispatch] = useReducer(composerUiReducer, INITIAL_UI_STATE);
  const [quickReplies, setQuickReplies] = useState([]);
  const attachmentUploader = useAttachmentUploader();

  const setManualText = useCallback((valueOrUpdater) => {
    composerDispatch({ type: SET_MESSAGE_TEXT, payload: valueOrUpdater });
  }, []);

  const setUsageWarning = useCallback((valueOrUpdater) => {
    uiDispatch({ type: SET_USAGE_WARNING, payload: valueOrUpdater });
  }, []);

  useEffect(() => {
    api
      .get('/minha-conta/respostas-rapidas')
      .then((response) => {
        setQuickReplies(response.data?.quick_replies ?? []);
      })
      .catch(() => {
        setQuickReplies([]);
      });
  }, []);

  const resetAiSuggestionState = useCallback(() => {
    uiDispatch({
      type: SET_AI_SUGGESTION_STATE,
      payload: {
        aiSuggestionBusy: false,
        aiSuggestionError: '',
        aiSuggestionStatus: '',
      },
    });
  }, []);

  const clearComposer = useCallback(() => {
    composerDispatch({ type: CLEAR_COMPOSER });
    attachmentUploader.clearAttachment();
    composerDispatch({ type: SET_MESSAGE_TYPE, payload: 'text' });
  }, [attachmentUploader]);

  const sendManualReply = useCallback(async (event) => {
    event.preventDefault();
    const trimmedText = String(composerState.text ?? '').trim();
    if (!detail?.id || (!trimmedText && !attachmentUploader.file)) return;

    uiDispatch({ type: SET_MANUAL_STATE, payload: { manualBusy: true, manualError: '' } });
    setUsageWarning('');
    uiDispatch({ type: SET_AI_SUGGESTION_STATE, payload: { aiSuggestionError: '', aiSuggestionStatus: '' } });

    try {
      let response;
      if (attachmentUploader.file) {
        const payload = new FormData();
        if (trimmedText) {
          payload.append('text', trimmedText);
        }
        payload.append('send_outbound', '1');
        payload.append('file', attachmentUploader.file);
        const uploadConfig = attachmentUploader.startUpload();
        response = await api.post(`/minha-conta/conversas/${detail.id}/responder-manual`, payload, uploadConfig);
      } else {
        response = await api.post(`/minha-conta/conversas/${detail.id}/responder-manual`, {
          text: trimmedText,
          send_outbound: true,
        });
      }

      const message = response.data?.message;
      shouldScrollChatToBottomRef.current = true;
      wasChatNearBottomRef.current = true;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        messages: appendUniqueMessage(prev?.messages ?? [], message),
      }));
      upsertConversationInList(response.data?.conversation);

      if (response.data?.usage_warning && response.data?.usage_message) {
        setUsageWarning(response.data.usage_message);
      }

      clearComposer();
      await refreshConversations();
    } catch (err) {
      uiDispatch({
        type: SET_MANUAL_STATE,
        payload: { manualError: err.response?.data?.message || 'Falha ao enviar resposta manual.' },
      });
    } finally {
      attachmentUploader.finishUpload();
      uiDispatch({ type: SET_MANUAL_STATE, payload: { manualBusy: false } });
    }
  }, [
    attachmentUploader,
    clearComposer,
    composerState.text,
    detail?.id,
    refreshConversations,
    setDetail,
    setUsageWarning,
    shouldScrollChatToBottomRef,
    upsertConversationInList,
    wasChatNearBottomRef,
  ]);

  const requestAiSuggestion = useCallback(async () => {
    if (!detail?.id) return;

    uiDispatch({
      type: SET_AI_SUGGESTION_STATE,
      payload: {
        aiSuggestionBusy: true,
        aiSuggestionError: '',
        aiSuggestionStatus: '',
        aiConfidenceScore: null,
        aiSuggestionId: null,
        aiSuggestionFeedbackState: null,
      },
    });
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/ia/sugestao`);
      const suggestion = String(response.data?.suggestion ?? '').trim();
      if (!suggestion) {
        throw new Error('empty_suggestion');
      }

      setManualText(suggestion);
      uiDispatch({
        type: SET_AI_SUGGESTION_STATE,
        payload: {
          aiConfidenceScore:
            typeof response.data?.confidence_score === 'number' ? response.data.confidence_score : null,
          aiSuggestionId: response.data?.suggestion_id ?? null,
          aiSuggestionFeedbackState: 'pending',
          aiSuggestionStatus: 'Sugestão aplicada no campo de resposta.',
        },
      });
    } catch (err) {
      uiDispatch({
        type: SET_AI_SUGGESTION_STATE,
        payload: {
          aiSuggestionError: err.response?.data?.message || 'Não foi possível gerar sugestão de IA agora.',
        },
      });
    } finally {
      uiDispatch({ type: SET_AI_SUGGESTION_STATE, payload: { aiSuggestionBusy: false } });
    }
  }, [detail?.id, setManualText]);

  const submitAiSuggestionFeedback = useCallback(async (helpful) => {
    const suggestionId = uiState.aiSuggestionId;
    if (!suggestionId) {
      uiDispatch({ type: SET_AI_SUGGESTION_STATE, payload: { aiSuggestionFeedbackState: 'submitted' } });
      return;
    }

    uiDispatch({ type: SET_AI_SUGGESTION_STATE, payload: { aiSuggestionFeedbackState: 'submitted' } });
    try {
      await api.post(`/minha-conta/ia/sugestoes/${suggestionId}/feedback`, { helpful });
    } catch (_err) {
    }
  }, [uiState.aiSuggestionId]);

  const handleManualImageChange = useCallback((event) => {
    const file = attachmentUploader.handleAttachmentChange(event);
    if (!file) {
      return;
    }
    composerDispatch({ type: SET_MESSAGE_TYPE, payload: resolveComposerType(file) });
    uiDispatch({ type: SET_MANUAL_STATE, payload: { manualError: '' } });
  }, [attachmentUploader]);

  const removeManualImage = useCallback(() => {
    attachmentUploader.clearAttachment();
    composerDispatch({ type: SET_MESSAGE_TYPE, payload: composerState.text.trim() ? 'text' : 'text' });
  }, [attachmentUploader, composerState.text]);

  const getMessageImageUrl = useCallback((msg) => {
    if (!msg?.id) return '';
    return `/api/minha-conta/mensagens/${msg.id}/media`;
  }, []);

  const handleApplyQuickReply = useCallback((text) => {
    setManualText(text);
  }, [setManualText]);

  return {
    manualText: composerState.text,
    setManualText,
    manualImageFile: attachmentUploader.file,
    manualImagePreviewUrl: attachmentUploader.previewUrl,
    manualBusy: uiState.manualBusy,
    manualError: uiState.manualError,
    aiSuggestionBusy: uiState.aiSuggestionBusy,
    aiSuggestionError: uiState.aiSuggestionError,
    aiSuggestionStatus: uiState.aiSuggestionStatus,
    aiConfidenceScore: uiState.aiConfidenceScore,
    aiSuggestionFeedbackState: uiState.aiSuggestionFeedbackState,
    submitAiSuggestionFeedback,
    quickReplies,
    requestAiSuggestion,
    handleManualImageChange,
    removeManualImage,
    sendManualReply,
    handleApplyQuickReply,
    getMessageImageUrl,
    usageWarning: uiState.usageWarning,
    setUsageWarning,
    resetAiSuggestionState,
    clearComposer,
    cancelAttachmentUpload: attachmentUploader.cancelUpload,
    attachmentUploadProgress: attachmentUploader.progress,
    attachmentUploading: attachmentUploader.uploading,
  };
}
