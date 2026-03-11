import './InternalChatPage.css';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import { useNotificationsContext } from '@/contexts/NotificationsContext';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import {
  buildConversationTitle,
  createInternalDirectConversation,
  listInternalChatRecipients,
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
  const [recipients, setRecipients] = useState([]);
  const [recipientsLoading, setRecipientsLoading] = useState(false);
  const [recipientsError, setRecipientsError] = useState('');
  const [recipientSearch, setRecipientSearch] = useState('');
  const [selectedRecipientId, setSelectedRecipientId] = useState('');
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');
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

  const openCreateModal = async () => {
    if (!authenticated) {
      return;
    }

    setCreateModalOpen(true);
    setCreateError('');
    setRecipientsError('');
    setRecipientSearch('');
    setSelectedRecipientId('');

    if (recipients.length > 0) {
      return;
    }

    setRecipientsLoading(true);
    try {
      const response = await listInternalChatRecipients({
        role,
        excludeUserId: currentUserId,
      });
      setRecipients(response.users ?? []);
    } catch (requestError) {
      setRecipientsError(
        parseErrorMessage(
          requestError,
          'Nao foi possivel carregar a lista de usuarios para iniciar conversa.'
        )
      );
    } finally {
      setRecipientsLoading(false);
    }
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
            onOpenCreateModal={openCreateModal}
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
              <h3>Nova conversa interna</h3>
              <button
                type="button"
                className="app-btn-ghost"
                onClick={() => setCreateModalOpen(false)}
              >
                Fechar
              </button>
            </header>

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

              {filteredRecipients.map((recipient) => (
                <button
                  key={recipient.id}
                  type="button"
                  className={`internal-chat-recipient-item ${
                    Number(selectedRecipientId) === Number(recipient.id)
                      ? 'internal-chat-recipient-item--active'
                      : ''
                  }`}
                  onClick={() => {
                    setSelectedRecipientId(String(recipient.id));
                    setCreateError('');
                  }}
                >
                  <span className="internal-chat-recipient-name">{recipient.name}</span>
                  <span className="internal-chat-recipient-email">{recipient.email}</span>
                </button>
              ))}
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
                onClick={() => void handleCreateDirectConversation()}
                disabled={createBusy}
              >
                {createBusy ? 'Criando...' : 'Criar conversa'}
              </button>
            </footer>
          </div>
        </div>
      ) : null}
    </Layout>
  );
}

export default InternalChatPage;
