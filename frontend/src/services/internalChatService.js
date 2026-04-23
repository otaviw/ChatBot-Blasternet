import { delete as remove, get, patch, post, put } from './apiClient';
import { applyParams, buildActionTemplates, buildCanonicalActionTemplates } from './internalChatRoutes';
import {
  normalizeConversation,
  normalizeMessage,
  normalizeUser,
  pickArray,
  pickObject,
  sortConversationsByActivity,
  sortMessagesChronologically,
  toPositiveInt,
} from './internalChatNormalizers';

const RETRYABLE_STATUSES = new Set([401, 403, 404, 405]);
const resolvedTemplateCache = new Map();

const METHOD_HANDLERS = {
  get: (url, { query, headers }) =>
    get(url, {
      params: query,
      headers,
    }),
  post: (url, { query, data, headers }) =>
    post(url, data, {
      params: query,
      headers,
    }),
  put: (url, { query, data, headers }) =>
    put(url, data, {
      params: query,
      headers,
    }),
  patch: (url, { query, data, headers }) =>
    patch(url, data, {
      params: query,
      headers,
    }),
  delete: (url, { query, data, headers }) =>
    remove(url, {
      params: query,
      data,
      headers,
    }),
};

const shouldRetryWithNextTemplate = (error) => {
  const status = Number(error?.response?.status ?? 0);
  return RETRYABLE_STATUSES.has(status);
};

const buildCacheKey = (role, action) => `${role}:${action}`;
const requestWithFallback = async ({
  role,
  action,
  method = 'get',
  params = {},
  query = undefined,
  data = undefined,
  headers = undefined,
}) => {
  const cacheKey = buildCacheKey(role, action);
  const cachedTemplate = resolvedTemplateCache.get(cacheKey);
  const canonicalTemplates = buildCanonicalActionTemplates(role, action);
  const actionTemplates = buildActionTemplates(role, action);
  const nonCanonicalTemplates = actionTemplates.filter(
    (template) => !canonicalTemplates.includes(template)
  );

  let templates = [...canonicalTemplates, ...nonCanonicalTemplates];
  if (cachedTemplate) {
    const cachedIsCanonical = canonicalTemplates.includes(cachedTemplate);
    if (cachedIsCanonical) {
      templates = [cachedTemplate, ...templates.filter((template) => template !== cachedTemplate)];
    } else if (templates.includes(cachedTemplate)) {
      templates = [
        ...canonicalTemplates,
        cachedTemplate,
        ...nonCanonicalTemplates.filter((template) => template !== cachedTemplate),
      ];
    }
  }

  let lastError = null;

  for (const template of templates) {
    const url = applyParams(template, params);
    const normalizedMethod = String(method ?? 'get').toLowerCase();
    const requestHandler = METHOD_HANDLERS[normalizedMethod];

    if (!requestHandler) {
      throw new Error(`Método HTTP não suportado: ${normalizedMethod}`);
    }

    try {
      const response = await requestHandler(url, { query, data, headers });
      resolvedTemplateCache.set(cacheKey, template);
      return response;
    } catch (error) {
      lastError = error;
      if (!shouldRetryWithNextTemplate(error)) {
        throw error;
      }
    }
  }

  if (lastError) {
    throw lastError;
  }

  throw new Error(`Nenhum endpoint de chat interno disponível para: ${action}`);
};

export async function listInternalChatConversations({
  role,
  search = '',
  page = 1,
  perPage = 25,
}) {
  const query = {};
  if (String(search).trim() !== '') {
    query.search = String(search).trim();
  }

  const normalizedPage = toPositiveInt(page);
  if (normalizedPage) {
    query.page = normalizedPage;
  }

  const normalizedPerPage = toPositiveInt(perPage);
  if (normalizedPerPage) {
    query.per_page = Math.min(normalizedPerPage, 100);
  }

  const response = await requestWithFallback({
    role,
    action: 'listConversations',
    method: 'get',
    query,
  });

  const payload = response.data ?? {};
  const rawConversations = pickArray(
    payload.conversations,
    payload.chat_conversations,
    payload.data?.conversations,
    Array.isArray(payload.data) ? payload.data : null
  );

  const conversations = sortConversationsByActivity(
    rawConversations.map(normalizeConversation).filter(Boolean)
  );

  return {
    conversations,
    pagination: payload.pagination ?? payload.conversations_pagination ?? payload.data?.pagination ?? null,
  };
}

