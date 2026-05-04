import { useCallback, useState } from 'react';
import { CONVERSATION_ASSIGNED_TYPE } from '@/constants/conversation';
import api from '@/services/api';
import { appendUniqueMessage } from '../inboxRealtimeUtils';
import useConversationSearch from './useConversationSearch';
import useMessageComposer from './useMessageComposer';

export default function useCompanyInboxActions({
  contactNameInput,
  detail,
  onConversationDeleted,
  refreshConversations,
  setContactNameInput,
  setDetail,
  setDetailError,
  shouldScrollChatToBottomRef,
  transferOptions,
  upsertConversationInList,
  wasChatNearBottomRef,
}) {
  const [contactBusy, setContactBusy] = useState(false);
  const [contactError, setContactError] = useState('');
  const [contactSuccess, setContactSuccess] = useState('');
  const [actionBusy, setActionBusy] = useState(false);
  const [tagsModalOpen, setTagsModalOpen] = useState(false);
  const [transferModalOpen, setTransferModalOpen] = useState(false);
  const [transferBusy, setTransferBusy] = useState(false);
  const [transferError, setTransferError] = useState('');
  const [transferSuccess, setTransferSuccess] = useState('');
  const [newConvModalOpen, setNewConvModalOpen] = useState(false);
  const [newConvBusy, setNewConvBusy] = useState(false);
  const [newConvError, setNewConvError] = useState('');
  const [sendTemplateModalOpen, setSendTemplateModalOpen] = useState(false);
  const [sendTemplateBusy, setSendTemplateBusy] = useState(false);
  const [sendTemplateError, setSendTemplateError] = useState('');
  const [sendTemplateSuccess, setSendTemplateSuccess] = useState('');
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [deleteError, setDeleteError] = useState('');

  const messageComposer = useMessageComposer({
    detail,
    refreshConversations,
    setDetail,
    shouldScrollChatToBottomRef,
    upsertConversationInList,
    wasChatNearBottomRef,
  });

  const conversationSearch = useConversationSearch({
    detail,
    transferOptionsUsers: transferOptions?.users ?? [],
    onTransferStateReset: () => {
      setTransferSuccess('');
      setTransferError('');
    },
  });

  const resetForOpenConversation = useCallback(() => {
    setTransferModalOpen(false);
    conversationSearch.resetSearchFilters();
    setTransferError('');
    setTransferSuccess('');
    conversationSearch.setShowTemplates(false);
    setContactError('');
    setContactSuccess('');
    messageComposer.resetAiSuggestionState();
    setSendTemplateModalOpen(false);
    setSendTemplateError('');
    setSendTemplateSuccess('');
  }, [conversationSearch, messageComposer]);

  const assumeConversation = useCallback(async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    setDetailError('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/assumir`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      upsertConversationInList(response.data?.conversation);
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao assumir conversa.');
    } finally {
      setActionBusy(false);
    }
  }, [detail?.id, refreshConversations, setDetail, setDetailError, upsertConversationInList]);

  const releaseConversation = useCallback(async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    setDetailError('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/soltar`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      upsertConversationInList(response.data?.conversation);
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao soltar conversa.');
    } finally {
      setActionBusy(false);
    }
  }, [detail?.id, refreshConversations, setDetail, setDetailError, upsertConversationInList]);

  const closeConversation = useCallback(async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    setDetailError('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/encerrar`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      upsertConversationInList(response.data?.conversation);
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao encerrar conversa.');
    } finally {
      setActionBusy(false);
    }
  }, [detail?.id, refreshConversations, setDetail, setDetailError, upsertConversationInList]);

  const deleteConversation = useCallback(async () => {
    if (!detail?.id) return;
    setDeleteBusy(true);
    setDeleteError('');
    try {
      await api.delete(`/minha-conta/conversas/${detail.id}`);
      onConversationDeleted?.(detail.id);
    } catch (err) {
      setDeleteError(err.response?.data?.message || 'Falha ao apagar conversa.');
    } finally {
      setDeleteBusy(false);
    }
  }, [detail?.id, onConversationDeleted]);

  const transferConversation = useCallback(async () => {
    if (!detail?.id) return;
    if (!conversationSearch.transferArea && !conversationSearch.transferUserId) {
      setTransferError('Selecione uma area ou um usuário destino.');
      setTransferSuccess('');
      return;
    }

    setTransferBusy(true);
    setTransferError('');
    setTransferSuccess('');
    try {
      const payload = conversationSearch.transferUserId
        ? {
            type: CONVERSATION_ASSIGNED_TYPE.USER,
            id: Number(conversationSearch.transferUserId),
            send_outbound: true,
          }
        : {
            type: CONVERSATION_ASSIGNED_TYPE.AREA,
            id: Number(conversationSearch.transferArea),
            send_outbound: true,
          };

      const response = await api.post(`/minha-conta/conversas/${detail.id}/transferir`, {
        ...payload,
      });

      const autoMessage = response.data?.message ?? null;
      const transferHistory = response.data?.transfer_history ?? [];
      shouldScrollChatToBottomRef.current = true;
      wasChatNearBottomRef.current = true;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        transfer_history: transferHistory.length ? transferHistory : prev?.transfer_history ?? [],
        messages: autoMessage ? appendUniqueMessage(prev?.messages ?? [], autoMessage) : prev?.messages ?? [],
      }));
      upsertConversationInList(response.data?.conversation);

      setTransferSuccess('Transferencia realizada com sucesso.');
      await refreshConversations();
    } catch (err) {
      setTransferError(err.response?.data?.message || 'Falha ao transferir conversa.');
    } finally {
      setTransferBusy(false);
    }
  }, [
    conversationSearch.transferArea,
    conversationSearch.transferUserId,
    detail?.id,
    refreshConversations,
    setDetail,
    shouldScrollChatToBottomRef,
    upsertConversationInList,
    wasChatNearBottomRef,
  ]);

  const attachTag = useCallback(
    async (tagId) => {
      if (!detail?.id || !tagId) return;
      try {
        const res = await api.post(`/minha-conta/conversas/${detail.id}/tags`, { tag_id: tagId });
        setDetail((prev) => ({ ...(prev ?? {}), tags: res.data.tags ?? prev?.tags ?? [] }));
      } catch (_err) {
        setDetailError('Falha ao adicionar tag.');
      }
    },
    [detail, setDetail, setDetailError]
  );

  const detachTag = useCallback(
    async (tagId) => {
      if (!detail?.id || !tagId) return;
      try {
        const res = await api.delete(`/minha-conta/conversas/${detail.id}/tags/${tagId}`);
        setDetail((prev) => ({ ...(prev ?? {}), tags: res.data.tags ?? prev?.tags ?? [] }));
      } catch (_err) {
        setDetailError('Falha ao remover tag.');
      }
    },
    [detail, setDetail, setDetailError]
  );

  const saveContactName = useCallback(async () => {
    if (!detail?.id) return;
    setContactBusy(true);
    setContactError('');
    setContactSuccess('');
    try {
      const payloadName = String(contactNameInput ?? '').trim();
      const response = await api.put(`/minha-conta/conversas/${detail.id}/contato`, {
        customer_name: payloadName || null,
      });

      const updatedConversation = response.data?.conversation ?? null;
      if (updatedConversation) {
        setDetail((prev) => ({ ...(prev ?? {}), ...updatedConversation }));
        setContactNameInput(updatedConversation.customer_name ?? '');
        upsertConversationInList(updatedConversation);
      }

      setContactSuccess('Contato salvo.');
    } catch (err) {
      setContactError(err.response?.data?.message || 'Falha ao salvar contato.');
    } finally {
      setContactBusy(false);
    }
  }, [
    contactNameInput,
    detail?.id,
    setContactNameInput,
    setDetail,
    upsertConversationInList,
  ]);

  const handleContactNameInputChange = useCallback(
    (value) => {
      setContactNameInput(value);
      setContactSuccess('');
      setContactError('');
    },
    [setContactNameInput]
  );

  const handleApplyQuickReply = useCallback((text) => {
    messageComposer.handleApplyQuickReply(text);
    conversationSearch.setShowTemplates(false);
  }, [conversationSearch, messageComposer]);

  const createConversation = useCallback(
    async ({ phone, name, sendTemplate, templateName, templateVariables = [] }) => {
      setNewConvBusy(true);
      setNewConvError('');
      try {
        const response = await api.post('/minha-conta/conversas', {
          customer_phone: phone,
          customer_name: name || null,
          send_template: sendTemplate,
          template_name: templateName || 'iniciar_conversa',
          template_variables: templateVariables,
        });

        const conversation = response.data?.conversation;
        if (conversation) {
          upsertConversationInList(conversation);
          await refreshConversations();
          setNewConvModalOpen(false);
        }
        return conversation ?? null;
      } catch (err) {
        setNewConvError(err.response?.data?.message || 'Falha ao criar conversa.');
        return null;
      } finally {
        setNewConvBusy(false);
      }
    },
    [refreshConversations, upsertConversationInList]
  );

  const sendTemplateToConversation = useCallback(async (templateName, templateVariables = []) => {
    if (!detail?.id) return;

    setSendTemplateBusy(true);
    setSendTemplateError('');
    setSendTemplateSuccess('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/enviar-template`, {
        template_name: templateName || 'iniciar_conversa',
        template_variables: templateVariables,
      });

      const message = response.data?.message;
      shouldScrollChatToBottomRef.current = true;
      wasChatNearBottomRef.current = true;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        messages: message
          ? appendUniqueMessage(prev?.messages ?? [], message)
          : prev?.messages ?? [],
      }));
      upsertConversationInList(response.data?.conversation);
      setSendTemplateSuccess('Template enviado com sucesso.');
      if (response.data?.usage_warning && response.data?.usage_message) {
        messageComposer.setUsageWarning(response.data.usage_message);
      }
      await refreshConversations();
    } catch (err) {
      setSendTemplateError(err.response?.data?.message || 'Falha ao enviar template.');
    } finally {
      setSendTemplateBusy(false);
    }
  }, [
    detail?.id,
    messageComposer,
    refreshConversations,
    setDetail,
    shouldScrollChatToBottomRef,
    upsertConversationInList,
    wasChatNearBottomRef,
  ]);

  return {
    actionBusy,
    attachTag,
    deleteConversation,
    deleteBusy,
    deleteError,
    detachTag,
    aiSuggestionBusy: messageComposer.aiSuggestionBusy,
    aiSuggestionError: messageComposer.aiSuggestionError,
    aiSuggestionStatus: messageComposer.aiSuggestionStatus,
    aiConfidenceScore: messageComposer.aiConfidenceScore,
    aiSuggestionFeedbackState: messageComposer.aiSuggestionFeedbackState,
    submitAiSuggestionFeedback: messageComposer.submitAiSuggestionFeedback,
    assumeConversation,
    availableUsers: conversationSearch.availableUsers,
    closeConversation,
    contactBusy,
    contactError,
    contactSuccess,
    getMessageImageUrl: messageComposer.getMessageImageUrl,
    handleApplyQuickReply,
    handleContactNameInputChange,
    handleManualImageChange: messageComposer.handleManualImageChange,
    handleTransferAreaChange: conversationSearch.handleTransferAreaChange,
    handleTransferUserChange: conversationSearch.handleTransferUserChange,
    manualBusy: messageComposer.manualBusy,
    manualError: messageComposer.manualError,
    manualImageFile: messageComposer.manualImageFile,
    manualImagePreviewUrl: messageComposer.manualImagePreviewUrl,
    manualText: messageComposer.manualText,
    quickReplies: messageComposer.quickReplies,
    requestAiSuggestion: messageComposer.requestAiSuggestion,
    releaseConversation,
    removeManualImage: messageComposer.removeManualImage,
    resetForOpenConversation,
    saveContactName,
    sendManualReply: messageComposer.sendManualReply,
    setManualText: messageComposer.setManualText,
    setShowTemplates: conversationSearch.setShowTemplates,
    setTagsModalOpen,
    setTransferModalOpen,
    showTemplates: conversationSearch.showTemplates,
    tagsModalOpen,
    transferArea: conversationSearch.transferArea,
    transferBusy,
    transferError,
    transferModalOpen,
    transferSuccess,
    transferUserId: conversationSearch.transferUserId,
    transferConversation,
    createConversation,
    newConvModalOpen,
    newConvBusy,
    newConvError,
    setNewConvModalOpen,
    sendTemplateToConversation,
    sendTemplateModalOpen,
    sendTemplateBusy,
    sendTemplateError,
    sendTemplateSuccess,
    setSendTemplateModalOpen,
    usageWarning: messageComposer.usageWarning,
    setUsageWarning: messageComposer.setUsageWarning,
  };
}
