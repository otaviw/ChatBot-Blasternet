import { REALTIME_EVENTS } from '@/constants/realtimeEvents';
import { isSupportedRealtimeEvent } from './realtime/isSupportedRealtimeEvent';
import { readPositiveInt } from './realtime/readPositiveInt';
import { readMessagePayload } from './realtime/readMessagePayload';
import { validateEventPayload } from './realtime/validateEventPayload';
import { error as logError, warn as logWarn } from '@/lib/logger';

const MAX_SEEN_ITEMS = 1500;
const SEEN_TTL_MS = 5 * 60 * 1000;

class RealtimeStore {
  constructor() {
    this.handlers = new Map();
    this.joinedConversations = new Set();
    this.joinedChatConversations = new Set();
    this.seenRequestIds = new Map();
    this.seenMessageIds = new Map();
    this.seenTransferIds = new Map();
  }

  static toPositiveInt(value) {
    const id = Number.parseInt(String(value), 10);
    return Number.isInteger(id) && id > 0 ? id : 0;
  }

  on(eventName, handler) {
    if (!isSupportedRealtimeEvent(eventName)) {
      throw new Error(`Realtime event não suportado: ${eventName}`);
    }

    if (!this.handlers.has(eventName)) {
      this.handlers.set(eventName, new Set());
    }

    this.handlers.get(eventName).add(handler);
  }

  off(eventName, handler) {
    const listeners = this.handlers.get(eventName);
    if (!listeners) {
      return;
    }

    listeners.delete(handler);
    if (listeners.size === 0) {
      this.handlers.delete(eventName);
    }
  }

  hasActivity() {
    return (
      this.handlers.size > 0 ||
      this.joinedConversations.size > 0 ||
      this.joinedChatConversations.size > 0
    );
  }

  addConversationRoom(conversationId) {
    const id = RealtimeStore.toPositiveInt(conversationId);
    if (!id) {
      return 0;
    }

    this.joinedConversations.add(id);
    return id;
  }

  removeConversationRoom(conversationId) {
    const id = RealtimeStore.toPositiveInt(conversationId);
    if (!id) {
      return 0;
    }

    this.joinedConversations.delete(id);
    return id;
  }

  hasConversationRoom(conversationId) {
    const id = RealtimeStore.toPositiveInt(conversationId);
    return id ? this.joinedConversations.has(id) : false;
  }

  getConversationRooms() {
    return [...this.joinedConversations];
  }

  addChatConversationRoom(conversationId) {
    const id = RealtimeStore.toPositiveInt(conversationId);
    if (!id) {
      return 0;
    }

    this.joinedChatConversations.add(id);
    return id;
  }

  removeChatConversationRoom(conversationId) {
    const id = RealtimeStore.toPositiveInt(conversationId);
    if (!id) {
      return 0;
    }

    this.joinedChatConversations.delete(id);
    return id;
  }

  hasChatConversationRoom(conversationId) {
    const id = RealtimeStore.toPositiveInt(conversationId);
    return id ? this.joinedChatConversations.has(id) : false;
  }

  getChatConversationRooms() {
    return [...this.joinedChatConversations];
  }

  dispatchRawEvent(eventName, envelope) {
    if (!isSupportedRealtimeEvent(eventName)) {
      return;
    }

    const normalized = this.normalizeEnvelope(eventName, envelope);

    const validation = validateEventPayload(eventName, normalized.payload);
    if (!validation.ok) {
      logWarn(`Realtime: payload inválido para "${eventName}":`, validation.error, normalized.payload);
      return;
    }

    if (this.isDuplicate(eventName, normalized)) {
      return;
    }

    const listeners = this.handlers.get(eventName);
    if (!listeners || listeners.size === 0) {
      return;
    }

    listeners.forEach((listener) => {
      try {
        listener(normalized);
      } catch (error) {
        logError('Realtime listener failed', error);
      }
    });
  }

  normalizeEnvelope(eventName, envelope) {
    const payload =
      envelope && typeof envelope.payload === 'object' && envelope.payload !== null
        ? envelope.payload
        : {};
    const meta =
      envelope && typeof envelope.meta === 'object' && envelope.meta !== null
        ? envelope.meta
        : {};

    return {
      event: eventName,
      payload,
      meta,
    };
  }

