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
  const [contactBusy, setContactBusy] = useState(false);
  const [contactError, setContactError] = useState('');
  const [contactSuccess, setContactSuccess] = useState('');
  const [actionBusy, setActionBusy] = useState(false);
  const [tagInput, setTagInput] = useState('');
  const [showTemplates, setShowTemplates] = useState(false);
  const [tagsModalOpen, setTagsModalOpen] = useState(false);
  const [transferExpanded, setTransferExpanded] = useState(false);
  const [quickReplies, setQuickReplies] = useState([]);
  const [transferArea, setTransferArea] = useState('');
  const [transferUserId, setTransferUserId] = useState('');
  const [transferBusy, setTransferBusy] = useState(false);
  const [transferError, setTransferError] = useState('');
  const [transferSuccess, setTransferSuccess] = useState('');

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
    setTransferArea('');
    setTransferUserId('');
    setTransferError('');
    setTransferSuccess('');
    setShowTemplates(false);
    setContactError('');
    setContactSuccess('');
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
      try {
        let response;
        if (manualImageFile) {
          const payload = new FormData();
          if (trimmedText) {
            payload.append('text', trimmedText);
          }
          payload.append('send_outbound', '1');
          payload.append('image', manualImageFile);
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

  return {
    actionBusy,
    addTag,
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
    setTransferExpanded,
    showTemplates,
    tagInput,
    tagsModalOpen,
    transferArea,
    transferBusy,
    transferError,
    transferExpanded,
    transferSuccess,
    transferUserId,
    transferConversation,
  };
}
