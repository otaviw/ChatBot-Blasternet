import './CompanyInboxPage.css';
import { useCallback, useMemo } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import InboxBackButton from '@/components/ui/InboxBackButton/InboxBackButton.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import { useNotificationsContext } from '@/contexts/NotificationsContext';
import useInboxRealtimeSync from './useInboxRealtimeSync';
import ConversationsSidebar from './components/ConversationsSidebar.jsx';
import ConversationToolbar from './components/ConversationToolbar.jsx';
import MessagesPanel from './components/MessagesPanel.jsx';
import ReplyComposer from './components/ReplyComposer.jsx';
import TagsModal from './components/TagsModal.jsx';
import useCompanyInboxConversations from './hooks/useCompanyInboxConversations';
import useCompanyInboxDetailMessages from './hooks/useCompanyInboxDetailMessages';
import useCompanyInboxActions from './hooks/useCompanyInboxActions';

const CONV_PER_PAGE = 25;

function CompanyInboxPage() {
  const { data, loading, error } = usePageData(
    `/minha-conta/conversas?page=1&per_page=${CONV_PER_PAGE}`
  );
  const { logout } = useLogout();
  const { markReadByReference, unreadConversationIds } = useNotificationsContext();

  const {
    conversationListRef,
    conversations,
    conversationsLoadingMore,
    conversationsPagination,
    convSearchInput,
    handleConversationsScroll,
    handleConversationsSearchEnter,
    handleNextConversationPage,
    loadedConversationPageRef,
    refreshConversations,
    setConversations,
    setConvSearchInput,
    upsertConversationInList,
  } = useCompanyInboxConversations({
    data,
    loading,
  });

  const {
    chatListRef,
    clearConversationPresence,
    contactNameInput,
    detail,
    detailError,
    detailLoading,
    handleChatScroll,
    loadMessagesPage,
    messagesLoadingOlder,
    messagesPagination,
    openConversation: openConversationRaw,
    refreshConversationDetail,
    selectedId,
    selectedIdRef,
    setContactNameInput,
    setDetail,
    setDetailError,
    setSelectedId,
    shouldScrollChatToBottomRef,
    transferOptions,
    wasChatNearBottomRef,
  } = useCompanyInboxDetailMessages({
    data,
    loading,
    markReadByReference,
  });

  const {
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
  } = useCompanyInboxActions({
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
  });

  const unreadConversationSet = useMemo(
    () => new Set((unreadConversationIds ?? []).map((value) => Number(value))),
    [unreadConversationIds]
  );

  const openConversation = useCallback(
    async (conversationId) => {
      resetForOpenConversation();
      await openConversationRaw(conversationId);
    },
    [openConversationRaw, resetForOpenConversation]
  );

  useInboxRealtimeSync({
    clearConversationPresence,
    refreshConversationDetail,
    refreshConversations,
    selectedIdRef,
    setConversations,
    setDetail,
  });

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando inbox...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">
          Nao foi possivel carregar a inbox.
        </p>
      </Layout>
    );
  }

  return (
    <Layout role="company" onLogout={logout} fullWidth>
      <div className="inbox-page">
        <div className="inbox-header">
          <h1 className="inbox-title">Inbox da empresa - Acompanhe atendimento em tempo real.</h1>
        </div>
        <div className="inbox-layout grid grid-cols-1 lg:grid-cols-[minmax(200px,280px)_1fr]">
          <ConversationsSidebar
            selectedId={selectedId}
            convSearchInput={convSearchInput}
            onConvSearchInputChange={setConvSearchInput}
            onConvSearchEnter={handleConversationsSearchEnter}
            conversationListRef={conversationListRef}
            onConversationsScroll={handleConversationsScroll}
            conversations={conversations}
            unreadConversationSet={unreadConversationSet}
            onOpenConversation={openConversation}
            conversationsPagination={conversationsPagination}
            onNextConversationPage={handleNextConversationPage}
            conversationsLoadingMore={conversationsLoadingMore}
            loadedConversationPage={loadedConversationPageRef.current}
          />

          <section className={`inbox-messages ${selectedId ? 'flex' : 'hidden lg:flex'} flex-col`}>
            {selectedId && <InboxBackButton onClick={() => setSelectedId(null)} />}
            {detailLoading && (
              <p className="inbox-empty-state text-sm text-[#737373]">Carregando conversa...</p>
            )}
            {detailError && <p className="inbox-empty-state text-sm text-red-600">{detailError}</p>}
            {!detailLoading && !detail && !detailError && (
              <p className="inbox-empty-state text-sm text-[#706f6c]">Selecione uma conversa.</p>
            )}
            {!!detail && (
              <div className="inbox-detail-layout">
                <ConversationToolbar
                  detail={detail}
                  contactNameInput={contactNameInput}
                  onContactNameChange={handleContactNameInputChange}
                  onSaveContactName={saveContactName}
                  contactBusy={contactBusy}
                  contactSuccess={contactSuccess}
                  contactError={contactError}
                  actionBusy={actionBusy}
                  onAssumeConversation={assumeConversation}
                  onReleaseConversation={releaseConversation}
                  onCloseConversation={closeConversation}
                  onOpenTagsModal={() => setTagsModalOpen(true)}
                  transferExpanded={transferExpanded}
                  onToggleTransferExpanded={() => setTransferExpanded((value) => !value)}
                  transferArea={transferArea}
                  onTransferAreaChange={handleTransferAreaChange}
                  transferOptions={transferOptions}
                  transferUserId={transferUserId}
                  onTransferUserChange={handleTransferUserChange}
                  availableUsers={availableUsers}
                  onTransferConversation={transferConversation}
                  transferBusy={transferBusy}
                  transferSuccess={transferSuccess}
                  transferError={transferError}
                />

                <MessagesPanel
                  detail={detail}
                  messagesPagination={messagesPagination}
                  onLoadMessagesPage={loadMessagesPage}
                  chatListRef={chatListRef}
                  onChatScroll={handleChatScroll}
                  messagesLoadingOlder={messagesLoadingOlder}
                  getMessageImageUrl={getMessageImageUrl}
                />

                <ReplyComposer
                  onSendManualReply={sendManualReply}
                  showTemplates={showTemplates}
                  onToggleTemplates={() => setShowTemplates((prev) => !prev)}
                  quickReplies={quickReplies}
                  onApplyQuickReply={handleApplyQuickReply}
                  onManualImageChange={handleManualImageChange}
                  manualImageFile={manualImageFile}
                  onRemoveManualImage={removeManualImage}
                  manualText={manualText}
                  onManualTextChange={setManualText}
                  manualBusy={manualBusy}
                  manualImagePreviewUrl={manualImagePreviewUrl}
                  manualError={manualError}
                />
              </div>
            )}
          </section>
        </div>
      </div>

      <TagsModal
        open={tagsModalOpen}
        detail={detail}
        tagInput={tagInput}
        onTagInputChange={setTagInput}
        onAddTag={() => addTag(tagInput)}
        onRemoveTag={removeTag}
        onClose={() => setTagsModalOpen(false)}
      />
    </Layout>
  );
}

export default CompanyInboxPage;
