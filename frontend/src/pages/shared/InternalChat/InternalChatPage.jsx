import './InternalChatPage.css';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import { useNotificationsContext } from '@/contexts/NotificationsContext';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import {
  addInternalChatGroupParticipant,
  buildConversationTitle,
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
import InternalChatMessagesPanel from './components/InternalChatMessagesPanel.jsx';
import InternalChatSidebar from './components/InternalChatSidebar.jsx';
import useInternalChatComposer from './hooks/useInternalChatComposer';
import useInternalChatConversations from './hooks/useInternalChatConversations';
import useInternalChatDetail from './hooks/useInternalChatDetail';
import useInternalChatRealtime from './hooks/useInternalChatRealtime';
import { formatDateTime, parseErrorMessage, parseRoleFromUser } from './internalChatUtils';

function InternalChatPage() {
  const { data, loading, error } = usePageData('/me');
  const { logout } = useLogout();
  const { markReadByReference } = useNotificationsContext();
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
  const queryConversationHandledRef = useRef(false);

  const user = data?.user ?? null;
  const role = useMemo(() => parseRoleFromUser(user), [user]);
  const currentUserId = Number.parseInt(String(user?.id ?? ''), 10) || null;
  const companyName = user?.company_name ?? '';
  const authenticated = Boolean(data?.authenticated);

  const {
    conversationListRef,
    conversationSearchInput,
    conversations,
    conversationsError,
    conversationsLoading,
    conversationsLoadingMore,
    conversationsPagination,
    handleConversationsScroll,
    handleNextConversationPage,
    loadedConversationPageRef,
    loadConversations,
    scheduleConversationsRefresh,
    setConversationSearchInput,
    setConversations,
  } = useInternalChatConversations({
    authenticated,
    role,
  });

  const {
    chatListRef,
    closeConversation,
    detail,
    detailError,
    detailLoading,
    handleChatScroll,
    loadMessagesPage,
    messagesLoadingOlder,
    messagesPagination,
    openConversation: openConversationRaw,
    refreshSelectedConversation,
    selectedConversationId,
    selectedConversationIdRef,
    setDetail,
    shouldScrollToBottomRef,
    sidebarVisibleOnMobile,
    wasChatNearBottomRef,
  } = useInternalChatDetail({
    authenticated,
    markReadByReference,
    role,
    setConversations,
  });

  const {
    cancelMessageEditing,
    clearMessageFileState,
    editingMessageId,
    editingMessageText,
    handleMessageDelete,
    handleMessageEditSave,
    handleMessageFileChange,
    handleSendMessage,
    handleToggleReaction,
    messageActionBusyId,
    messageActionError,
    messageFile,
    messageFilePreviewUrl,
    messageText,
    openMessageOptionsId,
    resetMessageInteractionState,
    sendBusy,
    sendError,
    setEditingMessageText,
    setMessageText,
    setOpenMessageOptionsId,
    startMessageEditing,
  } = useInternalChatComposer({
    loadConversations,
    role,
    scheduleConversationsRefresh,
    selectedConversationId,
    setConversations,
    setDetail,
    shouldScrollToBottomRef,
    wasChatNearBottomRef,
  });

  const openConversation = useCallback(
    async (conversationId) => {
      resetMessageInteractionState();
      await openConversationRaw(conversationId);
    },
    [openConversationRaw, resetMessageInteractionState]
  );

  const handleBackToConversations = useCallback(() => {
    resetMessageInteractionState();
    closeConversation();
  }, [closeConversation, resetMessageInteractionState]);

  const handleToggleMessageOptions = useCallback(
    (messageId) => {
      setOpenMessageOptionsId((previous) =>
        Number(previous) === Number(messageId) ? null : Number(messageId)
      );
    },
    [setOpenMessageOptionsId]
  );

  useInternalChatRealtime({
    authenticated,
    loadConversations,
    markReadByReference,
    role,
    scheduleConversationsRefresh,
    selectedConversationIdRef,
    setConversations,
    setDetail,
    shouldScrollToBottomRef,
    wasChatNearBottomRef,
  });

  useEffect(() => {
    if (!authenticated) {
      return;
    }

    if (queryConversationHandledRef.current) {
      return;
    }

    queryConversationHandledRef.current = true;
    const params = new URLSearchParams(window.location.search);
    const queryConversationId = Number.parseInt(
      String(params.get('conversationId') ?? ''),
      10
    );

    if (queryConversationId > 0) {
      void openConversation(queryConversationId);
    }
  }, [authenticated, openConversation]);

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

  const openCreateModal = async (type = 'direct') => {
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
  };

  const handleCreateDirectConversation = async () => {
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
  };

  const handleCreateGroupConversation = async () => {
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
  };

  const handleToggleGroupParticipant = (participantId) => {
    const id = Number(participantId);
    setSelectedGroupIds((previous) =>
      previous.includes(id)
        ? previous.filter((existing) => existing !== id)
        : [...previous, id]
    );
    setCreateError('');
  };

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

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando chat interno...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !user) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Nao foi possivel carregar o chat interno.</p>
      </Layout>
    );
  }

  const selectedConversation = detail;
  const currentTitle = selectedConversation
    ? buildConversationTitle(selectedConversation, currentUserId)
    : '';
  const currentParticipants = selectedConversation?.participants ?? [];

  return (
    <Layout
      role={role}
      companyName={role === 'company' ? companyName : undefined}
      onLogout={logout}
      fullWidth
    >
      <div className="internal-chat-page">
        <div className="internal-chat-header">
          <div className="internal-chat-header-row">
            <h1 className="internal-chat-title">Chat interno</h1>
            <a
              href={role === 'admin' ? '/admin/suporte' : '/suporte'}
              className="app-btn-secondary internal-chat-support-link"
            >
              Suporte
            </a>
          </div>
        </div>

        <div className="internal-chat-layout">
          <InternalChatSidebar
            conversationListRef={conversationListRef}
            conversationSearchInput={conversationSearchInput}
            conversations={conversations}
            conversationsError={conversationsError}
            conversationsLoading={conversationsLoading}
            conversationsLoadingMore={conversationsLoadingMore}
            conversationsPagination={conversationsPagination}
            currentUserId={currentUserId}
            formatDateTime={formatDateTime}
            onConversationSearchInputChange={setConversationSearchInput}
            onConversationsScroll={handleConversationsScroll}
            onNextConversationPage={handleNextConversationPage}
            onOpenConversation={openConversation}
            onOpenConversationOptions={handleOpenConversationOptions}
            onOpenCreateModal={() => void openCreateModal('direct')}
            onOpenCreateGroupModal={() => void openCreateModal('group')}
            loadedConversationPage={loadedConversationPageRef.current}
            selectedConversationId={selectedConversationId}
            sidebarVisibleOnMobile={sidebarVisibleOnMobile}
          />

          <InternalChatMessagesPanel
            chatListRef={chatListRef}
            composerProps={{
              messageFile,
              messageFilePreviewUrl,
              messageText,
              onClearFile: clearMessageFileState,
              onMessageFileChange: handleMessageFileChange,
              onMessageTextChange: setMessageText,
              onSubmit: handleSendMessage,
              sendBusy,
              sendError,
            }}
            currentParticipants={currentParticipants}
            currentTitle={currentTitle}
            currentUserId={currentUserId}
            detailError={detailError}
            detailLoading={detailLoading}
            editingMessageId={editingMessageId}
            editingMessageText={editingMessageText}
            formatDateTime={formatDateTime}
            messageActionBusyId={messageActionBusyId}
            messageActionError={messageActionError}
            messagesLoadingOlder={messagesLoadingOlder}
            messagesPagination={messagesPagination}
            onBack={handleBackToConversations}
            onCancelMessageEditing={cancelMessageEditing}
            onChatScroll={handleChatScroll}
            onLoadMessagesPage={loadMessagesPage}
            onMessageDelete={handleMessageDelete}
            onMessageEditSave={handleMessageEditSave}
            onRefresh={refreshSelectedConversation}
            onStartMessageEditing={startMessageEditing}
            onToggleMessageOptions={handleToggleMessageOptions}
            onToggleReaction={handleToggleReaction}
            onUpdateEditingMessageText={setEditingMessageText}
            openMessageOptionsId={openMessageOptionsId}
            selectedConversation={selectedConversation}
            selectedConversationId={selectedConversationId}
            sidebarVisibleOnMobile={sidebarVisibleOnMobile}
          />
        </div>
      </div>

      {createModalOpen ? (
        <div className="internal-chat-modal-overlay" role="dialog" aria-modal="true">
          <div className="internal-chat-modal">
            <header className="internal-chat-modal-header">
              <h3>{createType === 'group' ? 'Novo grupo' : 'Nova conversa interna'}</h3>
              <button
                type="button"
                className="app-btn-ghost"
                onClick={() => setCreateModalOpen(false)}
              >
                Fechar
              </button>
            </header>

            {createType === 'group' ? (
              <p className="internal-chat-modal-hint">
                Selecione pelo menos 2 participantes para criar o grupo.
                {selectedGroupIds.length > 0 ? ` (${selectedGroupIds.length} selecionado${selectedGroupIds.length > 1 ? 's' : ''})` : ''}
              </p>
            ) : null}

            <input
              type="search"
              className="app-input"
              placeholder="Buscar usuario por nome ou email..."
              value={recipientSearch}
              onChange={(event) => setRecipientSearch(event.target.value)}
            />

            <div className="internal-chat-recipient-list">
              {recipientsLoading ? (
                <p className="internal-chat-list-state">Carregando usuarios...</p>
              ) : null}

              {!recipientsLoading && !filteredRecipients.length ? (
                <p className="internal-chat-list-state">
                  Nenhum usuario disponivel para iniciar conversa.
                </p>
              ) : null}

              {filteredRecipients.map((recipient) => {
                const isSelectedDirect = createType === 'direct' && Number(selectedRecipientId) === Number(recipient.id);
                const isSelectedGroup = createType === 'group' && selectedGroupIds.includes(Number(recipient.id));
                const isActive = isSelectedDirect || isSelectedGroup;

                return (
                  <button
                    key={recipient.id}
                    type="button"
                    className={`internal-chat-recipient-item ${isActive ? 'internal-chat-recipient-item--active' : ''}`}
                    onClick={() => {
                      if (createType === 'group') {
                        handleToggleGroupParticipant(recipient.id);
                      } else {
                        setSelectedRecipientId(String(recipient.id));
                        setCreateError('');
                      }
                    }}
                  >
                    <span className="internal-chat-recipient-name">
                      {isSelectedGroup ? '✓ ' : ''}{recipient.name}
                    </span>
                    <span className="internal-chat-recipient-email">{recipient.email}</span>
                  </button>
                );
              })}
            </div>

            {recipientsError ? <p className="internal-chat-error-inline">{recipientsError}</p> : null}
            {createError ? <p className="internal-chat-error-inline">{createError}</p> : null}

            <footer className="internal-chat-modal-actions">
              <button
                type="button"
                className="app-btn-secondary"
                onClick={() => setCreateModalOpen(false)}
                disabled={createBusy}
              >
                Cancelar
              </button>
              <button
                type="button"
                className="app-btn-primary"
                onClick={() => {
                  if (createType === 'group') {
                    void handleCreateGroupConversation();
                  } else {
                    void handleCreateDirectConversation();
                  }
                }}
                disabled={createBusy}
              >
                {createBusy ? 'Criando...' : createType === 'group' ? 'Criar grupo' : 'Criar conversa'}
              </button>
            </footer>
          </div>
        </div>
      ) : null}

      {optionsConversation ? (
        <div className="internal-chat-modal-overlay" role="dialog" aria-modal="true">
          <div className="internal-chat-modal internal-chat-conversation-options-modal">
            <header className="internal-chat-modal-header">
              <h3>Opcoes da conversa</h3>
              <button
                type="button"
                className="app-btn-ghost"
                onClick={closeConversationOptionsModal}
              >
                Fechar
              </button>
            </header>

            <p className="internal-chat-modal-hint">
              {buildConversationTitle(optionsConversation, currentUserId)}
            </p>

            {optionsIsGroup ? (
              <div className="internal-chat-group-options">
                <label className="internal-chat-group-options-label">
                  Nome do grupo
                  <input
                    type="text"
                    className="app-input"
                    value={groupNameDraft}
                    onChange={(event) => setGroupNameDraft(event.target.value)}
                    disabled={!optionsCurrentUserIsAdmin || conversationOptionsBusy}
                  />
                </label>
                {optionsCurrentUserIsAdmin ? (
                  <button
                    type="button"
                    className="app-btn-secondary"
                    onClick={() => void handleSaveGroupName()}
                    disabled={conversationOptionsBusy || !groupNameDraft.trim()}
                  >
                    {conversationOptionsBusy ? 'Salvando...' : 'Salvar nome'}
                  </button>
                ) : (
                  <p className="internal-chat-modal-hint">
                    Apenas admins podem alterar o nome do grupo.
                  </p>
                )}

                {optionsCurrentUserIsAdmin ? (
                  <button
                    type="button"
                    className="app-btn-secondary"
                    onClick={() => void handleOpenParticipantsModal()}
                    disabled={conversationOptionsBusy}
                  >
                    Participantes
                  </button>
                ) : null}

                {optionsCurrentUserIsAdmin && transferAdminCandidates.length > 0 ? (
                  <label className="internal-chat-group-options-label">
                    Transferir admin antes de sair (somente quando necessario)
                    <select
                      className="app-input"
                      value={leaveTransferAdminTo}
                      onChange={(event) => setLeaveTransferAdminTo(event.target.value)}
                      disabled={conversationOptionsBusy}
                    >
                      <option value="">Nao transferir agora</option>
                      {transferAdminCandidates.map((participant) => (
                        <option key={participant.id} value={participant.id}>
                          {participant.name}
                        </option>
                      ))}
                    </select>
                  </label>
                ) : null}

                <button
                  type="button"
                  className="app-btn-secondary"
                  onClick={() => void handleLeaveGroup()}
                  disabled={conversationOptionsBusy}
                >
                  {conversationOptionsBusy ? 'Saindo...' : 'Sair do grupo'}
                </button>

                {optionsCurrentUserIsAdmin ? (
                  <button
                    type="button"
                    className="app-btn-danger"
                    onClick={() => void handleDeleteGroup()}
                    disabled={conversationOptionsBusy}
                  >
                    {conversationOptionsBusy ? 'Apagando...' : 'Apagar grupo'}
                  </button>
                ) : null}
              </div>
            ) : (
              <div className="internal-chat-group-options">
                <p className="internal-chat-modal-hint">
                  Esta acao remove a conversa apenas para voce.
                </p>
                <button
                  type="button"
                  className="app-btn-danger"
                  onClick={() => void handleDeleteDirectConversation()}
                  disabled={conversationOptionsBusy}
                >
                  {conversationOptionsBusy ? 'Apagando...' : 'Apagar conversa'}
                </button>
              </div>
            )}

            {conversationOptionsError ? (
              <p className="internal-chat-error-inline">{conversationOptionsError}</p>
            ) : null}
          </div>
        </div>
      ) : null}

      {optionsConversation && participantsModalOpen ? (
        <div className="internal-chat-modal-overlay" role="dialog" aria-modal="true">
          <div className="internal-chat-modal internal-chat-participants-modal">
            <header className="internal-chat-modal-header">
              <h3>Participantes do grupo</h3>
              <button
                type="button"
                className="app-btn-ghost"
                onClick={() => setParticipantsModalOpen(false)}
              >
                Fechar
              </button>
            </header>

            <input
              type="search"
              className="app-input"
              placeholder="Buscar participante para adicionar..."
              value={participantsModalSearch}
              onChange={(event) => setParticipantsModalSearch(event.target.value)}
            />

            <div className="internal-chat-participants-grid">
              <section className="internal-chat-participants-block">
                <h4>Participantes atuais</h4>
                <ul className="internal-chat-participants-list">
                  {optionsParticipants.map((participant) => {
                    const isSelf = Number(participant.id) === Number(currentUserId);
                    const isParticipantAdmin = Boolean(participant.is_admin);

                    return (
                      <li key={participant.id} className="internal-chat-participant-item">
                        <div>
                          <strong>{participant.name}</strong>
                          <p>{participant.email}</p>
                          <span className="internal-chat-participant-badges">
                            {isParticipantAdmin ? 'Admin' : 'Participante'}
                            {isSelf ? ' | voce' : ''}
                          </span>
                        </div>
                        <div className="internal-chat-participant-actions">
                          <button
                            type="button"
                            className="app-btn-secondary text-xs"
                            onClick={() => void handleToggleParticipantAdmin(participant)}
                            disabled={participantsModalBusy || isSelf}
                          >
                            {isParticipantAdmin ? 'Tirar admin' : 'Tornar admin'}
                          </button>
                          {!isSelf ? (
                            <button
                              type="button"
                              className="app-btn-danger text-xs"
                              onClick={() => void handleRemoveParticipantFromGroup(participant.id)}
                              disabled={participantsModalBusy}
                            >
                              Remover
                            </button>
                          ) : null}
                        </div>
                      </li>
                    );
                  })}
                </ul>
              </section>

              <section className="internal-chat-participants-block">
                <h4>Adicionar participantes</h4>
                <ul className="internal-chat-participants-list">
                  {filteredAddableGroupRecipients.length === 0 ? (
                    <li className="internal-chat-list-state">
                      Nenhum usuario disponivel para adicionar.
                    </li>
                  ) : (
                    filteredAddableGroupRecipients.map((recipient) => (
                      <li key={recipient.id} className="internal-chat-participant-item">
                        <div>
                          <strong>{recipient.name}</strong>
                          <p>{recipient.email}</p>
                        </div>
                        <button
                          type="button"
                          className="app-btn-primary text-xs"
                          onClick={() => void handleAddParticipantToGroup(recipient.id)}
                          disabled={participantsModalBusy}
                        >
                          Adicionar
                        </button>
                      </li>
                    ))
                  )}
                </ul>
              </section>
            </div>

            {participantsModalError ? (
              <p className="internal-chat-error-inline">{participantsModalError}</p>
            ) : null}
          </div>
        </div>
      ) : null}
    </Layout>
  );
}

export default InternalChatPage;
