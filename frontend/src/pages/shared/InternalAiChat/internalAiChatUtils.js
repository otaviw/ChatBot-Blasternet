import { sortInternalAiMessagesChronologically } from '@/services/internalAiChatService';

export const toStatusCode = (error) => Number(error?.response?.status ?? 0);

export const parseRequestErrorMessage = (
  error,
  {
    fallback422 = 'Não foi possível concluir a solicitacao.',
    fallback404 = 'Registro não encontrado.',
    fallbackUnexpected = 'Ocorreu uma falha inesperada. Tente novamente.',
  } = {},
) => {
  const status = toStatusCode(error);
  const apiMessage = String(error?.response?.data?.message ?? '').trim();

  if (status === 422) {
    return apiMessage || fallback422;
  }

  if (status === 404) {
    return apiMessage || fallback404;
  }

  return apiMessage || fallbackUnexpected;
};

export const mergeMessagesById = (olderMessages = [], newerMessages = []) => {
  const byId = new Map();

  [...olderMessages, ...newerMessages].forEach((message) => {
    const id = Number(message?.id ?? 0);
    if (id <= 0) {
      return;
    }

    const current = byId.get(id) ?? {};
    byId.set(id, {
      ...current,
      ...message,
      meta: message?.meta ?? current.meta ?? {},
    });
  });

  return sortInternalAiMessagesChronologically(Array.from(byId.values()));
};

export const canRequestMessageSend = ({ conversationId, sendBusy }) => {
  const normalizedConversationId = Number.parseInt(String(conversationId ?? ''), 10);
  if (!Number.isFinite(normalizedConversationId) || normalizedConversationId <= 0) {
    return false;
  }

  return !Boolean(sendBusy);
};