export async function getInternalChatConversation({
  role,
  conversationId,
  messagesPage = null,
  messagesPerPage = null,
  messagesLimit = null,
}) {
  const id = toPositiveInt(conversationId);
  if (!id) {
    throw new Error('ID de conversa inválido.');
  }

  const normalizedMessagesPage = toPositiveInt(messagesPage);
  const normalizedMessagesPerPage = toPositiveInt(messagesPerPage);
  const normalizedMessagesLimit = toPositiveInt(messagesLimit);
  const query = {};

  if (normalizedMessagesPage) {
    query.messages_page = normalizedMessagesPage;
  }

  if (normalizedMessagesPerPage) {
    query.messages_per_page = Math.min(normalizedMessagesPerPage, 300);
  } else if (normalizedMessagesLimit) {
    query.messages_limit = Math.min(normalizedMessagesLimit, 300);
  }

  const response = await requestWithFallback({
    role,
    action: 'getConversation',
    method: 'get',
    params: { conversationId: id },
    query,
  });

  const payload = response.data ?? {};
  const rawConversation = pickObject(
    payload.conversation,
    payload.chat_conversation,
    payload.data?.conversation,
    payload.data
  );
  const rawMessages = pickArray(
    payload.messages,
    payload.chat_messages,
    payload.data?.messages,
    rawConversation?.messages
  );

  const conversation = normalizeConversation({
    ...(rawConversation ?? {}),
    messages: rawMessages,
  });

  if (!conversation) {
    throw new Error('Conversa não encontrada.');
  }

  conversation.messages = sortMessagesChronologically(conversation.messages ?? []);

  return {
    conversation,
    participants: conversation.participants ?? [],
    messagesPagination:
      payload.messages_pagination ?? payload.pagination ?? payload.data?.messages_pagination ?? null,
  };
}

export async function createInternalDirectConversation({ role, recipientId, initialText = '' }) {
  const id = toPositiveInt(recipientId);
  if (!id) {
    throw new Error('Destinatario inválido.');
  }

  const text = String(initialText ?? '').trim();
  const payload = {
    type: 'direct',
    recipient_id: id,
    recipientId: id,
    user_id: id,
    userId: id,
    participant_ids: [id],
    participantIds: [id],
    participants: [id],
    content: text || undefined,
    text: text || undefined,
  };

  const response = await requestWithFallback({
    role,
    action: 'createConversation',
    method: 'post',
    data: payload,
  });

  const body = response.data ?? {};
  const conversation = normalizeConversation(
    pickObject(body.conversation, body.chat_conversation, body.data?.conversation, body.data)
  );
  const message = normalizeMessage(
    pickObject(body.message, body.chat_message, body.data?.message, body.data?.chat_message)
  );

  return {
    conversation,
    message,
  };
}

export async function createInternalGroupConversation({ role, participantIds = [], initialText = '' }) {
  if (!Array.isArray(participantIds) || participantIds.length < 2) {
    throw new Error('Selecione pelo menos 2 participantes para criar um grupo.');
  }

  const ids = participantIds.map((id) => Number.parseInt(String(id), 10)).filter((id) => id > 0);
  const text = String(initialText ?? '').trim();

  const payload = {
    type: 'group',
    participant_ids: ids,
    participantIds: ids,
    participants: ids,
    content: text || undefined,
    text: text || undefined,
  };

  const response = await requestWithFallback({
    role,
    action: 'createConversation',
    method: 'post',
    data: payload,
  });

  const body = response.data ?? {};
  const conversation = normalizeConversation(
    pickObject(body.conversation, body.chat_conversation, body.data?.conversation, body.data)
  );
  const message = normalizeMessage(
    pickObject(body.message, body.chat_message, body.data?.message, body.data?.chat_message)
  );

  return { conversation, message };
}

export async function deleteInternalChatConversation({ role, conversationId }) {
  const id = toPositiveInt(conversationId);
  if (!id) {
    throw new Error('Conversa invalida.');
  }

  const response = await requestWithFallback({
    role,
    action: 'deleteConversation',
    method: 'delete',
    params: { conversationId: id },
    data: {
      conversation_id: id,
      conversationId: id,
    },
  });

  return response.data ?? { ok: false };
}

export async function updateInternalChatGroupName({ role, conversationId, name }) {
  const id = toPositiveInt(conversationId);
  if (!id) {
    throw new Error('Grupo inválido.');
  }

  const normalizedName = String(name ?? '').trim();
  if (!normalizedName) {
    throw new Error('Informe o nome do grupo.');
  }

  const response = await requestWithFallback({
    role,
    action: 'updateGroupName',
    method: 'patch',
    params: { conversationId: id },
    data: {
      name: normalizedName,
      group_name: normalizedName,
    },
  });

  const body = response.data ?? {};
  const conversation = normalizeConversation(
    pickObject(body.conversation, body.chat_conversation, body.data?.conversation, body.data)
  );

  return {
    conversation,
  };
}

