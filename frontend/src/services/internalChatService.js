import api from './api';

const PREFIXES_BY_ROLE = Object.freeze({
  admin: ['/admin', '', '/minha-conta'],
  company: ['/minha-conta', '', '/admin'],
});

// Canonical API contract: exactly one route template per action.
const CANONICAL_ACTION_TEMPLATES = Object.freeze({
  listConversations: '/chat/conversations',
  getConversation: '/chat/conversations/:conversationId',
  createConversation: '/chat/conversations',
  sendMessage: '/chat/conversations/:conversationId/messages',
  updateMessage: '/chat/conversations/:conversationId/messages/:messageId',
  deleteMessage: '/chat/conversations/:conversationId/messages/:messageId',
  markRead: '/chat/conversations/:conversationId/read',
  toggleReaction: '/chat/conversations/:conversationId/messages/:messageId/reactions',
  listRecipients: '/chat/users',
});

// Temporary compatibility switch for legacy aliases.
// Phase-out plan:
// 1) enabled (default): canonical first + deprecated aliases as fallback.
// 2) disabled: canonical only.
const LEGACY_ENDPOINT_COMPAT_ENABLED = !new Set(['0', 'false', 'off', 'no']).has(
  String(import.meta.env.VITE_INTERNAL_CHAT_ENABLE_LEGACY_ENDPOINTS ?? '0')
    .trim()
    .toLowerCase()
);

// Deprecated aliases kept temporarily for migration safety.
// Remove these aliases after backend rollout is fully completed.
const LEGACY_ACTION_ALIASES = Object.freeze({
  // DEPRECATED alias for listConversations
  listConversations: ['/chat/conversas'],
  // DEPRECATED alias for getConversation
  getConversation: ['/chat/conversas/:conversationId'],
  // DEPRECATED alias for createConversation
  createConversation: ['/chat/conversas'],
  // DEPRECATED aliases for sendMessage
  sendMessage: ['/chat/conversas/:conversationId/mensagens', '/chat/messages', '/chat/mensagens'],
  // DEPRECATED aliases for updateMessage
  updateMessage: [
    '/chat/conversas/:conversationId/mensagens/:messageId',
    '/chat/messages/:messageId',
    '/chat/mensagens/:messageId',
  ],
  // DEPRECATED aliases for deleteMessage
  deleteMessage: [
    '/chat/conversas/:conversationId/mensagens/:messageId',
    '/chat/messages/:messageId',
    '/chat/mensagens/:messageId',
  ],
  // DEPRECATED aliases for markRead
  markRead: [
    '/chat/conversas/:conversationId/lido',
    '/chat/conversations/:conversationId/mark-read',
    '/chat/conversas/:conversationId/marcar-lido',
  ],
  toggleReaction: [],
  // DEPRECATED aliases for listRecipients
  listRecipients: ['/chat/usuarios', '/chat/recipients', '/chat/destinatarios'],
});

// DEPRECATED role-specific recipient fallback paths.
const LEGACY_RECIPIENT_FALLBACKS_BY_ROLE = Object.freeze({
  admin: ['/admin/users'],
  company: ['/minha-conta/users', '/admin/users'],
});

const RETRYABLE_STATUSES = new Set([401, 403, 404, 405]);
const resolvedTemplateCache = new Map();

