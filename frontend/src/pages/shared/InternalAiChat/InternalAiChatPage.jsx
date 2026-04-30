import './InternalAiChatPage.css';
import { useMemo } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Notice from '@/components/ui/Notice/Notice.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import SkeletonCard from '@/components/ui/SkeletonCard/SkeletonCard.jsx';
import SkeletonConversationItem from '@/components/ui/SkeletonConversationItem/SkeletonConversationItem.jsx';
import SkeletonText from '@/components/ui/SkeletonText/SkeletonText.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import useAdminCompanySelector from '@/hooks/useAdminCompanySelector';
import useInternalAiChatPage from './hooks/useInternalAiChatPage';

const parseRoleFromUser = (user) => {
  const normalizedRole = String(user?.role ?? '').trim().toLowerCase();
  return normalizedRole === 'system_admin' ? 'admin' : 'company';
};

const formatDateTime = (value) => {
  const timestamp = new Date(value).getTime();
  if (!Number.isFinite(timestamp) || timestamp <= 0) {
    return '';
  }

  return new Date(timestamp).toLocaleString('pt-BR');
};

const buildConversationTitle = (conversation) => {
  const title = String(conversation?.title ?? '').trim();
  if (title) {
    return title;
  }

  return `Conversa #${conversation?.id ?? '-'}`;
};

const buildConversationPreview = (conversation) => {
  const content = String(conversation?.last_message?.content ?? '').trim();
  if (!content) {
    return 'Sem mensagens ainda.';
  }

  if (content.length <= 96) {
    return content;
  }

  return `${content.slice(0, 96)}...`;
};

const buildConversationDateLabel = (conversation) =>
  formatDateTime(
    conversation?.last_message_at ?? conversation?.updated_at ?? conversation?.created_at ?? null
  );

const buildMessageSenderLabel = (message, currentUserId) => {
  if (String(message?.role ?? '') === 'assistant') {
    return 'IA';
  }

  if (String(message?.role ?? '') === 'system') {
    return 'Sistema';
  }

  if (Number(message?.user_id ?? 0) === Number(currentUserId ?? 0)) {
    return 'Você';
  }

  return 'Usuário';
};

