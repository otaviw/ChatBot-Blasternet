const toInteger = (...values) => {
  for (const value of values) {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return null;
};

export const toPositiveInt = (...values) => {
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

export const pickArray = (...values) => {
  for (const value of values) {
    if (Array.isArray(value)) {
      return value;
    }
  }

  return [];
};

export const pickObject = (...values) => {
  for (const value of values) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      return value;
    }
  }

  return null;
};

export const normalizeUser = (raw) => {
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
    is_admin: Boolean(raw.is_admin ?? raw.isAdmin ?? raw.pivot?.is_admin ?? false),
    joined_at: raw.joined_at ?? raw.joinedAt ?? raw.pivot?.joined_at ?? null,
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

export const normalizeMessage = (raw) => {
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

export const normalizeConversation = (raw) => {
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
    name: raw.name ? String(raw.name) : '',
    created_by: toInteger(raw.created_by, raw.createdBy),
    company_id: toInteger(raw.company_id, raw.companyId),
    created_at: raw.created_at ?? raw.createdAt ?? null,
    updated_at: raw.updated_at ?? raw.updatedAt ?? null,
    deleted_at: raw.deleted_at ?? raw.deletedAt ?? null,
    unread_count: Math.max(0, toInteger(raw.unread_count, raw.unreadCount) ?? 0),
    participant_count: Math.max(
      0,
      toInteger(raw.participant_count, raw.participantCount) ?? participants.length
    ),
    current_user_is_admin: Boolean(raw.current_user_is_admin ?? raw.currentUserIsAdmin ?? false),
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

export const sortConversationsByActivity = (items) =>
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

export const sortMessagesChronologically = (items) =>
  [...items].sort((left, right) => {
    const leftTimestamp = toTimestamp(left?.created_at);
    const rightTimestamp = toTimestamp(right?.created_at);

    if (leftTimestamp !== rightTimestamp) {
      return leftTimestamp - rightTimestamp;
    }

    return Number(left?.id ?? 0) - Number(right?.id ?? 0);
  });

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
  const conversationType = String(conversation?.type ?? '');
  const groupName = String(conversation?.name ?? '').trim();
  if (conversationType === 'group' && groupName) {
    return groupName;
  }

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