const toInteger = (...values) => {
  for (const value of values) {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return null;
};

const toPositiveInt = (...values) => {
  for (const value of values) {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    if (parsed > 0) {
      return parsed;
    }
  }

  return null;
};

const toTimestamp = (value) => {
  if (!value) {
    return 0;
  }

  const parsed = new Date(value).getTime();
  return Number.isFinite(parsed) ? parsed : 0;
};

const unique = (items) => [...new Set(items)];

const normalizeUrl = (value) => {
  const raw = String(value ?? '').trim();
  if (!raw) {
    return '';
  }

  if (
    raw.startsWith('http://') ||
    raw.startsWith('https://') ||
    raw.startsWith('//') ||
    raw.startsWith('blob:') ||
    raw.startsWith('data:')
  ) {
    return raw;
  }

  return raw.startsWith('/') ? raw : `/${raw}`;
};

const withPrefix = (prefix, template) => {
  if (template.startsWith('/admin/') || template.startsWith('/minha-conta/')) {
    return template;
  }

  if (!prefix) {
    return template;
  }

  return `${prefix}${template}`;
};

const applyParams = (template, params = {}) => {
  let value = template;

  Object.entries(params).forEach(([key, rawValue]) => {
    value = value.replaceAll(`:${key}`, encodeURIComponent(String(rawValue)));
  });

  return value;
};

const buildExpandedTemplates = (role, templates) => {
  const prefixes = PREFIXES_BY_ROLE[role] ?? PREFIXES_BY_ROLE.company;
  return prefixes.flatMap((prefix) => templates.map((template) => withPrefix(prefix, template)));
};

const buildCanonicalActionTemplates = (role, action) => {
  const canonicalTemplate = CANONICAL_ACTION_TEMPLATES[action];
  if (!canonicalTemplate) {
    return [];
  }

  return unique(buildExpandedTemplates(role, [canonicalTemplate]));
};

const buildActionTemplates = (role, action) => {
  const canonicalTemplates = buildCanonicalActionTemplates(role, action);
  if (canonicalTemplates.length === 0) {
    return [];
  }

  if (!LEGACY_ENDPOINT_COMPAT_ENABLED) {
    return canonicalTemplates;
  }

  const legacyAliases = LEGACY_ACTION_ALIASES[action] ?? [];
  const legacyTemplates = buildExpandedTemplates(role, legacyAliases);

  if (action === 'listRecipients') {
    return unique([
      ...canonicalTemplates,
      ...legacyTemplates,
      ...(LEGACY_RECIPIENT_FALLBACKS_BY_ROLE[role] ?? []),
    ]);
  }

  return unique([...canonicalTemplates, ...legacyTemplates]);
};

const shouldRetryWithNextTemplate = (error) => {
  const status = Number(error?.response?.status ?? 0);
  return RETRYABLE_STATUSES.has(status);
};

const pickArray = (...values) => {
  for (const value of values) {
    if (Array.isArray(value)) {
      return value;
    }
  }

  return [];
};

const pickObject = (...values) => {
  for (const value of values) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      return value;
    }
  }

  return null;
};

const normalizeUser = (raw) => {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const id = toPositiveInt(raw.id, raw.user_id, raw.userId);
  if (!id) {
    return null;
  }

  return {
    id,
    name: String(raw.name ?? raw.full_name ?? `Usuario #${id}`),
    email: raw.email ? String(raw.email) : '',
    role: raw.role ? String(raw.role) : '',
    company_id: toInteger(raw.company_id, raw.companyId),
    is_active: raw.is_active == null ? true : Boolean(raw.is_active),
  };
};

const normalizeAttachment = (raw) => {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const id = toPositiveInt(raw.id, raw.attachment_id, raw.attachmentId);
  const fallbackPublicUrl = normalizeUrl(raw.public_url ?? raw.publicUrl ?? raw.url);
  const mediaUrl = normalizeUrl(
    raw.media_url ??
      raw.mediaUrl ??
      (id ? `/api/chat/attachments/${id}/media` : '')
  );
  const resolvedUrl = mediaUrl || fallbackPublicUrl;

  return {
    id,
    url: resolvedUrl,
    media_url: mediaUrl || resolvedUrl,
    public_url: fallbackPublicUrl,
    mime_type: raw.mime_type ? String(raw.mime_type) : '',
    size_bytes: toInteger(raw.size_bytes, raw.sizeBytes),
    original_name: raw.original_name ? String(raw.original_name) : '',
  };
};