function InternalAiChatPage() {
  const { data, loading, error } = usePageData('/me');
  const { logout } = useLogout();
  const messageInputId = 'internal-ai-chat-message-input';
  const sendErrorId = 'internal-ai-chat-send-error';

  const user = data?.user ?? null;
  const role = useMemo(() => parseRoleFromUser(user), [user]);
  const companyName = user?.company_name ?? '';
  const isSystemAdmin = user?.role === 'system_admin';
  const canAccessInternalAiChat = Boolean(
    user?.can_access_internal_ai_chat ?? user?.can_use_ai
  );

  const { companies, selectedCompanyId, setSelectedCompanyId } = useAdminCompanySelector({
    isAdmin: isSystemAdmin,
  });

  const companyId = isSystemAdmin ? (selectedCompanyId || null) : null;

  const {
    chatListRef,
    conversations,
    conversationsError,
    conversationsLoading,
    conversationsLoadingMore,
    conversationsPagination,
    createBusy,
    createConversation,
    createError,
    detail,
    detailError,
    detailLoading,
    draftMessage,
    hasMoreConversations,
    hasOlderMessages,
    loadConversations,
    loadMoreConversations,
    loadOlderMessages,
    messagesLoadingOlder,
    openConversation,
    reloadSelectedConversation,
    selectedConversationId,
    sendBusy,
    sendError,
    sendMessage,
    setDraftMessage,
    streamingContent,
    handleChatScroll,
  } = useInternalAiChatPage({
    enabled: Boolean(data?.authenticated && user && canAccessInternalAiChat && (!isSystemAdmin || companyId)),
    companyId,
  });
  const hasConversations = conversations.length > 0;
  const totalConversations = Number(conversationsPagination?.total ?? conversations.length ?? 0);
  const listCountLabel =
    totalConversations > 0 ? `${conversations.length} / ${totalConversations}` : `${conversations.length}`;

  if (loading) {
    return (
      <Layout role={role} onLogout={logout}>
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
        <p className="text-sm text-red-600">Não foi possível carregar o chat com IA.</p>
      </Layout>
    );
  }

  if (!canAccessInternalAiChat) {
    return (
      <Layout role={role} companyName={companyName || undefined} onLogout={logout}>
        <Notice tone="info">
          Chat com IA indisponível para este utilizador ou para esta empresa.
        </Notice>
      </Layout>
    );
  }

  if (isSystemAdmin && !companyId) {
    return (
      <Layout role={role} onLogout={logout} fullWidth>
        <PageHeader
          title="Chat interno com IA"
          subtitle="Selecione uma empresa para iniciar o chat."
          action={companies.length > 0 ? (
            <select
              value={selectedCompanyId}
              onChange={(e) => setSelectedCompanyId(e.target.value)}
              className="rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm text-[#1f2937] outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
            >
              <option value="">Selecione uma empresa...</option>
              {companies.map((c) => (
                <option key={c.id} value={String(c.id)}>{c.name}</option>
              ))}
            </select>
          ) : undefined}
        />
        <Notice tone="info">Selecione uma empresa no menu acima para usar o chat com IA.</Notice>
      </Layout>
    );
  }

  return (
    <Layout role={role} companyName={companyName || undefined} onLogout={logout} fullWidth>
      <div className="internal-ai-chat-page">
        <PageHeader
          title="Chat interno com IA"
          subtitle="Converse com a IA usando as configurações da sua empresa."
          action={
            <div className="internal-ai-chat-page__actions">
              {isSystemAdmin && companies.length > 0 && (
                <select
                  value={selectedCompanyId}
                  onChange={(e) => setSelectedCompanyId(e.target.value)}
                  className="rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm text-[#1f2937] outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
                >
                  {companies.map((c) => (
                    <option key={c.id} value={String(c.id)}>{c.name}</option>
                  ))}
                </select>
              )}
              <button
                type="button"
                className="app-btn-secondary"
                onClick={() => void loadConversations()}
                disabled={conversationsLoading || conversationsLoadingMore || createBusy}
              >
                Atualizar
              </button>
              <button
                type="button"
                className="app-btn-primary"
                onClick={() => void createConversation()}
                disabled={createBusy}
              >
                {createBusy ? 'Criando...' : 'Nova conversa'}
              </button>
            </div>
          }
        />

        {createError ? (
          <Notice tone="danger" className="mb-4" aria-live="polite">
            {createError}
          </Notice>
        ) : null}

        <section className="internal-ai-chat">
          <aside className="internal-ai-chat__sidebar">
            <header className="internal-ai-chat__sidebar-header">
              <h2 className="internal-ai-chat__sidebar-title">Conversas</h2>
              <span className="internal-ai-chat__sidebar-count">{conversations.length}</span>
            </header>

            {conversationsError ? (
              <div className="internal-ai-chat__sidebar-notice">
                <Notice tone="danger">{conversationsError}</Notice>
                <button
                  type="button"
                  className="app-btn-secondary internal-ai-chat__small-btn"
                  onClick={() => void loadConversations()}
                  disabled={conversationsLoading}
                >
                  Recarregar lista
                </button>
              </div>
            ) : null}

            <ul
              className="internal-ai-chat__conversation-list"
              aria-busy={conversationsLoading || conversationsLoadingMore}
            >
              {conversationsLoading && !conversations.length ? (
                <>
                  <li><SkeletonConversationItem /></li>
                  <li><SkeletonConversationItem /></li>
                  <li><SkeletonConversationItem /></li>
                </>
              ) : null}

              {!conversationsLoading && !conversations.length ? (
                <li className="internal-ai-chat__empty">
                  Nenhuma conversa encontrada. Clique em "Nova conversa" para iniciar.
                </li>
              ) : null}

              {conversations.map((conversation) => {
                const isSelected = Number(selectedConversationId) === Number(conversation.id);

                return (
                  <li key={conversation.id}>
                    <button
                      type="button"
                      className={`internal-ai-chat__conversation-btn ${
                        isSelected ? 'internal-ai-chat__conversation-btn--selected' : ''
                      }`}
                      onClick={() => void openConversation(conversation.id)}
                      aria-pressed={isSelected}
                      aria-current={isSelected ? 'true' : undefined}
                    >
                      <span className="internal-ai-chat__conversation-head">
                        <span className="internal-ai-chat__conversation-title">
                          {buildConversationTitle(conversation)}
                        </span>
                        <span className="internal-ai-chat__conversation-time">
                          {buildConversationDateLabel(conversation)}
                        </span>
                      </span>
                      <span className="internal-ai-chat__conversation-preview">
                        {buildConversationPreview(conversation)}
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>

            {hasMoreConversations ? (
              <div className="internal-ai-chat__sidebar-footer">
                <button
                  type="button"
                  className="app-btn-secondary internal-ai-chat__small-btn"
                  onClick={() => void loadMoreConversations()}
                  disabled={conversationsLoadingMore}
                >
                  {conversationsLoadingMore ? 'Carregando...' : 'Carregar mais'}
                </button>
              </div>
            ) : null}

            <div className="internal-ai-chat__sidebar-status" aria-live="polite">
              <span className="internal-ai-chat__sidebar-status-text">
                {conversationsLoadingMore
                  ? 'Carregando mais conversas...'
                  : hasMoreConversations
                    ? 'Ha mais conversas disponiveis.'
                    : hasConversations
                      ? 'Fim da lista.'
                      : ''}
              </span>
              {totalConversations > 0 ? (
                <span className="internal-ai-chat__sidebar-status-text">{listCountLabel}</span>
              ) : null}
            </div>
          </aside>

          <div className="internal-ai-chat__panel">
            {!selectedConversationId ? (
              <div className="internal-ai-chat__panel-empty">
                <p className="internal-ai-chat__panel-empty-title">Selecione uma conversa</p>
                <p className="internal-ai-chat__panel-empty-description">
                  Escolha uma conversa na lista para visualizar o historico e continuar o atendimento.
                </p>
                {!hasConversations ? (
                  <button
                    type="button"
                    className="app-btn-primary"
                    onClick={() => void createConversation()}
                    disabled={createBusy}
                  >
                    {createBusy ? 'Criando...' : 'Criar primeira conversa'}
                  </button>
                ) : null}
              </div>
            ) : null}

            {selectedConversationId && detailLoading ? (
              <div className="internal-ai-chat__panel-loading" role="status" aria-live="polite">
                <SkeletonCard className="w-full" lines={4} />
              </div>
            ) : null}

            {selectedConversationId && !detailLoading && detailError ? (
              <div className="internal-ai-chat__panel-notice">
                <Notice tone="danger">{detailError}</Notice>
                <button
                  type="button"
                  className="app-btn-secondary internal-ai-chat__small-btn"
                  onClick={() => void reloadSelectedConversation()}
                >
                  Tentar novamente
                </button>
              </div>
            ) : null}

            {selectedConversationId && !detailLoading && !detailError && detail ? (
              <>
                <header className="internal-ai-chat__panel-header">
                  <div className="internal-ai-chat__panel-header-content">
                    <h2 className="internal-ai-chat__panel-title">{buildConversationTitle(detail)}</h2>
                    <p className="internal-ai-chat__panel-subtitle">
                      {(detail.messages ?? []).length} mensagens
                    </p>
                  </div>
                  <button
                    type="button"
                    className="app-btn-secondary internal-ai-chat__small-btn"
                    onClick={() => void reloadSelectedConversation()}
                    disabled={detailLoading || sendBusy}
                  >
                    Atualizar
                  </button>
                </header>

                <ul
                  ref={chatListRef}
                  className="internal-ai-chat__messages"
                  onScroll={handleChatScroll}
                  role="log"
                  aria-live="polite"
                  aria-busy={sendBusy}
                >
                  {hasOlderMessages ? (
                    <li className="internal-ai-chat__older-wrapper">
                      <button
                        type="button"
                        className="app-btn-secondary internal-ai-chat__small-btn"
                        onClick={() => void loadOlderMessages()}
                        disabled={messagesLoadingOlder}
                      >
                        {messagesLoadingOlder ? 'Carregando...' : 'Carregar mensagens anteriores'}
                      </button>
                    </li>
                  ) : null}

                  {!detail.messages?.length && !sendBusy ? (
                    <li className="internal-ai-chat__empty">
                      Conversa iniciada. Envie a primeira mensagem para a IA.
                    </li>
                  ) : null}

                  {(detail.messages ?? []).map((message) => {
                    const roleValue = String(message.role ?? '');
                    const mine =
                      roleValue === 'user' &&
                      Number(message.user_id ?? 0) === Number(user?.id ?? 0);
                    const bubbleClass =
                      roleValue === 'assistant'
                        ? 'internal-ai-chat__bubble--assistant'
                        : roleValue === 'system'
                          ? 'internal-ai-chat__bubble--system'
                          : mine
                            ? 'internal-ai-chat__bubble--mine'
                            : 'internal-ai-chat__bubble--user';

                    return (
                      <li
                        key={message.id}
                        className={`internal-ai-chat__bubble ${bubbleClass}`}
                      >
                        <span className="internal-ai-chat__sender">
                          {buildMessageSenderLabel(message, user?.id)}
                        </span>
                        <p className="internal-ai-chat__text">{message.content}</p>
                        <span className="internal-ai-chat__time">
                          {formatDateTime(message.created_at)}
                        </span>
                      </li>
                    );
                  })}

                  {sendBusy ? (
                    <li
                      className="internal-ai-chat__bubble internal-ai-chat__bubble--assistant internal-ai-chat__bubble--pending"
                    >
                      <span className="internal-ai-chat__sender">IA</span>
                      {streamingContent ? (
                        <p className="internal-ai-chat__text">
                          {streamingContent}
                          <span className="internal-ai-chat__cursor" aria-hidden="true">▌</span>
                        </p>
                      ) : (
                        <p className="internal-ai-chat__text internal-ai-chat__text--muted">
                          Gerando resposta...
                        </p>
                      )}
                    </li>
                  ) : null}
                </ul>

                <form
                  className="internal-ai-chat__composer"
                  onSubmit={(event) => {
                    event.preventDefault();
                    void sendMessage();
                  }}
                >
                  <label htmlFor={messageInputId} className="internal-ai-chat__sr-only">
                    Mensagem para IA
                  </label>
                  <textarea
                    id={messageInputId}
                    className="app-input internal-ai-chat__input"
                    rows={3}
                    value={draftMessage}
                    onChange={(event) => setDraftMessage(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        if (!sendBusy && String(draftMessage ?? '').trim()) {
                          void sendMessage();
                        }
                      }
                    }}
                    placeholder="Digite sua mensagem..."
                    disabled={sendBusy}
                    aria-invalid={Boolean(sendError)}
                    aria-describedby={sendError ? sendErrorId : undefined}
                  />

                  {sendError ? (
                    <p id={sendErrorId} className="internal-ai-chat__error" role="alert">
                      {sendError}
                    </p>
                  ) : null}

                  {sendBusy ? (
                    <p className="internal-ai-chat__sending-feedback" role="status">
                      {streamingContent ? 'Recebendo resposta...' : 'Aguardando resposta da IA...'}
                    </p>
                  ) : null}

                  <div className="internal-ai-chat__composer-actions">
                    <span className="internal-ai-chat__composer-hint">
                      As mensagens ficam registradas no historico desta conversa.
                    </span>
                    <button
                      type="submit"
                      className="app-btn-primary"
                      disabled={sendBusy || !String(draftMessage ?? '').trim()}
                    >
                      {sendBusy ? 'Enviando...' : 'Enviar'}
                    </button>
                  </div>
                </form>
              </>
            ) : null}
          </div>
        </section>
      </div>
    </Layout>
  );
}

export default InternalAiChatPage;