export async function addInternalChatGroupParticipant({ role, conversationId, participantId }) {
  const id = toPositiveInt(conversationId);
  const userId = toPositiveInt(participantId);
  if (!id || !userId) {
    throw new Error('Grupo/participante inválido.');
  }

  const response = await requestWithFallback({
    role,
    action: 'addGroupParticipant',
    method: 'post',
    params: { conversationId: id },
    data: {
      participant_id: userId,
      participantId: userId,
      user_id: userId,
      userId: userId,
    },
  });

  const body = response.data ?? {};
  const conversation = normalizeConversation(
    pickObject(body.conversation, body.chat_conversation, body.data?.conversation, body.data)
  );

  return { conversation };
}

export async function removeInternalChatGroupParticipant({ role, conversationId, participantId }) {
  const id = toPositiveInt(conversationId);
  const userId = toPositiveInt(participantId);
  if (!id || !userId) {
    throw new Error('Grupo/participante inválido.');
  }

  const response = await requestWithFallback({
    role,
    action: 'removeGroupParticipant',
    method: 'delete',
    params: {
      conversationId: id,
      participantId: userId,
    },
  });

  const body = response.data ?? {};
  const conversation = normalizeConversation(
    pickObject(body.conversation, body.chat_conversation, body.data?.conversation, body.data)
  );

  return {
    conversation,
    removed_user_id: toPositiveInt(body.removed_user_id, body.removedUserId),
  };
}

export async function updateInternalChatGroupParticipantAdmin({
  role,
  conversationId,
  participantId,
  isAdmin,
}) {
  const id = toPositiveInt(conversationId);
  const userId = toPositiveInt(participantId);
  if (!id || !userId) {
    throw new Error('Grupo/participante inválido.');
  }

  const response = await requestWithFallback({
    role,
    action: 'updateGroupParticipantAdmin',
    method: 'patch',
    params: {
      conversationId: id,
      participantId: userId,
    },
    data: {
      is_admin: Boolean(isAdmin),
      isAdmin: Boolean(isAdmin),
    },
  });

  const body = response.data ?? {};
  const conversation = normalizeConversation(
    pickObject(body.conversation, body.chat_conversation, body.data?.conversation, body.data)
  );

  return { conversation };
}

export async function leaveInternalChatGroup({ role, conversationId, transferAdminTo = null }) {
  const id = toPositiveInt(conversationId);
  if (!id) {
    throw new Error('Grupo inválido.');
  }

  const transferId = toPositiveInt(transferAdminTo);
  const payload = {};
  if (transferId) {
    payload.transfer_admin_to = transferId;
    payload.transferAdminTo = transferId;
  }

  const response = await requestWithFallback({
    role,
    action: 'leaveGroup',
    method: 'post',
    params: { conversationId: id },
    data: payload,
  });

  return response.data ?? { ok: false };
}

export async function deleteInternalChatGroup({ role, conversationId }) {
  const id = toPositiveInt(conversationId);
  if (!id) {
    throw new Error('Grupo inválido.');
  }

  const response = await requestWithFallback({
    role,
    action: 'deleteGroup',
    method: 'delete',
    params: { conversationId: id },
    data: {
      conversation_id: id,
      conversationId: id,
    },
  });

  return response.data ?? { ok: false };
}

export async function toggleInternalChatReaction({ role, conversationId, messageId, emoji }) {
  const chatConversationId = toPositiveInt(conversationId);
  const chatMessageId = toPositiveInt(messageId);
  if (!chatConversationId || !chatMessageId) {
    throw new Error('IDs de conversa/mensagem inválidos.');
  }

  if (!emoji || !String(emoji).trim()) {
    throw new Error('Informe um emoji para reagir.');
  }

  const response = await requestWithFallback({
    role,
    action: 'toggleReaction',
    method: 'post',
    params: {
      conversationId: chatConversationId,
      messageId: chatMessageId,
    },
    data: {
      emoji: String(emoji).trim(),
    },
  });

  const body = response.data ?? {};
  const message = normalizeMessage(
    pickObject(body.message, body.chat_message, body.data?.message, body.data?.chat_message, body.data)
  );
  const conversation = normalizeConversation(
    pickObject(body.conversation, body.chat_conversation, body.data?.conversation)
  );

  return { message, conversation };
}

