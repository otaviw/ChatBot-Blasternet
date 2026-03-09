export function toTimestamp(value) {
  if (!value) return 0;

  const timestamp = new Date(value).getTime();
  return Number.isFinite(timestamp) ? timestamp : 0;
}

export function conversationActivityTimestamp(conversation) {
  return Math.max(
    toTimestamp(conversation?.last_message_at),
    toTimestamp(conversation?.updated_at),
    toTimestamp(conversation?.created_at)
  );
}

export function sortConversationsByActivity(items) {
  return [...items].sort((a, b) => {
    const byActivity = conversationActivityTimestamp(b) - conversationActivityTimestamp(a);
    if (byActivity !== 0) {
      return byActivity;
    }

    return Number(b?.id ?? 0) - Number(a?.id ?? 0);
  });
}

export function appendUniqueMessage(messages, message) {
  if (!message || !message.id) {
    return messages;
  }

  const exists = messages.some((item) => Number(item.id) === Number(message.id));
  return exists ? messages : [...messages, message];
}

export function mergeMessagesChronologically(currentMessages, incomingMessages) {
  const mergedById = new Map();

  [...(currentMessages ?? []), ...(incomingMessages ?? [])].forEach((message) => {
    if (!message?.id) {
      return;
    }

    mergedById.set(Number(message.id), {
      ...(mergedById.get(Number(message.id)) ?? {}),
      ...message,
    });
  });

  return Array.from(mergedById.values()).sort((a, b) => Number(a.id ?? 0) - Number(b.id ?? 0));
}

export function normalizeEventConversation(payload) {
  if (!payload || typeof payload !== 'object') {
    return null;
  }

  const id = Number.parseInt(String(payload.id ?? payload.conversation_id ?? ''), 10);
  if (!id) {
    return null;
  }

  return {
    ...payload,
    id,
  };
}

export function buildRealtimeMessage(payload, conversationId, messageId) {
  const nestedMessage =
    payload?.message && typeof payload.message === 'object' ? payload.message : null;

  return {
    id: messageId,
    conversation_id: conversationId,
    direction: payload.direction ?? nestedMessage?.direction ?? 'out',
    type: payload.type ?? nestedMessage?.type ?? 'system',
    text: payload.text ?? nestedMessage?.text ?? '',
    content_type:
      payload.contentType ??
      payload.content_type ??
      nestedMessage?.contentType ??
      nestedMessage?.content_type ??
      'text',
    media_url: payload.mediaUrl ?? payload.media_url ?? nestedMessage?.mediaUrl ?? nestedMessage?.media_url ?? null,
    media_mime_type:
      payload.mediaMimeType ??
      payload.media_mime_type ??
      nestedMessage?.mediaMimeType ??
      nestedMessage?.media_mime_type ??
      null,
    media_size_bytes:
      payload.mediaSizeBytes ??
      payload.media_size_bytes ??
      nestedMessage?.mediaSizeBytes ??
      nestedMessage?.media_size_bytes ??
      null,
    media_width: payload.mediaWidth ?? payload.media_width ?? nestedMessage?.mediaWidth ?? nestedMessage?.media_width ?? null,
    media_height:
      payload.mediaHeight ??
      payload.media_height ??
      nestedMessage?.mediaHeight ??
      nestedMessage?.media_height ??
      null,
    created_at: payload.createdAt ?? payload.created_at ?? nestedMessage?.createdAt ?? nestedMessage?.created_at ?? null,
  };
}
