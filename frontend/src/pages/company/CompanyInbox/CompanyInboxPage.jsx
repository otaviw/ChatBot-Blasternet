import './CompanyInboxPage.css';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import InboxBackButton from '@/components/ui/InboxBackButton/InboxBackButton.jsx';
import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
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
import NewConversationModal from './components/NewConversationModal.jsx';
import SendTemplateModal from './components/SendTemplateModal.jsx';
import ConversationSearchModal from './components/ConversationSearchModal.jsx';
import useCompanyInboxConversations from './hooks/useCompanyInboxConversations';
import useCompanyInboxDetailMessages from './hooks/useCompanyInboxDetailMessages';
import useCompanyInboxActions from './hooks/useCompanyInboxActions';
import api from '@/services/api';

const CONV_PER_PAGE = 25;

function CompanyInboxPage() {
  const [conversationSearchOpen, setConversationSearchOpen] = useState(false);
  const [conversationSearchQuery, setConversationSearchQuery] = useState('');
  const [conversationSearchLoading, setConversationSearchLoading] = useState(false);
  const [conversationSearchResults, setConversationSearchResults] = useState([]);
  const [focusedMessageId, setFocusedMessageId] = useState(null);
  const [conversationCounters, setConversationCounters] = useState({
    por_area: [],
    sem_area: { total_abertas: 0, total_sem_resposta: 0 },
    total_abertas: 0,
  });
  const typingTimersRef = useRef(new Map());
  const [typingConversationIds, setTypingConversationIds] = useState(new Set());

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
    conversationsLoading,
    conversationsLoadingMore,
    conversationsPagination,
    convSearchInput,
    filters,
    isSearchMode,
    searchTerm,
    handleConversationsScroll,
    handleConversationsSearchEnter,
    handleNextConversationPage,
    loadedConversationPageRef,
    refreshConversations,
    setConversations,
    setConvSearchInput,
    setFilters,
    upsertConversationInList,
  } = useCompanyInboxConversations({
    data,
    loading,
  });

  const attendants = useMemo(() => data?.attendants ?? [], [data]);
  const companyTags = useMemo(() => data?.company_tags ?? [], [data]);

  const loadConversationCounters = useCallback(async () => {
    try {
      const response = await api.get('/minha-conta/conversas/contadores');
      const payload = response.data ?? {};
      setConversationCounters({
        por_area: Array.isArray(payload.por_area) ? payload.por_area : [],
        sem_area: payload.sem_area ?? { total_abertas: 0, total_sem_resposta: 0 },
        total_abertas: Number(payload.total_abertas ?? 0),
      });
    } catch (_error) {
    }
  }, []);

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
    openConversationAtMessagePage,
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
    attachTag,
    detachTag,
    aiSuggestionBusy,
    aiSuggestionError,
    aiSuggestionStatus,
    aiConfidenceScore,
    aiSuggestionFeedbackState,
    submitAiSuggestionFeedback,
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
    resetForOpenConversation,
    saveContactName,
    sendManualReply,
    setManualText,
    setShowTemplates,
    setTagsModalOpen,
    setTransferModalOpen,
    showTemplates,
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

  useEffect(() => {
    if (!data?.authenticated) {
      return;
    }

    void loadConversationCounters();
  }, [data?.authenticated, loadConversationCounters]);

  useEffect(() => {
    setConversationSearchOpen(false);
    setConversationSearchQuery('');
    setConversationSearchResults([]);
    setConversationSearchLoading(false);
    setFocusedMessageId(null);
  }, [selectedId]);

  const unreadConversationSet = useMemo(
    () => new Set((unreadConversationIds ?? []).map((value) => Number(value))),
    [unreadConversationIds]
  );

  const openConversation = useCallback(
    async (conversationId) => {
      resetForOpenConversation();
      await openConversationRaw(conversationId);
      setFocusedMessageId(null);
    },
    [openConversationRaw, resetForOpenConversation]
  );

  useEffect(() => {
    if (!conversationSearchOpen || !selectedId) {
      return undefined;
    }

    let canceled = false;
    const handle = setTimeout(async () => {
      const term = conversationSearchQuery.trim();
      if (term === '') {
        setConversationSearchResults([]);
        setConversationSearchLoading(false);
        return;
      }

      setConversationSearchLoading(true);
      try {
        const response = await api.get(
          `/minha-conta/conversas/${selectedId}/mensagens/buscar`,
          {
            params: {
              q: term,
              messages_per_page: 25,
            },
          }
        );
        if (canceled) return;
        setConversationSearchResults(response.data?.results ?? []);
      } catch (_error) {
        if (canceled) return;
      } finally {
        if (!canceled) setConversationSearchLoading(false);
      }
    }, 400);

    return () => {
      canceled = true;
      clearTimeout(handle);
    };
  }, [conversationSearchOpen, conversationSearchQuery, selectedId]);

  const handleSelectConversationSearchResult = useCallback(
    async (result) => {
      const ok = await openConversationAtMessagePage(result?.message_page ?? 1);
      if (!ok) {
        return;
      }

      setFocusedMessageId(Number(result?.message_id || 0) || null);
      setConversationSearchOpen(false);
    },
    [openConversationAtMessagePage]
  );

  const handleCustomerTyping = useCallback((conversationId, ttlMs = 5000) => {
    const id = Number(conversationId);
    if (!id) {
      return;
    }

    setTypingConversationIds((prev) => {
      const next = new Set(prev);
      next.add(id);
      return next;
    });

    const existing = typingTimersRef.current.get(id);
    if (existing) {
      clearTimeout(existing);
    }

    const timeout = setTimeout(() => {
      typingTimersRef.current.delete(id);
      setTypingConversationIds((prev) => {
        const next = new Set(prev);
        next.delete(id);
        return next;
      });
    }, Math.max(1000, Number(ttlMs) || 5000));

    typingTimersRef.current.set(id, timeout);
  }, []);

  const handleRealtimeCountersUpdated = useCallback((counters) => {
    if (counters && typeof counters === 'object') {
      setConversationCounters({
        por_area: Array.isArray(counters.por_area) ? counters.por_area : [],
        sem_area: counters.sem_area ?? { total_abertas: 0, total_sem_resposta: 0 },
        total_abertas: Number(counters.total_abertas ?? 0),
      });
      return;
    }

    void loadConversationCounters();
  }, [loadConversationCounters]);

  useEffect(() => {
    return () => {
      for (const timeout of typingTimersRef.current.values()) {
        clearTimeout(timeout);
      }
      typingTimersRef.current.clear();
    };
  }, []);

  useInboxRealtimeSync({
    clearConversationPresence,
    onCountersUpdated: handleRealtimeCountersUpdated,
    onCustomerTyping: handleCustomerTyping,
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
        <div className="space-y-3">
          <LoadingSkeleton className="h-6 w-56" />
          <div className="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-3">
            <LoadingSkeleton className="h-[420px] w-full" />
            <LoadingSkeleton className="h-[420px] w-full" />
          </div>
        </div>
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
          <h1 className="inbox-title">Inbox ({conversationCounters.total_abertas} abertas)</h1>
        </div>
        <div className="inbox-layout">
          <ConversationsSidebar
            serviceAreaNames={serviceAreaNames}
            attendants={attendants}
            companyTags={companyTags}
            conversationCounters={conversationCounters}
            selectedId={selectedId}
            mobileVisible={!selectedId}
            convSearchInput={convSearchInput}
            onConvSearchInputChange={setConvSearchInput}
            onConvSearchEnter={handleConversationsSearchEnter}
            isSearchMode={isSearchMode}
            searchTerm={searchTerm}
            filters={filters}
            onFiltersChange={setFilters}
            conversationListRef={conversationListRef}
            onConversationsScroll={handleConversationsScroll}
            conversationsLoading={conversationsLoading}
            conversations={conversations}
            unreadConversationSet={unreadConversationSet}
            onOpenConversation={openConversation}
            conversationsPagination={conversationsPagination}
            onNextConversationPage={handleNextConversationPage}
            conversationsLoadingMore={conversationsLoadingMore}
            loadedConversationPage={loadedConversationPageRef.current}
            onNewConversation={() => setNewConvModalOpen(true)}
          />

          <section className={`inbox-messages flex-col${selectedId ? ' inbox-messages--visible' : ''}`}>
            {selectedId && <InboxBackButton onClick={() => setSelectedId(null)} />}
            {detailLoading && (
              <p className="inbox-empty-state text-sm text-[#737373]">Carregando conversa...</p>
            )}
            {detailError && <p className="inbox-empty-state text-sm text-red-600">{detailError}</p>}
            {!detailLoading && !detail && !detailError && (
              <div className="inbox-empty-state">
                <EmptyState
                  title="Selecione uma conversa"
                  subtitle="Escolha um contato na lista para visualizar mensagens e responder."
                />
              </div>
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
                  onOpenSendTemplateModal={() => setSendTemplateModalOpen(true)}
                  onOpenConversationSearchModal={() => setConversationSearchOpen(true)}
                  onDetachTag={detachTag}
                />
                {selectedId && typingConversationIds.has(Number(selectedId)) ? (
                  <div className="inbox-typing-indicator" aria-live="polite">
                    Cliente digitando
                    <span className="inbox-typing-dots" aria-hidden>
                      <span>.</span><span>.</span><span>.</span>
                    </span>
                  </div>
                ) : null}

                <MessagesPanel
                  detail={detail}
                  messagesPagination={messagesPagination}
                  onLoadMessagesPage={loadMessagesPage}
                  chatListRef={chatListRef}
                  onChatScroll={handleChatScroll}
                  messagesLoadingOlder={messagesLoadingOlder}
                  getMessageImageUrl={getMessageImageUrl}
                  focusedMessageId={focusedMessageId}
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
                  aiConfidenceScore={aiConfidenceScore}
                  aiSuggestionFeedbackState={aiSuggestionFeedbackState}
                  onAiSuggestionFeedback={submitAiSuggestionFeedback}
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
        companyTags={companyTags}
        onAttachTag={attachTag}
        onDetachTag={detachTag}
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

      <NewConversationModal
        open={newConvModalOpen}
        onClose={() => setNewConvModalOpen(false)}
        onSubmit={createConversation}
        busy={newConvBusy}
        error={newConvError}
      />

      <SendTemplateModal
        open={sendTemplateModalOpen}
        detail={detail}
        onClose={() => setSendTemplateModalOpen(false)}
        onConfirm={sendTemplateToConversation}
        busy={sendTemplateBusy}
        error={sendTemplateError}
        success={sendTemplateSuccess}
      />

      <ConversationSearchModal
        open={conversationSearchOpen}
        query={conversationSearchQuery}
        loading={conversationSearchLoading}
        results={conversationSearchResults}
        onQueryChange={setConversationSearchQuery}
        onClose={() => setConversationSearchOpen(false)}
        onSelectResult={handleSelectConversationSearchResult}
      />
    </Layout>
  );
}

export default CompanyInboxPage;
