import { useCallback, useEffect, useMemo, useState } from 'react';
import { CONVERSATION_ASSIGNED_TYPE } from '@/constants/conversation';
import api from '@/services/api';
import { appendUniqueMessage } from '../inboxRealtimeUtils';

export default function useCompanyInboxActions({
  contactNameInput,
  detail,
  refreshConversations,
  setContactNameInput,
  setDetail,
  setDetailError,
  shouldScrollChatToBottomRef,
  transferOptions,
  upsertConversationInList,
  wasChatNearBottomRef,
}) {
  const [manualText, setManualText] = useState('');
  const [manualImageFile, setManualImageFile] = useState(null);
  const [manualImagePreviewUrl, setManualImagePreviewUrl] = useState('');
  const [manualBusy, setManualBusy] = useState(false);
  const [manualError, setManualError] = useState('');
  const [aiSuggestionBusy, setAiSuggestionBusy] = useState(false);
  const [aiSuggestionError, setAiSuggestionError] = useState('');
  const [aiSuggestionStatus, setAiSuggestionStatus] = useState('');
  const [contactBusy, setContactBusy] = useState(false);
  const [contactError, setContactError] = useState('');
  const [contactSuccess, setContactSuccess] = useState('');
  const [actionBusy, setActionBusy] = useState(false);
  const [tagInput, setTagInput] = useState('');
  const [showTemplates, setShowTemplates] = useState(false);
  const [tagsModalOpen, setTagsModalOpen] = useState(false);
  const [transferModalOpen, setTransferModalOpen] = useState(false);
  const [quickReplies, setQuickReplies] = useState([]);
  const [transferArea, setTransferArea] = useState('');
  const [transferUserId, setTransferUserId] = useState('');
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

  useEffect(() => {
    return () => {
      if (manualImagePreviewUrl) {
        URL.revokeObjectURL(manualImagePreviewUrl);
      }
    };
  }, [manualImagePreviewUrl]);

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

  useEffect(() => {
    if (!detail?.id) {
      return;
    }

    setTransferArea(
      detail.assigned_type === CONVERSATION_ASSIGNED_TYPE.AREA ? String(detail.assigned_id ?? '') : ''
    );
  }, [detail?.assigned_id, detail?.assigned_type, detail?.id]);

  const availableUsers = useMemo(() => {
    const users = transferOptions.users ?? [];
    if (!transferArea) {
      return users;
    }

    return users.filter((user) =>
      (user.areas ?? []).some((area) => String(area.id) === String(transferArea))
    );
  }, [transferOptions, transferArea]);

  useEffect(() => {
    if (!transferUserId) return;
    const exists = availableUsers.some((user) => String(user.id) === String(transferUserId));
    if (!exists) {
      setTransferUserId('');
    }
  }, [availableUsers, transferUserId]);

  const resetForOpenConversation = useCallback(() => {
    setTransferModalOpen(false);
    setTransferArea('');
    setTransferUserId('');
    setTransferError('');
    setTransferSuccess('');
    setShowTemplates(false);
    setContactError('');
    setContactSuccess('');
    setAiSuggestionBusy(false);
    setAiSuggestionError('');
    setAiSuggestionStatus('');
    setSendTemplateModalOpen(false);
    setSendTemplateError('');
    setSendTemplateSuccess('');
  }, []);

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

  const transferConversation = useCallback(async () => {
    if (!detail?.id) return;
    if (!transferArea && !transferUserId) {
      setTransferError('Selecione uma area ou um usuario destino.');
      setTransferSuccess('');
      return;
    }

    setTransferBusy(true);
    setTransferError('');
    setTransferSuccess('');
    try {
      const payload = transferUserId
        ? { type: CONVERSATION_ASSIGNED_TYPE.USER, id: Number(transferUserId), send_outbound: true }
        : { type: CONVERSATION_ASSIGNED_TYPE.AREA, id: Number(transferArea), send_outbound: true };

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
    detail?.id,
    refreshConversations,
    setDetail,
    shouldScrollChatToBottomRef,
    transferArea,
    transferUserId,
    upsertConversationInList,
    wasChatNearBottomRef,
  ]);

  const addTag = useCallback(
    async (tag) => {
      if (!detail?.id || !tag.trim()) return;
      const currentTags = detail.tags ?? [];
      const normalizedTag = tag.toLowerCase().trim();
      if (currentTags.includes(normalizedTag)) return;

      const newTags = [...currentTags, normalizedTag];
      try {
        await api.put(`/minha-conta/conversas/${detail.id}/tags`, { tags: newTags });
        setDetail((prev) => ({ ...(prev ?? {}), tags: newTags }));
        setTagInput('');
      } catch (_err) {
        setDetailError('Falha ao adicionar tag.');
      }
    },
    [detail, setDetail, setDetailError]
  );

  const removeTag = useCallback(
    async (tag) => {
      if (!detail?.id) return;
      const newTags = (detail.tags ?? []).filter((item) => item !== tag);
      try {
        await api.put(`/minha-conta/conversas/${detail.id}/tags`, { tags: newTags });
        setDetail((prev) => ({ ...(prev ?? {}), tags: newTags }));
      } catch (_err) {
        setDetailError('Falha ao remover tag.');
      }
    },
    [detail, setDetail, setDetailError]
  );

  const sendManualReply = useCallback(
    async (event) => {
      event.preventDefault();
      const trimmedText = manualText.trim();
      if (!detail?.id || (!trimmedText && !manualImageFile)) return;

      setManualBusy(true);
      setManualError('');
      setAiSuggestionError('');
      setAiSuggestionStatus('');
      try {
        let response;
        if (manualImageFile) {
          const payload = new FormData();
          if (trimmedText) {
            payload.append('text', trimmedText);
          }
          payload.append('send_outbound', '1');
          if (manualImageFile) payload.append('file', manualImageFile);
          response = await api.post(`/minha-conta/conversas/${detail.id}/responder-manual`, payload);
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
        setManualText('');
        if (manualImagePreviewUrl) {
          URL.revokeObjectURL(manualImagePreviewUrl);
        }
        setManualImageFile(null);
        setManualImagePreviewUrl('');
        await refreshConversations();
      } catch (err) {
        setManualError(err.response?.data?.message || 'Falha ao enviar resposta manual.');
      } finally {
        setManualBusy(false);
      }
    },
    [
      detail?.id,
      manualImageFile,
      manualImagePreviewUrl,
      manualText,
      refreshConversations,
      setDetail,
      shouldScrollChatToBottomRef,
      upsertConversationInList,
      wasChatNearBottomRef,
    ]
  );

  const requestAiSuggestion = useCallback(async () => {
    if (!detail?.id) return;

    setAiSuggestionBusy(true);
    setAiSuggestionError('');
    setAiSuggestionStatus('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/ia/sugestao`);
      const suggestion = String(response.data?.suggestion ?? '').trim();
      if (!suggestion) {
        throw new Error('empty_suggestion');
      }

      setManualText(suggestion);
      setAiSuggestionStatus('Sugestao aplicada no campo de resposta.');
    } catch (err) {
      setAiSuggestionError(
        err.response?.data?.message || 'Nao foi possivel gerar sugestao de IA agora.'
      );
    } finally {
      setAiSuggestionBusy(false);
    }
  }, [detail?.id]);

  const handleManualImageChange = useCallback(
    (event) => {
      const file = event.target.files?.[0];
      if (!file) {
        return;
      }

      if (manualImagePreviewUrl) {
        URL.revokeObjectURL(manualImagePreviewUrl);
      }

      setManualImageFile(file);
      setManualImagePreviewUrl(URL.createObjectURL(file));
      setManualError('');
    },
    [manualImagePreviewUrl]
  );

  const removeManualImage = useCallback(() => {
    if (manualImagePreviewUrl) {
      URL.revokeObjectURL(manualImagePreviewUrl);
    }
    setManualImageFile(null);
    setManualImagePreviewUrl('');
  }, [manualImagePreviewUrl]);

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

  const handleTransferAreaChange = useCallback((value) => {
    setTransferArea(value);
    setTransferSuccess('');
    setTransferError('');
  }, []);

  const handleTransferUserChange = useCallback(
    (value) => {
      setTransferUserId(value);
      setTransferSuccess('');
      setTransferError('');

      if (value) {
        const selectedUser = (transferOptions.users ?? []).find(
          (user) => String(user.id) === String(value)
        );
        if (selectedUser?.areas?.length && !transferArea) {
          setTransferArea(String(selectedUser.areas[0].id));
        }
      }
    },
    [transferArea, transferOptions.users]
  );

  const handleApplyQuickReply = useCallback((text) => {
    setManualText(text);
    setShowTemplates(false);
  }, []);

  const getMessageImageUrl = useCallback((msg) => {
    if (!msg?.id) return '';
    return `/api/minha-conta/mensagens/${msg.id}/media`;
  }, []);

  const createConversation = useCallback(
    async ({ phone, name, sendTemplate, templateName }) => {
      setNewConvBusy(true);
      setNewConvError('');
      try {
        const response = await api.post('/minha-conta/conversas', {
          customer_phone: phone,
          customer_name: name || null,
          send_template: sendTemplate,
          template_name: templateName || 'iniciar_conversa',
        });

        const conversation = response.data?.conversation;
        if (conversation) {
          upsertConversationInList(conversation);
          await refreshConversations();
          setNewConvModalOpen(false);
          // Abre a conversa recém-criada
          if (conversation.id) {
            shouldScrollChatToBottomRef.current = true;
            setDetail(conversation);
          }
        }
      } catch (err) {
        setNewConvError(err.response?.data?.message || 'Falha ao criar conversa.');
      } finally {
        setNewConvBusy(false);
      }
    },
    [refreshConversations, setDetail, shouldScrollChatToBottomRef, upsertConversationInList]
  );

  const sendTemplateToConversation = useCallback(async (templateName) => {
    if (!detail?.id) return;

    setSendTemplateBusy(true);
    setSendTemplateError('');
    setSendTemplateSuccess('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/enviar-template`, {
        template_name: templateName || 'iniciar_conversa',
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
      await refreshConversations();
    } catch (err) {
      setSendTemplateError(err.response?.data?.message || 'Falha ao enviar template.');
    } finally {
      setSendTemplateBusy(false);
    }
  }, [
    detail?.id,
    refreshConversations,
    setDetail,
    shouldScrollChatToBottomRef,
    upsertConversationInList,
    wasChatNearBottomRef,
  ]);

  return {
    actionBusy,
    addTag,
    aiSuggestionBusy,
    aiSuggestionError,
    aiSuggestionStatus,
    assumeConversation,
    availableUsers,
    closeConversation,
    contactBusy,
    contactError,
    contactSuccess,
    getMessageImageUrl,
    handleApplyQuickReply,
    handleContactNameInputChange,
    handleManualImageChange,
    handleTransferAreaChange,
    handleTransferUserChange,
    manualBusy,
    manualError,
    manualImageFile,
    manualImagePreviewUrl,
    manualText,
    quickReplies,
    requestAiSuggestion,
    releaseConversation,
    removeManualImage,
    removeTag,
    resetForOpenConversation,
    saveContactName,
    sendManualReply,
    setManualText,
    setShowTemplates,
    setTagInput,
    setTagsModalOpen,
    setTransferModalOpen,
    showTemplates,
    tagInput,
    tagsModalOpen,
    transferArea,
    transferBusy,
    transferError,
    transferModalOpen,
    transferSuccess,
    transferUserId,
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
  };
}