  isDuplicate(eventName, envelope) {
    const now = Date.now();
    this.pruneSeen(this.seenRequestIds, now);
    this.pruneSeen(this.seenMessageIds, now);
    this.pruneSeen(this.seenTransferIds, now);

    const requestId = String(envelope?.meta?.requestId ?? envelope?.meta?.request_id ?? '').trim();
    if (requestId !== '') {
      const scopedRequestKey = `${eventName}:${requestId}:${this.buildRequestDedupeKey(eventName, envelope?.payload)}`;
      if (this.seenRequestIds.has(scopedRequestKey)) {
        return true;
      }
      this.seenRequestIds.set(scopedRequestKey, now);
    }

    if (
      eventName === REALTIME_EVENTS.MESSAGE_CREATED ||
      eventName === REALTIME_EVENTS.MESSAGE_UPDATED
    ) {
      const messagePayload = readMessagePayload(envelope?.payload);
      const messageId =
        readPositiveInt(envelope?.payload, ['messageId', 'message_id', 'id']) ??
        readPositiveInt(messagePayload, ['id', 'messageId', 'message_id']);
      if (messageId !== null) {
        const messageKey =
          eventName === REALTIME_EVENTS.MESSAGE_UPDATED
            ? this.buildMessageUpdateDedupeKey(envelope?.payload, messagePayload, messageId)
            : this.buildMessageDedupeKey(envelope?.payload, messagePayload, messageId);
        if (this.seenMessageIds.has(messageKey)) {
          return true;
        }
        this.seenMessageIds.set(messageKey, now);
      }
    }

    if (eventName === REALTIME_EVENTS.MESSAGE_STATUS_UPDATED) {
      const messageId = readPositiveInt(envelope?.payload, ['messageId', 'message_id', 'id']);
      if (messageId !== null) {
        const messageKey = this.buildMessageStatusDedupeKey(envelope?.payload, messageId);
        if (this.seenMessageIds.has(messageKey)) {
          return true;
        }
        this.seenMessageIds.set(messageKey, now);
      }
    }

    if (eventName === REALTIME_EVENTS.MESSAGE_REACTIONS_UPDATED) {
      const messageId = readPositiveInt(envelope?.payload, ['messageId', 'message_id', 'id']);
      if (messageId !== null) {
        const messageKey = this.buildMessageReactionsDedupeKey(envelope?.payload, messageId);
        if (this.seenMessageIds.has(messageKey)) {
          return true;
        }
        this.seenMessageIds.set(messageKey, now);
      }
    }

    if (eventName === REALTIME_EVENTS.CONVERSATION_TRANSFERRED) {
      const transferId = readPositiveInt(envelope?.payload, ['transferId', 'transfer_id', 'id']);
      if (transferId !== null) {
        if (this.seenTransferIds.has(transferId)) {
          return true;
        }
        this.seenTransferIds.set(transferId, now);
      }
    }

    return false;
  }

  buildRequestDedupeKey(eventName, payload) {
    if (!payload || typeof payload !== 'object') {
      return 'empty';
    }

    if (
      eventName === REALTIME_EVENTS.MESSAGE_CREATED ||
      eventName === REALTIME_EVENTS.MESSAGE_UPDATED
    ) {
      const messagePayload = readMessagePayload(payload);
      const messageId =
        readPositiveInt(payload, ['messageId', 'message_id', 'id']) ??
        readPositiveInt(messagePayload, ['id', 'messageId', 'message_id']);
      if (messageId !== null) {
        if (eventName === REALTIME_EVENTS.MESSAGE_UPDATED) {
          return this.buildMessageUpdateDedupeKey(payload, messagePayload, messageId);
        }
        return this.buildMessageDedupeKey(payload, messagePayload, messageId);
      }

      const conversationId =
        readPositiveInt(payload, ['conversationId', 'conversation_id']) ??
        readPositiveInt(payload?.conversation, ['id', 'conversationId', 'conversation_id']) ??
        readPositiveInt(messagePayload, ['conversationId', 'conversation_id']);
      const updatedAt = String(
        payload?.updatedAt ??
          payload?.updated_at ??
          messagePayload?.updated_at ??
          messagePayload?.updatedAt ??
          ''
      ).trim();
      const createdAt = String(
        payload?.createdAt ??
          payload?.created_at ??
          messagePayload?.created_at ??
          messagePayload?.createdAt ??
          ''
      ).trim();
      const direction = String(payload?.direction ?? messagePayload?.direction ?? '').trim();
      const type = String(payload?.type ?? messagePayload?.type ?? '').trim();
      const contentType = String(
        payload?.contentType ??
          payload?.content_type ??
          messagePayload?.content_type ??
          messagePayload?.contentType ??
          ''
      ).trim();
      const mediaUrl = String(
        payload?.mediaUrl ??
          payload?.media_url ??
          messagePayload?.media_url ??
          messagePayload?.mediaUrl ??
          ''
      ).trim();
      const text = String(payload?.text ?? messagePayload?.text ?? '')
        .trim()
        .slice(0, 80);

      return `message-signature:${eventName}:${conversationId ?? 'na'}|${createdAt}|${updatedAt}|${direction}|${type}|${contentType}|${mediaUrl}|${text}`;
    }

    if (eventName === REALTIME_EVENTS.MESSAGE_STATUS_UPDATED) {
      const messageId = readPositiveInt(payload, ['messageId', 'message_id', 'id']);
      if (messageId !== null) {
        return this.buildMessageStatusDedupeKey(payload, messageId);
      }
      return 'message-status:unknown';
    }

    if (eventName === REALTIME_EVENTS.MESSAGE_REACTIONS_UPDATED) {
      const messageId = readPositiveInt(payload, ['messageId', 'message_id', 'id']);
      if (messageId !== null) {
        return this.buildMessageReactionsDedupeKey(payload, messageId);
      }
      return 'message-reactions:unknown';
    }

    if (eventName === REALTIME_EVENTS.CONVERSATION_TRANSFERRED) {
      const transferId = readPositiveInt(payload, ['transferId', 'transfer_id', 'id']);
      return transferId !== null ? `transfer:${transferId}` : 'transfer:unknown';
    }

    if (eventName === REALTIME_EVENTS.NOTIFICATION_CREATED) {
      const notificationId = readPositiveInt(payload?.notification, ['id']);
      return notificationId !== null ? `notification:${notificationId}` : 'notification:unknown';
    }

    const companyId = readPositiveInt(payload, ['companyId', 'company_id']);
    if (companyId !== null) {
      return `company:${companyId}`;
    }

    return 'generic';
  }

