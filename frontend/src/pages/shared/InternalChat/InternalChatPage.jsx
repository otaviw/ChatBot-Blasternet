import './InternalChatPage.css';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import InboxBackButton from '@/components/ui/InboxBackButton/InboxBackButton.jsx';
import { useNotificationsContext } from '@/contexts/NotificationsContext';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import realtimeClient from '@/services/realtimeClient';
import {
  appendUniqueChatMessage,
  buildConversationPreview,
  buildConversationTitle,
  createInternalDirectConversation,
  deleteInternalChatMessage,
  editInternalChatMessage,
  getInternalChatConversation,
  listInternalChatConversations,
  listInternalChatRecipients,
  markInternalChatConversationRead,
  normalizeRealtimeInternalChatMessage,
  sendInternalChatMessage,
  upsertConversationInList,
} from '@/services/internalChatService';

const POLL_CONVERSATIONS_MS = 20000;

const toTimestamp = (value) => {
  if (!value) {
    return 0;
  }

  const parsed = new Date(value).getTime();
  return Number.isFinite(parsed) ? parsed : 0;
};

const formatDateTime = (value) => {
  const timestamp = toTimestamp(value);
  if (!timestamp) {
    return '';
  }

  return new Date(timestamp).toLocaleString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const parseRoleFromUser = (user) => {
  const normalized = String(user?.role ?? '').trim().toLowerCase();
  return normalized === 'system_admin' ? 'admin' : 'company';
};

const parseErrorMessage = (error, fallbackText) =>
  String(error?.response?.data?.message ?? fallbackText);

function InternalChatPage() {
  const { data, loading, error } = usePageData('/me');
  const { logout } = useLogout();
  const { markReadByReference } = useNotificationsContext();
  const [conversationSearchInput, setConversationSearchInput] = useState('');
  const [conversationSearch, setConversationSearch] = useState('');
  const [conversations, setConversations] = useState([]);
  const [conversationsLoading, setConversationsLoading] = useState(false);
  const [conversationsError, setConversationsError] = useState('');
  const [selectedConversationId, setSelectedConversationId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [messageText, setMessageText] = useState('');
  const [messageFile, setMessageFile] = useState(null);
  const [messageFilePreviewUrl, setMessageFilePreviewUrl] = useState('');
  const [sendBusy, setSendBusy] = useState(false);
  const [sendError, setSendError] = useState('');
  const [messageActionBusyId, setMessageActionBusyId] = useState(null);
  const [messageActionError, setMessageActionError] = useState('');
  const [editingMessageId, setEditingMessageId] = useState(null);
  const [editingMessageText, setEditingMessageText] = useState('');
  const [openMessageOptionsId, setOpenMessageOptionsId] = useState(null);
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [recipients, setRecipients] = useState([]);
  const [recipientsLoading, setRecipientsLoading] = useState(false);
  const [recipientsError, setRecipientsError] = useState('');
  const [recipientSearch, setRecipientSearch] = useState('');
  const [selectedRecipientId, setSelectedRecipientId] = useState('');
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');
  const selectedConversationIdRef = useRef(null);
  const chatListRef = useRef(null);
  const shouldScrollToBottomRef = useRef(false);
  const wasChatNearBottomRef = useRef(true);
  const queryConversationHandledRef = useRef(false);
  const refreshTimerRef = useRef(null);
  const [sidebarVisibleOnMobile, setSidebarVisibleOnMobile] = useState(true);

  const user = data?.user ?? null;
  const role = useMemo(() => parseRoleFromUser(user), [user]);
  const currentUserId = Number.parseInt(String(user?.id ?? ''), 10) || null;
  const companyName = user?.company_name ?? '';

  const clearMessageFileState = useCallback(() => {
    if (messageFilePreviewUrl) {
      URL.revokeObjectURL(messageFilePreviewUrl);
    }
    setMessageFile(null);
    setMessageFilePreviewUrl('');
  }, [messageFilePreviewUrl]);

  useEffect(() => {
    return () => {
      if (messageFilePreviewUrl) {
        URL.revokeObjectURL(messageFilePreviewUrl);
      }
    };
  }, [messageFilePreviewUrl]);

  useEffect(() => {
    selectedConversationIdRef.current = selectedConversationId;
  }, [selectedConversationId]);

  useEffect(() => {
    const handlePointerDown = (event) => {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      if (target.closest('[data-chat-message-options="true"]')) {
        return;
      }

      setOpenMessageOptionsId(null);
    };

    const handleEscape = (event) => {
      if (event.key === 'Escape') {
        setOpenMessageOptionsId(null);
      }
    };

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleEscape);

    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleEscape);
    };
  }, []);

  const scheduleConversationsRefresh = useCallback(
    (callback) => {
      if (refreshTimerRef.current) {
        clearTimeout(refreshTimerRef.current);
      }

      refreshTimerRef.current = setTimeout(() => {
        refreshTimerRef.current = null;
        callback();
      }, 500);
    },
    []
  );

  const loadConversations = useCallback(
    async ({ silent = false } = {}) => {
      if (!data?.authenticated) {
        return;
      }

      if (!silent) {
        setConversationsLoading(true);
      }
      setConversationsError('');

      try {
        const response = await listInternalChatConversations({
          role,
          search: conversationSearch,
        });
        const incoming = response.conversations ?? [];

        setConversations((previous) => {
          const previousById = new Map(
            previous.map((item) => [Number(item.id), Number(item.unread_count ?? 0)])
          );

          return incoming.map((item) => {
            const previousUnread = previousById.get(Number(item.id)) ?? 0;
            return {
              ...item,
              unread_count: Math.max(Number(item.unread_count ?? 0), previousUnread),
            };
          });
        });
      } catch (requestError) {
        setConversationsError(
          parseErrorMessage(
            requestError,
            'Nao foi possivel carregar as conversas de chat interno.'
          )
        );
      } finally {
        if (!silent) {
          setConversationsLoading(false);
        }
      }
    },
    [conversationSearch, data?.authenticated, role]
  );

  const openConversation = useCallback(
    async (conversationId) => {
      const id = Number.parseInt(String(conversationId ?? ''), 10);
      if (!id || !data?.authenticated) {
        return;
      }

      const previousId = selectedConversationIdRef.current;
      if (previousId && previousId !== id) {
        realtimeClient.leaveChatConversation(previousId);
      }

      setSidebarVisibleOnMobile(false);
      setSelectedConversationId(id);
      setDetailLoading(true);
      setDetailError('');
      setSendError('');
      setMessageActionError('');
      setMessageActionBusyId(null);
      setEditingMessageId(null);
      setEditingMessageText('');
      setOpenMessageOptionsId(null);

      try {
        const response = await getInternalChatConversation({
          role,
          conversationId: id,
        });
        const conversation = response.conversation;

        if (!conversation) {
          throw new Error('Conversa nao encontrada.');
        }

        shouldScrollToBottomRef.current = true;
        wasChatNearBottomRef.current = true;
        setDetail(conversation);
        setConversations((previous) =>
          upsertConversationInList(previous, {
            ...conversation,
            unread_count: 0,
          })
        );

        await realtimeClient.joinChatConversation(id);

        try {
          await markInternalChatConversationRead({ role, conversationId: id });
        } catch (_markReadError) {
          // Marcar como lido e best effort.
        }

        try {
          await markReadByReference(
            NOTIFICATION_MODULE.INTERNAL_CHAT,
            NOTIFICATION_REFERENCE_TYPE.CHAT_CONVERSATION,
            id
          );
        } catch (_markReferenceReadError) {
          // Marcacao de notificacao e best effort.
        }
      } catch (requestError) {
        setDetail(null);
        setDetailError(parseErrorMessage(requestError, 'Nao foi possivel abrir a conversa.'));
      } finally {
        setDetailLoading(false);
      }
    },
    [data?.authenticated, markReadByReference, role]
  );

  const refreshSelectedConversation = useCallback(async () => {
    const id = selectedConversationIdRef.current;
    if (!id || !data?.authenticated) {
      return;
    }

    try {
      const response = await getInternalChatConversation({
        role,
        conversationId: id,
      });

      if (!response.conversation || Number(selectedConversationIdRef.current) !== Number(id)) {
        return;
      }

      setDetail(response.conversation);
      setConversations((previous) =>
        upsertConversationInList(previous, {
          ...response.conversation,
          unread_count: 0,
        })
      );
    } catch (_requestError) {
      // Refresh de detalhe e apenas para manter consistencia do estado local.
    }
  }, [data?.authenticated, role]);

  useEffect(() => {
    const handle = setTimeout(() => {
      setConversationSearch(conversationSearchInput.trim());
    }, 350);

    return () => clearTimeout(handle);
  }, [conversationSearchInput]);

  useEffect(() => {
    if (!data?.authenticated) {
      return undefined;
    }

    void loadConversations();
    const timer = setInterval(() => {
      void loadConversations({ silent: true });
    }, POLL_CONVERSATIONS_MS);

    return () => {
      clearInterval(timer);
    };
  }, [data?.authenticated, loadConversations]);

  useEffect(() => {
    if (!data?.authenticated) {
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
  }, [data?.authenticated, openConversation]);

  useEffect(() => {
    if (!data?.authenticated) {
      return undefined;
    }

    const applyRealtimeMessage = (envelope, { incrementUnread = false } = {}) => {
      const message = normalizeRealtimeInternalChatMessage(envelope?.payload);
      if (!message?.conversation_id) {
        return;
      }

      const conversationId = Number(message.conversation_id);
      const isCurrentConversation =
        Number(selectedConversationIdRef.current) === conversationId;
      const activityDate = message.updated_at ?? message.created_at ?? null;

      if (!isCurrentConversation) {
        setConversations((previous) => {
          const existingConversation = previous.find(
            (conversation) => Number(conversation.id) === conversationId
          );
          const previousUnread = Number(existingConversation?.unread_count ?? 0);
          const previousLastMessageId = Number(existingConversation?.last_message?.id ?? 0);
          const incomingMessageId = Number(message.id ?? 0);
          const shouldReplaceLastMessage =
            incrementUnread || !existingConversation || previousLastMessageId === incomingMessageId;

          return upsertConversationInList(previous, {
            id: conversationId,
            last_message: shouldReplaceLastMessage
              ? message
              : existingConversation?.last_message ?? null,
            last_message_at: shouldReplaceLastMessage
              ? activityDate
              : existingConversation?.last_message_at ?? activityDate,
            unread_count: incrementUnread ? previousUnread + 1 : previousUnread,
          });
        });

        scheduleConversationsRefresh(() => {
          void loadConversations({ silent: true });
        });
        return;
      }

      if (incrementUnread) {
        shouldScrollToBottomRef.current = true;
        wasChatNearBottomRef.current = true;
      }

      setDetail((previous) => {
        if (!previous || Number(previous.id) !== conversationId) {
          return previous;
        }

        const previousLastMessageId = Number(previous.last_message?.id ?? 0);
        const incomingMessageId = Number(message.id ?? 0);
        const shouldReplaceLastMessage =
          !previousLastMessageId || previousLastMessageId === incomingMessageId || incrementUnread;

        return {
          ...previous,
          messages: appendUniqueChatMessage(previous.messages ?? [], message),
          last_message: shouldReplaceLastMessage ? message : previous.last_message,
          last_message_at: shouldReplaceLastMessage
            ? activityDate ?? previous.last_message_at
            : previous.last_message_at,
          unread_count: 0,
        };
      });

      setConversations((previous) =>
        upsertConversationInList(previous, {
          id: conversationId,
          last_message: message,
          last_message_at: activityDate,
          unread_count: 0,
        })
      );

      if (incrementUnread) {
        void markInternalChatConversationRead({
          role,
          conversationId,
        }).catch(() => {});

        void markReadByReference(
          NOTIFICATION_MODULE.INTERNAL_CHAT,
          NOTIFICATION_REFERENCE_TYPE.CHAT_CONVERSATION,
          conversationId
        ).catch(() => {});
      }
    };

    const unsubscribeCreated = realtimeClient.on('message.created', (envelope) => {
      applyRealtimeMessage(envelope, { incrementUnread: true });
    });

    const unsubscribeUpdated = realtimeClient.on('message.updated', (envelope) => {
      applyRealtimeMessage(envelope, { incrementUnread: false });
    });

    return () => {
      unsubscribeCreated();
      unsubscribeUpdated();
      const selectedId = selectedConversationIdRef.current;
      if (selectedId) {
        realtimeClient.leaveChatConversation(selectedId);
      }
    };
  }, [data?.authenticated, loadConversations, markReadByReference, role, scheduleConversationsRefresh]);

  useEffect(() => {
    return () => {
      if (refreshTimerRef.current) {
        clearTimeout(refreshTimerRef.current);
        refreshTimerRef.current = null;
      }
    };
  }, []);

  useEffect(() => {
    const chatElement = chatListRef.current;
    if (!chatElement || !detail?.id) {
      return;
    }

    if (shouldScrollToBottomRef.current || wasChatNearBottomRef.current) {
      shouldScrollToBottomRef.current = false;
      requestAnimationFrame(() => {
        const element = chatListRef.current;
        if (!element) {
          return;
        }

        element.scrollTop = element.scrollHeight;
        wasChatNearBottomRef.current = true;
      });
    }
  }, [detail?.id, detail?.messages?.length]);

  const handleChatScroll = (event) => {
    const element = event.currentTarget;
    const remaining = element.scrollHeight - element.scrollTop - element.clientHeight;
    wasChatNearBottomRef.current = remaining <= 72;
  };

  const handleSendMessage = async (event) => {
    event.preventDefault();

    if (!selectedConversationId || sendBusy) {
      return;
    }

    setSendBusy(true);
    setSendError('');

    try {
      const response = await sendInternalChatMessage({
        role,
        conversationId: selectedConversationId,
        text: messageText,
        file: messageFile,
      });

      if (response.message) {
        shouldScrollToBottomRef.current = true;
        wasChatNearBottomRef.current = true;
        setDetail((previous) => {
          if (!previous || Number(previous.id) !== Number(selectedConversationId)) {
            return previous;
          }

          return {
            ...previous,
            messages: appendUniqueChatMessage(previous.messages ?? [], response.message),
            last_message: response.message,
            last_message_at: response.message.created_at ?? previous.last_message_at,
            unread_count: 0,
          };
        });
      }

      if (response.conversation) {
        setConversations((previous) =>
          upsertConversationInList(previous, {
            ...response.conversation,
            unread_count: 0,
          })
        );
      } else if (response.message) {
        setConversations((previous) =>
          upsertConversationInList(previous, {
            id: selectedConversationId,
            last_message: response.message,
            last_message_at: response.message.created_at,
            unread_count: 0,
          })
        );
      }

      setMessageText('');
      clearMessageFileState();

      try {
        await markInternalChatConversationRead({
          role,
          conversationId: selectedConversationId,
        });
      } catch (_markReadError) {
        // Melhor esforco.
      }

      scheduleConversationsRefresh(() => {
        void loadConversations({ silent: true });
      });
    } catch (requestError) {
      setSendError(parseErrorMessage(requestError, 'Nao foi possivel enviar a mensagem.'));
    } finally {
      setSendBusy(false);
    }
  };

  const handleMessageFileChange = (event) => {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }

    if (messageFilePreviewUrl) {
      URL.revokeObjectURL(messageFilePreviewUrl);
    }

    setMessageFile(file);
    setSendError('');
    if (String(file.type ?? '').startsWith('image/')) {
      setMessageFilePreviewUrl(URL.createObjectURL(file));
    } else {
      setMessageFilePreviewUrl('');
    }
  };

  const startMessageEditing = (message) => {
    const messageId = Number.parseInt(String(message?.id ?? ''), 10);
    if (!messageId) {
      return;
    }

    setEditingMessageId(messageId);
    setEditingMessageText(String(message?.content ?? ''));
    setMessageActionError('');
    setOpenMessageOptionsId(null);
  };

  const cancelMessageEditing = () => {
    setEditingMessageId(null);
    setEditingMessageText('');
    setMessageActionError('');
    setOpenMessageOptionsId(null);
  };

  const handleMessageEditSave = async (messageId) => {
    const conversationId = Number.parseInt(String(selectedConversationId ?? ''), 10);
    const id = Number.parseInt(String(messageId ?? ''), 10);
    if (!conversationId || !id) {
      return;
    }

    const editedText = editingMessageText.trim();
    if (!editedText) {
      setMessageActionError('Informe o novo texto antes de salvar a edicao.');
      return;
    }

    setMessageActionBusyId(id);
    setMessageActionError('');

    try {
      const response = await editInternalChatMessage({
        role,
        conversationId,
        messageId: id,
        text: editedText,
      });

      if (response.message) {
        setDetail((previous) => {
          if (!previous || Number(previous.id) !== conversationId) {
            return previous;
          }

          const updatedMessages = appendUniqueChatMessage(previous.messages ?? [], response.message);
          const previousLastMessageId = Number(previous.last_message?.id ?? 0);
          const incomingMessageId = Number(response.message.id ?? 0);
          const shouldUpdateLastMessage = previousLastMessageId === incomingMessageId;

          return {
            ...previous,
            messages: updatedMessages,
            last_message: shouldUpdateLastMessage ? response.message : previous.last_message,
            last_message_at: shouldUpdateLastMessage
              ? response.message.updated_at ?? response.message.created_at ?? previous.last_message_at
              : previous.last_message_at,
          };
        });
      }

      if (response.conversation) {
        setConversations((previous) => upsertConversationInList(previous, response.conversation));
      } else if (response.message) {
        setConversations((previous) =>
          upsertConversationInList(previous, {
            id: conversationId,
            last_message: response.message,
            last_message_at: response.message.updated_at ?? response.message.created_at,
          })
        );
      }

      setEditingMessageId(null);
      setEditingMessageText('');
      setOpenMessageOptionsId(null);
    } catch (requestError) {
      setMessageActionError(
        parseErrorMessage(requestError, 'Nao foi possivel editar a mensagem.')
      );
    } finally {
      setMessageActionBusyId(null);
    }
  };

  const handleMessageDelete = async (messageId) => {
    const conversationId = Number.parseInt(String(selectedConversationId ?? ''), 10);
    const id = Number.parseInt(String(messageId ?? ''), 10);
    if (!conversationId || !id) {
      return;
    }

    setOpenMessageOptionsId(null);

    const confirmed = window.confirm('Tem certeza que deseja apagar esta mensagem?');
    if (!confirmed) {
      return;
    }

    setMessageActionBusyId(id);
    setMessageActionError('');

    try {
      const response = await deleteInternalChatMessage({
        role,
        conversationId,
        messageId: id,
      });

      if (response.message) {
        setDetail((previous) => {
          if (!previous || Number(previous.id) !== conversationId) {
            return previous;
          }

          const updatedMessages = appendUniqueChatMessage(previous.messages ?? [], response.message);
          const previousLastMessageId = Number(previous.last_message?.id ?? 0);
          const incomingMessageId = Number(response.message.id ?? 0);
          const shouldUpdateLastMessage = previousLastMessageId === incomingMessageId;

          return {
            ...previous,
            messages: updatedMessages,
            last_message: shouldUpdateLastMessage ? response.message : previous.last_message,
            last_message_at: shouldUpdateLastMessage
              ? response.message.updated_at ?? response.message.created_at ?? previous.last_message_at
              : previous.last_message_at,
          };
        });
      }

      if (response.conversation) {
        setConversations((previous) => upsertConversationInList(previous, response.conversation));
      } else if (response.message) {
        setConversations((previous) =>
          upsertConversationInList(previous, {
            id: conversationId,
            last_message: response.message,
            last_message_at: response.message.updated_at ?? response.message.created_at,
          })
        );
      }

      if (Number(editingMessageId) === id) {
        cancelMessageEditing();
      }
    } catch (requestError) {
      setMessageActionError(
        parseErrorMessage(requestError, 'Nao foi possivel apagar a mensagem.')
      );
    } finally {
      setMessageActionBusyId(null);
    }
  };

  const openCreateModal = async () => {
    if (!data?.authenticated) {
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
          <h1 className="internal-chat-title">Chat interno</h1>
        </div>

        <div className="internal-chat-layout">
          <aside
            className={`internal-chat-sidebar ${
              sidebarVisibleOnMobile || !selectedConversationId ? 'internal-chat-sidebar--visible' : ''
            }`}
          >
            <div className="internal-chat-sidebar-top">
              <button
                type="button"
                className="app-btn-primary internal-chat-new-btn"
                onClick={() => void openCreateModal()}
              >
                Nova conversa
              </button>
              <input
                type="search"
                className="app-input internal-chat-search"
                placeholder="Buscar conversa..."
                value={conversationSearchInput}
                onChange={(event) => setConversationSearchInput(event.target.value)}
              />
            </div>

            <ul className="internal-chat-conversation-list">
              {conversationsLoading && !conversations.length ? (
                <li className="internal-chat-list-state">Carregando conversas...</li>
              ) : null}

              {!conversationsLoading && !conversations.length ? (
                <li className="internal-chat-list-state">Nenhuma conversa encontrada.</li>
              ) : null}

              {conversations.map((conversation) => {
                const active = Number(selectedConversationId) === Number(conversation.id);
                const unreadCount = Number(conversation.unread_count ?? 0);

                return (
                  <li key={conversation.id}>
                    <button
                      type="button"
                      className={`internal-chat-conversation-item ${
                        active ? 'internal-chat-conversation-item--active' : ''
                      }`}
                      onClick={() => void openConversation(conversation.id)}
                    >
                      <div className="internal-chat-conversation-row">
                        <span className="internal-chat-conversation-name">
                          {buildConversationTitle(conversation, currentUserId)}
                        </span>
                        <span className="internal-chat-conversation-time">
                          {formatDateTime(conversation.last_message_at)}
                        </span>
                      </div>
                      <div className="internal-chat-conversation-row">
                        <span className="internal-chat-conversation-preview">
                          {buildConversationPreview(conversation)}
                        </span>
                        {unreadCount > 0 ? (
                          <span className="internal-chat-unread-badge">
                            {unreadCount > 99 ? '99+' : unreadCount}
                          </span>
                        ) : null}
                      </div>
                    </button>
                  </li>
                );
              })}
            </ul>

            {conversationsError ? (
              <p className="internal-chat-error-box">{conversationsError}</p>
            ) : null}
          </aside>

          <section
            className={`internal-chat-main ${
              selectedConversationId && !sidebarVisibleOnMobile
                ? 'internal-chat-main--visible'
                : ''
            }`}
          >
            {selectedConversationId ? (
              <InboxBackButton
                onClick={() => {
                  setSidebarVisibleOnMobile(true);
                  setSelectedConversationId(null);
                  setDetail(null);
                  setDetailError('');
                  setSendError('');
                  setMessageActionError('');
                  setMessageActionBusyId(null);
                  setEditingMessageId(null);
                  setEditingMessageText('');
                  setOpenMessageOptionsId(null);
                  const id = selectedConversationIdRef.current;
                  if (id) {
                    realtimeClient.leaveChatConversation(id);
                  }
                }}
                className="internal-chat-back-btn lg:hidden"
                label="Voltar para conversas"
              />
            ) : null}

            {!selectedConversationId ? (
              <div className="internal-chat-empty-state">
                Selecione uma conversa para visualizar as mensagens.
              </div>
            ) : null}

            {selectedConversationId && detailLoading ? (
              <div className="internal-chat-empty-state">Carregando conversa...</div>
            ) : null}

            {selectedConversationId && detailError ? (
              <div className="internal-chat-error-box">{detailError}</div>
            ) : null}

            {selectedConversationId && selectedConversation && !detailLoading ? (
              <div className="internal-chat-detail">
                <header className="internal-chat-toolbar">
                  <div>
                    <h2 className="internal-chat-toolbar-title">{currentTitle}</h2>
                    <p className="internal-chat-toolbar-subtitle">
                      {currentParticipants
                        .map((participant) => participant.name)
                        .join(' | ') || 'Sem participantes carregados'}
                    </p>
                  </div>
                  <button
                    type="button"
                    className="app-btn-secondary"
                    onClick={() => void refreshSelectedConversation()}
                  >
                    Atualizar
                  </button>
                </header>

                <ul
                  ref={chatListRef}
                  onScroll={handleChatScroll}
                  className="internal-chat-message-list"
                >
                  {!selectedConversation.messages?.length ? (
                    <li className="internal-chat-list-state">
                      Sem mensagens nesta conversa ainda.
                    </li>
                  ) : null}

                  {(selectedConversation.messages ?? []).map((message) => {
                    const isMine =
                      currentUserId && Number(message.sender_id) === Number(currentUserId);
                    const isDeleted = Boolean(message.deleted_at || message.is_deleted);
                    const hasAttachments = !isDeleted && (message.attachments ?? []).length > 0;
                    const isEditing =
                      Number(editingMessageId ?? 0) > 0 &&
                      Number(editingMessageId) === Number(message.id);
                    const isMessageActionBusy =
                      Number(messageActionBusyId ?? 0) > 0 &&
                      Number(messageActionBusyId) === Number(message.id);

                    return (
                      <li
                        key={`${message.id ?? 'local'}-${message.created_at ?? ''}-${message.sender_id ?? ''}`}
                        className={`internal-chat-message ${
                          isMine ? 'internal-chat-message--mine' : 'internal-chat-message--other'
                        }`}
                      >
                        <span className="internal-chat-message-label">
                          {message.sender_name}
                          {isMine ? ' (voce)' : ''}
                          {isDeleted ? ' (apagada)' : message.edited_at ? ' (editada)' : ''}
                        </span>

                        {isEditing ? (
                          <div className="internal-chat-message-edit">
                            <textarea
                              className="app-input internal-chat-message-edit-textarea"
                              rows={2}
                              value={editingMessageText}
                              onChange={(event) => setEditingMessageText(event.target.value)}
                            />
                            <div className="internal-chat-message-edit-actions">
                              <button
                                type="button"
                                className="app-btn-secondary"
                                disabled={isMessageActionBusy}
                                onClick={cancelMessageEditing}
                              >
                                Cancelar
                              </button>
                              <button
                                type="button"
                                className="app-btn-primary"
                                disabled={isMessageActionBusy || !editingMessageText.trim()}
                                onClick={() => void handleMessageEditSave(message.id)}
                              >
                                {isMessageActionBusy ? 'Salvando...' : 'Salvar'}
                              </button>
                            </div>
                          </div>
                        ) : message.content ? (
                          <p
                            className={`internal-chat-message-content ${
                              isDeleted ? 'internal-chat-message-content--deleted' : ''
                            }`}
                          >
                            {message.content}
                          </p>
                        ) : null}

                        {hasAttachments ? (
                          <div className="internal-chat-attachments">
                            {message.attachments.map((attachment) => (
                              <a
                                key={attachment.id ?? `${attachment.url}-${attachment.original_name}`}
                                href={attachment.url || '#'}
                                target={attachment.url ? '_blank' : undefined}
                                rel={attachment.url ? 'noreferrer' : undefined}
                                className="internal-chat-attachment-link"
                                onClick={(event) => {
                                  if (!attachment.url) {
                                    event.preventDefault();
                                  }
                                }}
                              >
                                {attachment.original_name || attachment.url || 'Anexo'}
                              </a>
                            ))}
                          </div>
                        ) : null}

                        <div className="internal-chat-message-footer">
                          <span className="internal-chat-message-time">
                            {formatDateTime(message.created_at)}
                          </span>

                          {isMine && !isDeleted && !isEditing ? (
                            <div
                              className="internal-chat-message-options"
                              data-chat-message-options="true"
                            >
                              <button
                                type="button"
                                className="internal-chat-message-options-trigger"
                                disabled={isMessageActionBusy}
                                onClick={() =>
                                  setOpenMessageOptionsId((previous) =>
                                    Number(previous) === Number(message.id) ? null : Number(message.id)
                                  )
                                }
                                aria-label="Opcoes da mensagem"
                                title="Opcoes"
                              >
                                <span
                                  aria-hidden="true"
                                  className="internal-chat-message-options-icon"
                                />
                              </button>

                              {Number(openMessageOptionsId) === Number(message.id) ? (
                                <div className="internal-chat-message-options-popover">
                                  <button
                                    type="button"
                                    className="internal-chat-message-options-item"
                                    disabled={isMessageActionBusy}
                                    onClick={() => startMessageEditing(message)}
                                  >
                                    Editar
                                  </button>
                                  <button
                                    type="button"
                                    className="internal-chat-message-options-item internal-chat-message-options-item--danger"
                                    disabled={isMessageActionBusy}
                                    onClick={() => void handleMessageDelete(message.id)}
                                  >
                                    {isMessageActionBusy ? 'Apagando...' : 'Apagar'}
                                  </button>
                                </div>
                              ) : null}
                            </div>
                          ) : null}
                        </div>
                      </li>
                    );
                  })}
                </ul>

                {messageActionError ? (
                  <p className="internal-chat-error-inline internal-chat-message-action-error">
                    {messageActionError}
                  </p>
                ) : null}

                <form className="internal-chat-composer" onSubmit={handleSendMessage}>
                  <div className="internal-chat-composer-actions">
                    <label className="app-btn-secondary internal-chat-file-btn">
                      Anexar arquivo
                      <input
                        type="file"
                        className="hidden"
                        onChange={handleMessageFileChange}
                      />
                    </label>
                    {messageFile ? (
                      <button
                        type="button"
                        className="app-btn-danger"
                        onClick={clearMessageFileState}
                      >
                        Remover arquivo
                      </button>
                    ) : null}
                  </div>

                  {messageFile ? (
                    <div className="internal-chat-file-pill">
                      <span>{messageFile.name}</span>
                    </div>
                  ) : null}

                  {messageFilePreviewUrl ? (
                    <div className="internal-chat-image-preview">
                      <img src={messageFilePreviewUrl} alt="Previa do anexo" />
                    </div>
                  ) : null}

                  <div className="internal-chat-composer-row">
                    <textarea
                      className="app-input internal-chat-textarea"
                      value={messageText}
                      onChange={(event) => setMessageText(event.target.value)}
                      placeholder="Digite sua mensagem interna..."
                      rows={2}
                    />
                    <button
                      type="submit"
                      className="app-btn-primary internal-chat-send-btn"
                      disabled={sendBusy || (!messageText.trim() && !messageFile)}
                    >
                      {sendBusy ? 'Enviando...' : 'Enviar'}
                    </button>
                  </div>

                  {sendError ? <p className="internal-chat-error-inline">{sendError}</p> : null}
                </form>
              </div>
            ) : null}
          </section>
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