const normalizeMessage = (raw) => {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const id = toPositiveInt(raw.id, raw.message_id, raw.messageId);
  const senderId = toPositiveInt(raw.sender_id, raw.senderId, raw.sender?.id, raw.user_id, raw.userId);
  const conversationId = toPositiveInt(raw.conversation_id, raw.conversationId);
  const attachments = pickArray(raw.attachments, raw.files).map(normalizeAttachment).filter(Boolean);
  const sender = normalizeUser(raw.sender);
  const normalizedSenderId = sender?.id ?? senderId;
  const type = String(raw.type ?? (attachments.length > 0 ? 'file' : 'text'));
  const deletedAt = raw.deleted_at ?? raw.deletedAt ?? null;
  const isDeleted = Boolean(raw.is_deleted ?? raw.isDeleted ?? deletedAt);
  const content = String(raw.content ?? raw.text ?? '');

  const metadata = raw.metadata && typeof raw.metadata === 'object' ? raw.metadata : {};
  const reactions = raw.reactions && typeof raw.reactions === 'object' && !Array.isArray(raw.reactions)
    ? raw.reactions
    : (metadata.reactions && typeof metadata.reactions === 'object' ? metadata.reactions : {});

  return {
    id,
    conversation_id: conversationId,
    sender_id: normalizedSenderId,
    sender_name: String(raw.sender_name ?? sender?.name ?? raw.user_name ?? 'Usuario'),
    type,
    content,
    metadata,
    reactions,
    read_by_count: toInteger(raw.read_by_count, raw.readByCount) ?? 0,
    participant_count: toInteger(raw.participant_count, raw.participantCount) ?? 0,
    attachments: isDeleted ? [] : attachments,
    created_at: raw.created_at ?? raw.createdAt ?? null,
    updated_at: raw.updated_at ?? raw.updatedAt ?? null,
    edited_at: raw.edited_at ?? raw.editedAt ?? null,
    deleted_at: deletedAt,
    is_deleted: isDeleted,
    sender,
  };
};

const normalizeConversation = (raw) => {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const id = toPositiveInt(raw.id, raw.conversation_id, raw.conversationId);
  if (!id) {
    return null;
  }

  const participants = pickArray(raw.participants, raw.users, raw.members)
    .map(normalizeUser)
    .filter(Boolean);

  const lastMessageRaw = pickObject(raw.last_message, raw.lastMessage, raw.latest_message, raw.latestMessage);
  const lastMessage = normalizeMessage(lastMessageRaw);
  const messages = pickArray(raw.messages, raw.chat_messages).map(normalizeMessage).filter(Boolean);

  return {
    id,
    type: String(raw.type ?? 'direct'),
    created_by: toInteger(raw.created_by, raw.createdBy),
    company_id: toInteger(raw.company_id, raw.companyId),
    created_at: raw.created_at ?? raw.createdAt ?? null,
    updated_at: raw.updated_at ?? raw.updatedAt ?? null,
    unread_count: Math.max(0, toInteger(raw.unread_count, raw.unreadCount) ?? 0),
    participants,
    messages,
    last_message: lastMessage,
    last_message_at:
      raw.last_message_at ??
      raw.lastMessageAt ??
      lastMessage?.created_at ??
      raw.updated_at ??
      raw.created_at ??
      null,
  };
};

const sortConversationsByActivity = (items) =>
  [...items].sort((left, right) => {
    const leftTimestamp = Math.max(
      toTimestamp(left?.last_message_at),
      toTimestamp(left?.updated_at),
      toTimestamp(left?.created_at)
    );
    const rightTimestamp = Math.max(
      toTimestamp(right?.last_message_at),
      toTimestamp(right?.updated_at),
      toTimestamp(right?.created_at)
    );

    if (rightTimestamp !== leftTimestamp) {
      return rightTimestamp - leftTimestamp;
    }

    return Number(right?.id ?? 0) - Number(left?.id ?? 0);
  });