  buildMessageDedupeKey(payload, messagePayload, messageId) {
    const conversationId =
      readPositiveInt(payload, ['conversationId', 'conversation_id']) ??
      readPositiveInt(payload?.conversation, ['id', 'conversationId', 'conversation_id']) ??
      readPositiveInt(messagePayload, ['conversationId', 'conversation_id']);
    const senderId =
      readPositiveInt(payload, ['senderId', 'sender_id']) ??
      readPositiveInt(payload?.sender, ['id', 'senderId', 'sender_id']) ??
      readPositiveInt(messagePayload, ['senderId', 'sender_id']);
    const direction = String(payload?.direction ?? messagePayload?.direction ?? '').trim();
    const channel = senderId ? 'chat' : direction ? 'inbox' : 'unknown';

    return `message:${channel}:${conversationId ?? 'na'}:${senderId ?? 'na'}:${messageId}`;
  }

  buildMessageUpdateDedupeKey(payload, messagePayload, messageId) {
    const base = this.buildMessageDedupeKey(payload, messagePayload, messageId);
    const updatedAt = String(
      payload?.updatedAt ??
        payload?.updated_at ??
        messagePayload?.updatedAt ??
        messagePayload?.updated_at ??
        ''
    ).trim();
    const editedAt = String(
      payload?.editedAt ??
        payload?.edited_at ??
        messagePayload?.editedAt ??
        messagePayload?.edited_at ??
        ''
    ).trim();
    const deletedAt = String(
      payload?.deletedAt ??
        payload?.deleted_at ??
        messagePayload?.deletedAt ??
        messagePayload?.deleted_at ??
        ''
    ).trim();

    return `${base}:${updatedAt}:${editedAt}:${deletedAt}`;
  }

  buildMessageStatusDedupeKey(payload, messageId) {
    const conversationId = readPositiveInt(payload, ['conversationId', 'conversation_id']);
    const deliveryStatus = String(payload?.deliveryStatus ?? payload?.delivery_status ?? '').trim();
    const sentAt = String(payload?.sentAt ?? payload?.sent_at ?? '').trim();
    const deliveredAt = String(payload?.deliveredAt ?? payload?.delivered_at ?? '').trim();
    const readAt = String(payload?.readAt ?? payload?.read_at ?? '').trim();
    const failedAt = String(payload?.failedAt ?? payload?.failed_at ?? '').trim();

    return `message-status:${conversationId ?? 'na'}:${messageId}:${deliveryStatus}:${sentAt}:${deliveredAt}:${readAt}:${failedAt}`;
  }

  buildMessageReactionsDedupeKey(payload, messageId) {
    const conversationId = readPositiveInt(payload, ['conversationId', 'conversation_id']);
    const normalizedReactions = Array.isArray(payload?.reactions)
      ? payload.reactions
          .map((reaction) => {
            if (!reaction || typeof reaction !== 'object') {
              return null;
            }

            const reactorPhone = String(
              reaction?.reactor_phone ?? reaction?.reactorPhone ?? ''
            ).trim();
            const emoji = String(reaction?.emoji ?? '').trim();
            const reactedAt = String(reaction?.reacted_at ?? reaction?.reactedAt ?? '').trim();

            if (!emoji) {
              return null;
            }

            return `${reactorPhone}|${emoji}|${reactedAt}`;
          })
          .filter(Boolean)
          .sort()
      : [];

    return `message-reactions:${conversationId ?? 'na'}:${messageId}:${normalizedReactions.join(',')}`;
  }

  pruneSeen(map, now) {
    for (const [key, timestamp] of map.entries()) {
      if (now - timestamp > SEEN_TTL_MS) {
        map.delete(key);
      }
    }

    if (map.size <= MAX_SEEN_ITEMS) {
      return;
    }

    const keys = map.keys();
    while (map.size > MAX_SEEN_ITEMS) {
      const next = keys.next();
      if (next.done) {
        break;
      }
      map.delete(next.value);
    }
  }
}

export default RealtimeStore;
