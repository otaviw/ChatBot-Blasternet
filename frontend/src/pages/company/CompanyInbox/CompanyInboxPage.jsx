import './CompanyInboxPage.css';
import { useCallback, useEffect, useMemo } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import InboxBackButton from '@/components/ui/InboxBackButton/InboxBackButton.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import { useNotificationsContext } from '@/hooks/useNotificationsContext';
import useInboxRealtimeSync from './useInboxRealtimeSync';
import ConversationsSidebar from './components/ConversationsSidebar.jsx';
import ConversationToolbar from './components/ConversationToolbar.jsx';
import MessagesPanel from './components/MessagesPanel.jsx';
import ReplyComposer from './components/ReplyComposer.jsx';
import TagsModal from './components/TagsModal.jsx';
import TransferModal from './components/TransferModal.jsx';
import useCompanyInboxConversations from './hooks/useCompanyInboxConversations';
import useCompanyInboxDetailMessages from './hooks/useCompanyInboxDetailMessages';
import useCompanyInboxActions from './hooks/useCompanyInboxActions';

const CONV_PER_PAGE = 25;

function CompanyInboxPage() {
  const { data, loading, error } = usePageData(
    `/minha-conta/conversas?page=1&per_page=${CONV_PER_PAGE}`
  );
  const { data: botData } = usePageData('/minha-conta/bot');
  const serviceAreaNames = useMemo(() => {
    const list = botData?.settings?.service_areas;
    if (!Array.isArray(list)) return [];
    return list.map((a) => String(a ?? '').trim()).filter(Boolean);
  }, [botData]);
  const { logout } = useLogout();
  const { markReadByReference, unreadConversationIds, setActiveConversationId } = useNotificationsContext();

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

  useEffect(() => {
    setActiveConversationId(selectedId ?? 0);
    return () => setActiveConversationId(0);
  }, [selectedId, setActiveConversationId]);

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
    selectedId,
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
          Não foi possível carregar as conversas.
        </p>
      </Layout>
    );
  }

  return (
    <Layout role="company" onLogout={logout} fullWidth>
      <div className="inbox-page">
        <div className={`inbox-header${selectedId ? ' inbox-header--hidden-mobile' : ''}`}>
          <h1 className="inbox-title">Conversas da empresa — atendimento em tempo real</h1>
        </div>
        <div className="inbox-layout">
          <ConversationsSidebar
            serviceAreaNames={serviceAreaNames}
            selectedId={selectedId}
            mobileVisible={!selectedId}
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

          <section className={`inbox-messages flex-col${selectedId ? ' inbox-messages--visible' : ''}`}>
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
                  serviceAreaNames={serviceAreaNames}
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
                  onOpenTransferModal={() => setTransferModalOpen(true)}
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
                  canUseAiSuggestion={Boolean(
                    data?.can_access_internal_ai_chat ?? data?.can_use_ai
                  )}
                  onRequestAiSuggestion={requestAiSuggestion}
                  aiSuggestionBusy={aiSuggestionBusy}
                  aiSuggestionStatus={aiSuggestionStatus}
                  aiSuggestionError={aiSuggestionError}
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

      <TransferModal
        open={transferModalOpen}
        detail={detail}
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
        onClose={() => setTransferModalOpen(false)}
      />
    </Layout>
  );
}

export default CompanyInboxPage;