const sortMessagesChronologically = (items) =>
  [...items].sort((left, right) => {
    const leftTimestamp = toTimestamp(left?.created_at);
    const rightTimestamp = toTimestamp(right?.created_at);

    if (leftTimestamp !== rightTimestamp) {
      return leftTimestamp - rightTimestamp;
    }

    return Number(left?.id ?? 0) - Number(right?.id ?? 0);
  });

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

    try {
      const response = await api.request({
        method,
        url,
        params: query,
        data,
        headers,
      });
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

  throw new Error(`Nenhum endpoint de chat interno disponivel para: ${action}`);
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
    throw new Error('ID de conversa invalido.');
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
    throw new Error('Conversa nao encontrada.');
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
    throw new Error('Destinatario invalido.');
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

export async function toggleInternalChatReaction({ role, conversationId, messageId, emoji }) {
  const chatConversationId = toPositiveInt(conversationId);
  const chatMessageId = toPositiveInt(messageId);
  if (!chatConversationId || !chatMessageId) {
    throw new Error('IDs de conversa/mensagem invalidos.');
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
    throw new Error('IDs de conversa/mensagem invalidos.');
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
    throw new Error('IDs de conversa/mensagem invalidos.');
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

export function normalizeRealtimeInternalChatMessage(payload) {
  if (!payload || typeof payload !== 'object') {
    return null;
  }

  const conversationId = toPositiveInt(payload.conversation_id, payload.conversationId);
  if (!conversationId) {
    return null;
  }

  const senderId = toPositiveInt(payload.sender_id, payload.senderId, payload.sender?.id);
  const hasInternalSignature =
    senderId !== null ||
    payload.content !== undefined ||
    Array.isArray(payload.attachments) ||
    payload.sender_name !== undefined;
  const looksLikeInboxPayload =
    payload.direction !== undefined ||
    payload.contentType !== undefined ||
    payload.content_type !== undefined;

  if (!hasInternalSignature || looksLikeInboxPayload) {
    return null;
  }

  const message = normalizeMessage({
    ...payload,
    conversation_id: conversationId,
    sender_id: senderId ?? undefined,
  });

  if (!message) {
    return null;
  }

  return message;
}

export function buildConversationTitle(conversation, currentUserId) {
  const participants = conversation?.participants ?? [];
  if (!participants.length) {
    return `Conversa #${conversation?.id ?? '-'}`;
  }

  const others = participants.filter((participant) => Number(participant.id) !== Number(currentUserId));
  const preferred = others.length > 0 ? others : participants;

  if (preferred.length === 1) {
    return preferred[0].name;
  }

  return preferred.map((participant) => participant.name).join(', ');
}

export function buildConversationPreview(conversation) {
  const lastMessage = conversation?.last_message ?? conversation?.messages?.at(-1) ?? null;
  const preview = lastMessage?.is_deleted ? 'Mensagem apagada' : lastMessage?.content ?? '';

  if (!preview) {
    return 'Sem mensagens ainda.';
  }

  return preview.length > 92 ? `${preview.slice(0, 92)}...` : preview;
}

export function upsertConversationInList(list, incoming) {
  if (!incoming?.id) {
    return list;
  }

  let found = false;

  const next = (list ?? []).map((item) => {
    if (Number(item.id) !== Number(incoming.id)) {
      return item;
    }

    found = true;
    return {
      ...item,
      ...incoming,
      participants: incoming.participants?.length ? incoming.participants : item.participants,
      last_message: incoming.last_message ?? item.last_message,
      unread_count: incoming.unread_count ?? item.unread_count ?? 0,
    };
  });

  return sortConversationsByActivity(found ? next : [incoming, ...next]);
}

export function appendUniqueChatMessage(messages, incoming) {
  if (!incoming) {
    return messages ?? [];
  }

  const incomingId = toPositiveInt(incoming.id);
  if (!incomingId) {
    return sortMessagesChronologically([...(messages ?? []), incoming]);
  }

  const existing = messages ?? [];
  const index = existing.findIndex((message) => Number(message.id) === incomingId);
  if (index >= 0) {
    const next = [...existing];
    next[index] = {
      ...existing[index],
      ...incoming,
      attachments: incoming.attachments ?? existing[index].attachments ?? [],
      metadata: incoming.metadata ?? existing[index].metadata ?? {},
    };
    return sortMessagesChronologically(next);
  }

  return sortMessagesChronologically([...existing, incoming]);
}
