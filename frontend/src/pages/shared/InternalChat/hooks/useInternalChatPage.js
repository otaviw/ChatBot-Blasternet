import { useCallback, useMemo, useState } from 'react';
import {
  addInternalChatGroupParticipant,
  createInternalDirectConversation,
  createInternalGroupConversation,
  deleteInternalChatConversation,
  deleteInternalChatGroup,
  leaveInternalChatGroup,
  listInternalChatRecipients,
  removeInternalChatGroupParticipant,
  updateInternalChatGroupName,
  updateInternalChatGroupParticipantAdmin,
  upsertConversationInList,
} from '@/services/internalChatService';
import { parseErrorMessage } from '../internalChatUtils';

export default function useInternalChatPage({
  authenticated,
  closeConversation,
  conversations,
  currentUserId,
  detail,
  loadConversations,
  openConversation,
  resetMessageInteractionState,
  role,
  scheduleConversationsRefresh,
  selectedConversationIdRef,
  setConversations,
  setDetail,
}) {
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [createType, setCreateType] = useState('direct');
  const [recipients, setRecipients] = useState([]);
  const [recipientsLoading, setRecipientsLoading] = useState(false);
  const [recipientsError, setRecipientsError] = useState('');
  const [recipientSearch, setRecipientSearch] = useState('');
  const [selectedRecipientId, setSelectedRecipientId] = useState('');
  const [selectedGroupIds, setSelectedGroupIds] = useState([]);
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');

  const [conversationOptionsTargetId, setConversationOptionsTargetId] = useState(null);
  const [conversationOptionsBusy, setConversationOptionsBusy] = useState(false);
  const [conversationOptionsError, setConversationOptionsError] = useState('');
  const [groupNameDraft, setGroupNameDraft] = useState('');
  const [participantsModalOpen, setParticipantsModalOpen] = useState(false);
  const [participantsModalSearch, setParticipantsModalSearch] = useState('');
  const [participantsModalBusy, setParticipantsModalBusy] = useState(false);
  const [participantsModalError, setParticipantsModalError] = useState('');
  const [leaveTransferAdminTo, setLeaveTransferAdminTo] = useState('');

  const ensureRecipientsLoaded = useCallback(async () => {
    if (recipients.length > 0) {
      return recipients;
    }

    setRecipientsLoading(true);
    setRecipientsError('');

    try {
      const response = await listInternalChatRecipients({
        role,
        excludeUserId: currentUserId,
      });

      const loadedRecipients = response.users ?? [];
      setRecipients(loadedRecipients);
      return loadedRecipients;
    } catch (requestError) {
      setRecipientsError(
        parseErrorMessage(
          requestError,
          'Nao foi possivel carregar a lista de usuarios para iniciar conversa.'
        )
      );
      return [];
    } finally {
      setRecipientsLoading(false);
    }
  }, [currentUserId, recipients, role]);

  const openCreateModal = useCallback(
    async (type = 'direct') => {
      if (!authenticated) {
        return;
      }

      setCreateModalOpen(true);
      setCreateType(type);
      setCreateError('');
      setRecipientsError('');
      setRecipientSearch('');
      setSelectedRecipientId('');
      setSelectedGroupIds([]);

      await ensureRecipientsLoaded();
    },
    [authenticated, ensureRecipientsLoaded]
  );

  const closeCreateModal = useCallback(() => {
    setCreateModalOpen(false);
  }, []);

  const selectRecipient = useCallback((recipientId) => {
    setSelectedRecipientId(String(recipientId));
    setCreateError('');
  }, []);

  const handleToggleGroupParticipant = useCallback((participantId) => {
    const id = Number(participantId);
    setSelectedGroupIds((previous) =>
      previous.includes(id)
        ? previous.filter((existing) => existing !== id)
        : [...previous, id]
    );
    setCreateError('');
  }, []);

  const handleCreateDirectConversation = useCallback(async () => {
    const recipientId = Number.parseInt(String(selectedRecipientId ?? ''), 10);
    if (!recipientId) {
      setCreateError('Selecione um usuario para iniciar a conversa.');
      return;
    }

    setCreateBusy(true);
    setCreateError('');

    try {
      const response = await createInternalDirectConversation({
        role,
        recipientId,
      });

      if (!response.conversation?.id) {
        throw new Error('Resposta da API nao retornou a conversa criada.');
      }

      setConversations((previous) =>
        upsertConversationInList(previous, response.conversation)
      );
      setCreateModalOpen(false);
      await openConversation(response.conversation.id);

      scheduleConversationsRefresh(() => {
        void loadConversations({ silent: true });
      });
    } catch (requestError) {
      setCreateError(
        parseErrorMessage(
          requestError,
          'Nao foi possivel iniciar a nova conversa interna.'
        )
      );
    } finally {
      setCreateBusy(false);
    }
  }, [
    loadConversations,
    openConversation,
    role,
    scheduleConversationsRefresh,
    selectedRecipientId,
    setConversations,
  ]);

  const handleCreateGroupConversation = useCallback(async () => {
    if (selectedGroupIds.length < 2) {
      setCreateError('Selecione pelo menos 2 participantes para criar o grupo.');
      return;
    }

    setCreateBusy(true);
    setCreateError('');

    try {
      const response = await createInternalGroupConversation({
        role,
        participantIds: selectedGroupIds,
      });

      if (!response.conversation?.id) {
        throw new Error('Resposta da API nao retornou a conversa criada.');
      }

      setConversations((previous) =>
        upsertConversationInList(previous, response.conversation)
      );
      setCreateModalOpen(false);
      await openConversation(response.conversation.id);

      scheduleConversationsRefresh(() => {
        void loadConversations({ silent: true });
      });
    } catch (requestError) {
      setCreateError(
        parseErrorMessage(
          requestError,
          'Nao foi possivel criar o grupo.'
        )
      );
    } finally {
      setCreateBusy(false);
    }
  }, [
    loadConversations,
    openConversation,
    role,
    scheduleConversationsRefresh,
    selectedGroupIds,
    setConversations,
  ]);

  const filteredRecipients = useMemo(() => {
    const search = recipientSearch.trim().toLowerCase();
    if (!search) {
      return recipients;
    }

    return recipients.filter((recipient) => {
      const name = String(recipient.name ?? '').toLowerCase();
      const email = String(recipient.email ?? '').toLowerCase();
      return name.includes(search) || email.includes(search);
    });
  }, [recipientSearch, recipients]);

  const optionsConversation = useMemo(() => {
    const targetId = Number.parseInt(String(conversationOptionsTargetId ?? ''), 10);
    if (!targetId) {
      return null;
    }

    if (detail && Number(detail.id) === targetId) {
      return detail;
    }

    return conversations.find((conversation) => Number(conversation.id) === targetId) ?? null;
  }, [conversationOptionsTargetId, conversations, detail]);

  const optionsParticipants = optionsConversation?.participants ?? [];
  const optionsIsGroup = String(optionsConversation?.type ?? '') === 'group';
  const optionsCurrentUserParticipant = optionsParticipants.find(
    (participant) => Number(participant.id) === Number(currentUserId)
  );
  const optionsCurrentUserIsAdmin = Boolean(
    optionsConversation?.current_user_is_admin ?? optionsCurrentUserParticipant?.is_admin
  );
  const transferAdminCandidates = optionsParticipants.filter(
    (participant) => Number(participant.id) !== Number(currentUserId)
  );

  const filteredAddableGroupRecipients = useMemo(() => {
    if (!optionsParticipants.length) {
      return [];
    }

    const participantIds = new Set(optionsParticipants.map((participant) => Number(participant.id)));
    const search = participantsModalSearch.trim().toLowerCase();

    return recipients
      .filter((recipient) => !participantIds.has(Number(recipient.id)))
      .filter((recipient) => {
        if (!search) {
          return true;
        }

        const name = String(recipient.name ?? '').toLowerCase();
        const email = String(recipient.email ?? '').toLowerCase();
        return name.includes(search) || email.includes(search);
      });
  }, [optionsParticipants, participantsModalSearch, recipients]);

  const applyConversationSummaryUpdate = useCallback(
    (updatedConversation) => {
      if (!updatedConversation?.id) {
        return;
      }

      setConversations((previous) => upsertConversationInList(previous, updatedConversation));
      setDetail((previous) => {
        if (!previous || Number(previous.id) !== Number(updatedConversation.id)) {
          return previous;
        }

        return {
          ...previous,
          ...updatedConversation,
          participants: updatedConversation.participants?.length
            ? updatedConversation.participants
            : previous.participants,
          messages: previous.messages ?? [],
        };
      });
    },
    [setConversations, setDetail]
  );

  const removeConversationFromUi = useCallback(
    (conversationId) => {
      const id = Number.parseInt(String(conversationId ?? ''), 10);
      if (!id) {
        return;
      }

      setConversations((previous) =>
        previous.filter((conversation) => Number(conversation.id) !== id)
      );

      if (Number(selectedConversationIdRef.current) === id) {
        resetMessageInteractionState();
        closeConversation();
      }
    },
    [closeConversation, resetMessageInteractionState, selectedConversationIdRef, setConversations]
  );

  const closeConversationOptionsModal = useCallback(() => {
    setConversationOptionsTargetId(null);
    setConversationOptionsBusy(false);
    setConversationOptionsError('');
    setGroupNameDraft('');
    setParticipantsModalOpen(false);
    setParticipantsModalSearch('');
    setParticipantsModalBusy(false);
    setParticipantsModalError('');
    setLeaveTransferAdminTo('');
  }, []);

  const handleOpenConversationOptions = useCallback((conversation) => {
    const targetId = Number.parseInt(String(conversation?.id ?? ''), 10);
    if (!targetId) {
      return;
    }

    setConversationOptionsTargetId(targetId);
    setConversationOptionsError('');
    setConversationOptionsBusy(false);
    setParticipantsModalOpen(false);
    setParticipantsModalSearch('');
    setParticipantsModalBusy(false);
    setParticipantsModalError('');
    setLeaveTransferAdminTo('');
    setGroupNameDraft(String(conversation?.name ?? ''));
  }, []);

  const handleDeleteDirectConversation = useCallback(async () => {
    const conversationId = Number.parseInt(String(optionsConversation?.id ?? ''), 10);
    if (!conversationId) {
      return;
    }

    setConversationOptionsBusy(true);
    setConversationOptionsError('');

    try {
      await deleteInternalChatConversation({
        role,
        conversationId,
      });

      removeConversationFromUi(conversationId);
      closeConversationOptionsModal();
    } catch (requestError) {
      setConversationOptionsError(
        parseErrorMessage(requestError, 'Nao foi possivel apagar a conversa.')
      );
    } finally {
      setConversationOptionsBusy(false);
    }
  }, [closeConversationOptionsModal, optionsConversation?.id, removeConversationFromUi, role]);

  const handleSaveGroupName = useCallback(async () => {
    const conversationId = Number.parseInt(String(optionsConversation?.id ?? ''), 10);
    if (!conversationId) {
      return;
    }

    const normalizedName = groupNameDraft.trim();
    if (!normalizedName) {
      setConversationOptionsError('Informe o novo nome do grupo.');
      return;
    }

    setConversationOptionsBusy(true);
    setConversationOptionsError('');

    try {
      const response = await updateInternalChatGroupName({
        role,
        conversationId,
        name: normalizedName,
      });

      if (response.conversation) {
        applyConversationSummaryUpdate(response.conversation);
        setGroupNameDraft(String(response.conversation.name ?? normalizedName));
      }
    } catch (requestError) {
      setConversationOptionsError(
        parseErrorMessage(requestError, 'Nao foi possivel alterar o nome do grupo.')
      );
    } finally {
      setConversationOptionsBusy(false);
    }
  }, [applyConversationSummaryUpdate, groupNameDraft, optionsConversation?.id, role]);

  const handleOpenParticipantsModal = useCallback(async () => {
    setParticipantsModalError('');
    setParticipantsModalSearch('');
    await ensureRecipientsLoaded();
    setParticipantsModalOpen(true);
  }, [ensureRecipientsLoaded]);

  const handleAddParticipantToGroup = useCallback(
    async (participantId) => {
      const conversationId = Number.parseInt(String(optionsConversation?.id ?? ''), 10);
      const userId = Number.parseInt(String(participantId ?? ''), 10);
      if (!conversationId || !userId) {
        return;
      }

      setParticipantsModalBusy(true);
      setParticipantsModalError('');

      try {
        const response = await addInternalChatGroupParticipant({
          role,
          conversationId,
          participantId: userId,
        });

        if (response.conversation) {
          applyConversationSummaryUpdate(response.conversation);
        }
      } catch (requestError) {
        setParticipantsModalError(
          parseErrorMessage(requestError, 'Nao foi possivel adicionar participante ao grupo.')
        );
      } finally {
        setParticipantsModalBusy(false);
      }
    },
    [applyConversationSummaryUpdate, optionsConversation?.id, role]
  );

  const handleRemoveParticipantFromGroup = useCallback(
    async (participantId) => {
      const conversationId = Number.parseInt(String(optionsConversation?.id ?? ''), 10);
      const userId = Number.parseInt(String(participantId ?? ''), 10);
      if (!conversationId || !userId) {
        return;
      }

      setParticipantsModalBusy(true);
      setParticipantsModalError('');

      try {
        const response = await removeInternalChatGroupParticipant({
          role,
          conversationId,
          participantId: userId,
        });

        if (response.conversation) {
          applyConversationSummaryUpdate(response.conversation);
        }
      } catch (requestError) {
        setParticipantsModalError(
          parseErrorMessage(requestError, 'Nao foi possivel remover participante do grupo.')
        );
      } finally {
        setParticipantsModalBusy(false);
      }
    },
    [applyConversationSummaryUpdate, optionsConversation?.id, role]
  );

  const handleToggleParticipantAdmin = useCallback(
    async (participant) => {
      const conversationId = Number.parseInt(String(optionsConversation?.id ?? ''), 10);
      const participantId = Number.parseInt(String(participant?.id ?? ''), 10);
      if (!conversationId || !participantId) {
        return;
      }

      setParticipantsModalBusy(true);
      setParticipantsModalError('');

      try {
        const response = await updateInternalChatGroupParticipantAdmin({
          role,
          conversationId,
          participantId,
          isAdmin: !Boolean(participant?.is_admin),
        });

        if (response.conversation) {
          applyConversationSummaryUpdate(response.conversation);
        }
      } catch (requestError) {
        setParticipantsModalError(
          parseErrorMessage(requestError, 'Nao foi possivel atualizar permissao de admin.')
        );
      } finally {
        setParticipantsModalBusy(false);
      }
    },
    [applyConversationSummaryUpdate, optionsConversation?.id, role]
  );

  const handleLeaveGroup = useCallback(async () => {
    const conversationId = Number.parseInt(String(optionsConversation?.id ?? ''), 10);
    if (!conversationId) {
      return;
    }

    const transferId = Number.parseInt(String(leaveTransferAdminTo ?? ''), 10) || null;

    setConversationOptionsBusy(true);
    setConversationOptionsError('');

    try {
      await leaveInternalChatGroup({
        role,
        conversationId,
        transferAdminTo: transferId,
      });

      removeConversationFromUi(conversationId);
      closeConversationOptionsModal();
    } catch (requestError) {
      setConversationOptionsError(
        parseErrorMessage(requestError, 'Nao foi possivel sair do grupo.')
      );
    } finally {
      setConversationOptionsBusy(false);
    }
  }, [
    closeConversationOptionsModal,
    leaveTransferAdminTo,
    optionsConversation?.id,
    removeConversationFromUi,
    role,
  ]);

  const handleDeleteGroup = useCallback(async () => {
    const conversationId = Number.parseInt(String(optionsConversation?.id ?? ''), 10);
    if (!conversationId) {
      return;
    }

    setConversationOptionsBusy(true);
    setConversationOptionsError('');

    try {
      await deleteInternalChatGroup({
        role,
        conversationId,
      });

      removeConversationFromUi(conversationId);
      closeConversationOptionsModal();
    } catch (requestError) {
      setConversationOptionsError(
        parseErrorMessage(requestError, 'Nao foi possivel apagar o grupo.')
      );
    } finally {
      setConversationOptionsBusy(false);
    }
  }, [closeConversationOptionsModal, optionsConversation?.id, removeConversationFromUi, role]);

  return {
    createModalOpen,
    createType,
    recipientsLoading,
    filteredRecipients,
    selectedRecipientId,
    selectedGroupIds,
    createBusy,
    createError,
    recipientsError,
    recipientSearch,
    openCreateModal,
    closeCreateModal,
    setRecipientSearch,
    selectRecipient,
    handleToggleGroupParticipant,
    handleCreateDirectConversation,
    handleCreateGroupConversation,

    optionsConversation,
    optionsParticipants,
    optionsIsGroup,
    optionsCurrentUserIsAdmin,
    transferAdminCandidates,
    conversationOptionsBusy,
    conversationOptionsError,
    groupNameDraft,
    participantsModalOpen,
    participantsModalSearch,
    participantsModalBusy,
    participantsModalError,
    leaveTransferAdminTo,
    filteredAddableGroupRecipients,
    setGroupNameDraft,
    setParticipantsModalOpen,
    setParticipantsModalSearch,
    setLeaveTransferAdminTo,
    handleOpenConversationOptions,
    closeConversationOptionsModal,
    handleDeleteDirectConversation,
    handleSaveGroupName,
    handleOpenParticipantsModal,
    handleAddParticipantToGroup,
    handleRemoveParticipantFromGroup,
    handleToggleParticipantAdmin,
    handleLeaveGroup,
    handleDeleteGroup,
  };
}
