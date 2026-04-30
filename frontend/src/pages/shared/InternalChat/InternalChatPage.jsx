import './InternalChatPage.css';
import { useCallback, useEffect, useMemo, useRef } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import SkeletonCard from '@/components/ui/SkeletonCard/SkeletonCard.jsx';
import SkeletonText from '@/components/ui/SkeletonText/SkeletonText.jsx';
import { useNotificationsContext } from '@/hooks/useNotificationsContext';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import { buildConversationTitle } from '@/services/internalChatService';
import InternalChatConversationOptionsModal from './components/InternalChatConversationOptionsModal.jsx';
import InternalChatCreateModal from './components/InternalChatCreateModal.jsx';
import InternalChatMessagesPanel from './components/InternalChatMessagesPanel.jsx';
import InternalChatSidebar from './components/InternalChatSidebar.jsx';
import useInternalChatComposer from './hooks/useInternalChatComposer';
import useInternalChatConversations from './hooks/useInternalChatConversations';
import useInternalChatDetail from './hooks/useInternalChatDetail';
import useInternalChatPage from './hooks/useInternalChatPage';
import useInternalChatRealtime from './hooks/useInternalChatRealtime';
import { formatDateTime, parseRoleFromUser } from './internalChatUtils';

function InternalChatPage() {
  const { data, loading, error } = usePageData('/me');
  const { logout } = useLogout();
  const { markReadByReference } = useNotificationsContext();
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

  const {
    closeConversationOptionsModal,
    closeCreateModal,
    conversationOptionsBusy,
    conversationOptionsError,
    createBusy,
    createError,
    createModalOpen,
    createType,
    filteredAddableGroupRecipients,
    filteredRecipients,
    groupNameDraft,
    handleAddParticipantToGroup,
    handleCreateDirectConversation,
    handleCreateGroupConversation,
    handleDeleteDirectConversation,
    handleDeleteGroup,
    handleLeaveGroup,
    handleOpenConversationOptions,
    handleOpenParticipantsModal,
    handleRemoveParticipantFromGroup,
    handleSaveGroupName,
    handleToggleGroupParticipant,
    handleToggleParticipantAdmin,
    leaveTransferAdminTo,
    openCreateModal,
    optionsConversation,
    optionsCurrentUserIsAdmin,
    optionsIsGroup,
    optionsParticipants,
    participantsModalBusy,
    participantsModalError,
    participantsModalOpen,
    participantsModalSearch,
    recipientSearch,
    recipientsError,
    recipientsLoading,
    selectRecipient,
    selectedGroupIds,
    selectedRecipientId,
    setGroupNameDraft,
    setLeaveTransferAdminTo,
    setParticipantsModalOpen,
    setParticipantsModalSearch,
    setRecipientSearch,
    transferAdminCandidates,
  } = useInternalChatPage({
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
  });

  useInternalChatRealtime({
    authenticated,
    loadConversations,
    markReadByReference,
    role,
    scheduleConversationsRefresh,
    selectedConversationId,
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

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <div className="space-y-4">
          <SkeletonText lines={2} lineClassName="h-4 w-80 max-w-full" />
          <div className="grid grid-cols-1 lg:grid-cols-[300px_1fr] gap-4">
            <SkeletonCard className="h-[420px]" lines={6} />
            <SkeletonCard className="h-[420px]" lines={8} />
          </div>
        </div>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !user) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Não foi possível carregar o chat interno.</p>
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

      <InternalChatCreateModal
        createBusy={createBusy}
        createError={createError}
        createModalOpen={createModalOpen}
        createType={createType}
        filteredRecipients={filteredRecipients}
        onClose={closeCreateModal}
        onCreateDirectConversation={handleCreateDirectConversation}
        onCreateGroupConversation={handleCreateGroupConversation}
        onRecipientSearchChange={setRecipientSearch}
        onSelectRecipient={selectRecipient}
        onToggleGroupParticipant={handleToggleGroupParticipant}
        recipientSearch={recipientSearch}
        recipientsError={recipientsError}
        recipientsLoading={recipientsLoading}
        selectedGroupIds={selectedGroupIds}
        selectedRecipientId={selectedRecipientId}
      />

      <InternalChatConversationOptionsModal
        closeConversationOptionsModal={closeConversationOptionsModal}
        conversationOptionsBusy={conversationOptionsBusy}
        conversationOptionsError={conversationOptionsError}
        currentUserId={currentUserId}
        filteredAddableGroupRecipients={filteredAddableGroupRecipients}
        groupNameDraft={groupNameDraft}
        handleAddParticipantToGroup={handleAddParticipantToGroup}
        handleDeleteDirectConversation={handleDeleteDirectConversation}
        handleDeleteGroup={handleDeleteGroup}
        handleLeaveGroup={handleLeaveGroup}
        handleOpenParticipantsModal={handleOpenParticipantsModal}
        handleRemoveParticipantFromGroup={handleRemoveParticipantFromGroup}
        handleSaveGroupName={handleSaveGroupName}
        handleToggleParticipantAdmin={handleToggleParticipantAdmin}
        leaveTransferAdminTo={leaveTransferAdminTo}
        optionsConversation={optionsConversation}
        optionsCurrentUserIsAdmin={optionsCurrentUserIsAdmin}
        optionsIsGroup={optionsIsGroup}
        optionsParticipants={optionsParticipants}
        participantsModalBusy={participantsModalBusy}
        participantsModalError={participantsModalError}
        participantsModalOpen={participantsModalOpen}
        participantsModalSearch={participantsModalSearch}
        setGroupNameDraft={setGroupNameDraft}
        setLeaveTransferAdminTo={setLeaveTransferAdminTo}
        setParticipantsModalOpen={setParticipantsModalOpen}
        setParticipantsModalSearch={setParticipantsModalSearch}
        transferAdminCandidates={transferAdminCandidates}
      />
    </Layout>
  );
}

export default InternalChatPage;
