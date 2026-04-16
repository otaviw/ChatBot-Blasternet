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

export function normalizeMessageReactions(reactions) {
  if (!Array.isArray(reactions)) {
    return [];
  }

  return reactions
    .map((reaction) => {
      if (!reaction || typeof reaction !== 'object') {
        return null;
      }

      const emoji = String(reaction.emoji ?? '').trim();
      if (!emoji) {
        return null;
      }

      const reactorPhone = String(
        reaction.reactor_phone ?? reaction.reactorPhone ?? ''
      ).trim();
      const reactedAtRaw = reaction.reacted_at ?? reaction.reactedAt ?? null;
      const reactedAt = reactedAtRaw ? String(reactedAtRaw) : null;

      return {
        id: Number.parseInt(String(reaction.id ?? ''), 10) || null,
        reactor_phone: reactorPhone,
        emoji,
        reacted_at: reactedAt,
      };
    })
    .filter(Boolean);
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
    whatsapp_message_id:
      payload.whatsappMessageId ??
      payload.whatsapp_message_id ??
      nestedMessage?.whatsappMessageId ??
      nestedMessage?.whatsapp_message_id ??
      null,
    delivery_status:
      payload.deliveryStatus ??
      payload.delivery_status ??
      nestedMessage?.deliveryStatus ??
      nestedMessage?.delivery_status ??
      null,
    sent_at: payload.sentAt ?? payload.sent_at ?? nestedMessage?.sentAt ?? nestedMessage?.sent_at ?? null,
    delivered_at:
      payload.deliveredAt ?? payload.delivered_at ?? nestedMessage?.deliveredAt ?? nestedMessage?.delivered_at ?? null,
    read_at: payload.readAt ?? payload.read_at ?? nestedMessage?.readAt ?? nestedMessage?.read_at ?? null,
    failed_at: payload.failedAt ?? payload.failed_at ?? nestedMessage?.failedAt ?? nestedMessage?.failed_at ?? null,
    created_at: payload.createdAt ?? payload.created_at ?? nestedMessage?.createdAt ?? nestedMessage?.created_at ?? null,
    reactions: normalizeMessageReactions(
      payload.reactions ?? payload.message_reactions ?? nestedMessage?.reactions ?? []
    ),
    meta: {
      actor_user_name:
        payload.actorUserName ??
        payload.actor_user_name ??
        nestedMessage?.actorUserName ??
        nestedMessage?.meta?.actor_user_name ??
        null,
    },
  };
}

export function buildRealtimeMessageStatusPatch(payload, conversationId, messageId) {
  return {
    id: messageId,
    conversation_id: conversationId,
    whatsapp_message_id: payload?.whatsappMessageId ?? payload?.whatsapp_message_id ?? null,
    delivery_status: payload?.deliveryStatus ?? payload?.delivery_status ?? null,
    sent_at: payload?.sentAt ?? payload?.sent_at ?? null,
    delivered_at: payload?.deliveredAt ?? payload?.delivered_at ?? null,
    read_at: payload?.readAt ?? payload?.read_at ?? null,
    failed_at: payload?.failedAt ?? payload?.failed_at ?? null,
  };
}

export function buildRealtimeMessageReactionsPatch(payload, conversationId, messageId) {
  return {
    id: messageId,
    conversation_id: conversationId,
    reactions: normalizeMessageReactions(payload?.reactions ?? []),
  };
}
