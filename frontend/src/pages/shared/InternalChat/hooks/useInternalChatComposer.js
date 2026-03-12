import { useCallback, useEffect, useState } from 'react';
import {
  appendUniqueChatMessage,
  deleteInternalChatMessage,
  editInternalChatMessage,
  markInternalChatConversationRead,
  sendInternalChatMessage,
  toggleInternalChatReaction,
  upsertConversationInList,
} from '@/services/internalChatService';
import { parseErrorMessage } from '../internalChatUtils';

export default function useInternalChatComposer({
  loadConversations,
  role,
  scheduleConversationsRefresh,
  selectedConversationId,
  setConversations,
  setDetail,
  shouldScrollToBottomRef,
  wasChatNearBottomRef,
}) {
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

  const handleSendMessage = useCallback(
    async (event) => {
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
        }

        scheduleConversationsRefresh(() => {
          void loadConversations({ silent: true });
        });
      } catch (requestError) {
        setSendError(parseErrorMessage(requestError, 'Nao foi possivel enviar a mensagem.'));
      } finally {
        setSendBusy(false);
      }
    },
    [
      clearMessageFileState,
      loadConversations,
      messageFile,
      messageText,
      role,
      scheduleConversationsRefresh,
      selectedConversationId,
      sendBusy,
      setConversations,
      setDetail,
      shouldScrollToBottomRef,
      wasChatNearBottomRef,
    ]
  );

  const handleMessageFileChange = useCallback(
    (event) => {
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
    },
    [messageFilePreviewUrl]
  );

  const startMessageEditing = useCallback((message) => {
    const messageId = Number.parseInt(String(message?.id ?? ''), 10);
    if (!messageId) {
      return;
    }

    setEditingMessageId(messageId);
    setEditingMessageText(String(message?.content ?? ''));
    setMessageActionError('');
    setOpenMessageOptionsId(null);
  }, []);

  const cancelMessageEditing = useCallback(() => {
    setEditingMessageId(null);
    setEditingMessageText('');
    setMessageActionError('');
    setOpenMessageOptionsId(null);
  }, []);

  const handleMessageEditSave = useCallback(
    async (messageId) => {
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
    },
    [editingMessageText, role, selectedConversationId, setConversations, setDetail]
  );

  const handleMessageDelete = useCallback(
    async (messageId) => {
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
    },
    [cancelMessageEditing, editingMessageId, role, selectedConversationId, setConversations, setDetail]
  );

  const handleToggleReaction = useCallback(
    async (messageId, emoji) => {
      const conversationId = Number.parseInt(String(selectedConversationId ?? ''), 10);
      const id = Number.parseInt(String(messageId ?? ''), 10);
      if (!conversationId || !id || !emoji) {
        return;
      }

      try {
        const response = await toggleInternalChatReaction({
          role,
          conversationId,
          messageId: id,
          emoji,
        });

        if (response.message) {
          setDetail((previous) => {
            if (!previous || Number(previous.id) !== conversationId) {
              return previous;
            }

            return {
              ...previous,
              messages: appendUniqueChatMessage(previous.messages ?? [], response.message),
            };
          });
        }

        if (response.conversation) {
          setConversations((previous) => upsertConversationInList(previous, response.conversation));
        }
      } catch (_error) {
      }
    },
    [role, selectedConversationId, setConversations, setDetail]
  );

  const resetMessageInteractionState = useCallback(() => {
    setSendError('');
    setMessageActionError('');
    setMessageActionBusyId(null);
    setEditingMessageId(null);
    setEditingMessageText('');
    setOpenMessageOptionsId(null);
  }, []);

  return {
    handleToggleReaction,
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
  };
}