export async function sendInternalChatMessage({ role, conversationId, text = '', file = null }) {
  const id = toPositiveInt(conversationId);
  if (!id) {
    throw new Error('Conversa invalida.');
  }

  const content = String(text ?? '').trim();
  const hasFile = Boolean(file);

  if (!content && !hasFile) {
    throw new Error('Informe uma mensagem antes de enviar.');
  }

  let data;
  let headers;

  if (hasFile) {
    const form = new FormData();
    if (content) {
      form.append('content', content);
      form.append('text', content);
    }
    form.append('conversation_id', String(id));
    form.append('conversationId', String(id));
    form.append('type', String(file.type ?? '').startsWith('image/') ? 'image' : 'file');
    form.append('file', file);
    form.append('attachment', file);
    data = form;
    headers = { 'Content-Type': 'multipart/form-data' };
  } else {
    data = {
      content,
      text: content,
      type: 'text',
      conversation_id: id,
      conversationId: id,
    };
    headers = undefined;
  }

  const response = await requestWithFallback({
    role,
    action: 'sendMessage',
    method: 'post',
    params: { conversationId: id },
    data,
    headers,
  });

  const body = response.data ?? {};
  const message = normalizeMessage(
    pickObject(body.message, body.chat_message, body.data?.message, body.data?.chat_message, body.data)
  );
  const conversation = normalizeConversation(
    pickObject(body.conversation, body.chat_conversation, body.data?.conversation)
  );

  return {
    message,
    conversation,
  };
}

export async function editInternalChatMessage({ role, conversationId, messageId, text }) {
  const chatConversationId = toPositiveInt(conversationId);
  const chatMessageId = toPositiveInt(messageId);
  if (!chatConversationId || !chatMessageId) {
    throw new Error('IDs de conversa/mensagem inválidos.');
  }

  const content = String(text ?? '').trim();
  if (!content) {
    throw new Error('Informe o novo texto para salvar a edicao.');
  }

  const response = await requestWithFallback({
    role,
    action: 'updateMessage',
    method: 'patch',
    params: {
      conversationId: chatConversationId,
      messageId: chatMessageId,
    },
    data: {
      content,
      text: content,
      conversation_id: chatConversationId,
      conversationId: chatConversationId,
      message_id: chatMessageId,
      messageId: chatMessageId,
    },
  });

  const body = response.data ?? {};
  const message = normalizeMessage(
    pickObject(body.message, body.chat_message, body.data?.message, body.data?.chat_message, body.data)
  );
  const conversation = normalizeConversation(
    pickObject(body.conversation, body.chat_conversation, body.data?.conversation)
  );

  return {
    message,
    conversation,
  };
}

export async function deleteInternalChatMessage({ role, conversationId, messageId }) {
  const chatConversationId = toPositiveInt(conversationId);
  const chatMessageId = toPositiveInt(messageId);
  if (!chatConversationId || !chatMessageId) {
    throw new Error('IDs de conversa/mensagem inválidos.');
  }

  const response = await requestWithFallback({
    role,
    action: 'deleteMessage',
    method: 'delete',
    params: {
      conversationId: chatConversationId,
      messageId: chatMessageId,
    },
    data: {
      conversation_id: chatConversationId,
      conversationId: chatConversationId,
      message_id: chatMessageId,
      messageId: chatMessageId,
    },
  });

  const body = response.data ?? {};
  const message = normalizeMessage(
    pickObject(body.message, body.chat_message, body.data?.message, body.data?.chat_message, body.data)
  );
  const conversation = normalizeConversation(
    pickObject(body.conversation, body.chat_conversation, body.data?.conversation)
  );

  return {
    message,
    conversation,
  };
}

export async function listInternalChatRecipients({ role, excludeUserId = null }) {
  const response = await requestWithFallback({
    role,
    action: 'listRecipients',
    method: 'get',
  });

  const payload = response.data ?? {};
  const rawUsers = pickArray(
    payload.users,
    payload.recipients,
    payload.participants,
    payload.data?.users,
    payload.data?.recipients,
    Array.isArray(payload.data) ? payload.data : null
  );

  const excludedId = toPositiveInt(excludeUserId);
  const users = rawUsers
    .map(normalizeUser)
    .filter(Boolean)
    .filter((user) => user.is_active)
    .filter((user) => !excludedId || Number(user.id) !== excludedId)
    .sort((left, right) => left.name.localeCompare(right.name, 'pt-BR'));

  return { users };
}

export async function markInternalChatConversationRead({ role, conversationId }) {
  const id = toPositiveInt(conversationId);
  if (!id) {
    return false;
  }

  await requestWithFallback({
    role,
    action: 'markRead',
    method: 'post',
    params: { conversationId: id },
  });

  return true;
}

export {
  normalizeRealtimeInternalChatMessage,
  buildConversationTitle,
  buildConversationPreview,
  upsertConversationInList,
  appendUniqueChatMessage,
} from './internalChatNormalizers';
