import { io } from 'socket.io-client';
import { REALTIME_EVENTS } from '@/constants/realtimeEvents';
import api from './api';
import { isSupportedRealtimeEvent } from './realtime/isSupportedRealtimeEvent';
import { readPositiveInt } from './realtime/readPositiveInt';
import { readMessagePayload } from './realtime/readMessagePayload';

const MAX_SEEN_ITEMS = 1500;
const SEEN_TTL_MS = 5 * 60 * 1000;

class RealtimeClient {
  constructor() {
    this.socket = null;
    this.connectPromise = null;
    this.handlers = new Map();
    this.joinedConversations = new Set();
    this.joinedChatConversations = new Set();
    this.seenRequestIds = new Map();
    this.seenMessageIds = new Map();
    this.seenTransferIds = new Map();
    this.reconnectTimer = null;
  }

  on(eventName, handler) {
    if (!isSupportedRealtimeEvent(eventName)) {
      throw new Error(`Realtime event não suportado: ${eventName}`);
    }

    if (!this.handlers.has(eventName)) {
      this.handlers.set(eventName, new Set());
    }

    this.handlers.get(eventName).add(handler);
    void this.ensureConnected().catch((error) => {
      console.error('Realtime connection failed', error);
    });

    return () => this.off(eventName, handler);
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

    if (
      this.handlers.size === 0 &&
      this.joinedConversations.size === 0 &&
      this.joinedChatConversations.size === 0
    ) {
      this.disconnect();
    }
  }

  disconnect() {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }

    if (this.socket) {
      this.socket.removeAllListeners();
      this.socket.disconnect();
      this.socket = null;
    }
  }

  async ensureConnected(forceRefresh = false) {
    if (this.socket?.connected && !forceRefresh) {
      return;
    }

    if (this.connectPromise) {
      return this.connectPromise;
    }

    this.connectPromise = this.connect(forceRefresh).finally(() => {
      this.connectPromise = null;
    });

    return this.connectPromise;
  }

  async connect(forceRefresh = false) {
    const socketToken = await this.fetchSocketToken();
    if (!socketToken?.token) {
      throw new Error('Token de realtime invalido.');
    }

    if (this.socket) {
      this.socket.removeAllListeners();
      this.socket.disconnect();
      this.socket = null;
    }

    const socket = io(socketToken.socket_url || import.meta.env.VITE_REALTIME_URL || 'http://localhost:8081', {
      transports: ['websocket'],
      auth: {
        token: socketToken.token,
      },
      autoConnect: true,
      reconnection: true,
      reconnectionAttempts: Infinity,
      reconnectionDelay: 1000,
      reconnectionDelayMax: 4000,
      timeout: 7000,
    });

    this.socket = socket;

    socket.on('connect', () => {
      this.rejoinConversationRooms();
      this.rejoinChatConversationRooms();
    });

    socket.on('auth.expired', () => {
      void this.reconnectWithFreshToken();
    });

    socket.on('connect_error', (error) => {
      const message = String(error?.message ?? '').toLowerCase();
      if (message.includes('token') || message.includes('auth') || message.includes('unauthorized')) {
        void this.reconnectWithFreshToken();
      }
    });

    socket.on('disconnect', () => {
      if (
        this.handlers.size > 0 ||
        this.joinedConversations.size > 0 ||
        this.joinedChatConversations.size > 0
      ) {
        this.scheduleReconnect();
      }
    });

    socket.onAny((eventName, envelope) => {
      if (!isSupportedRealtimeEvent(eventName)) {
        return;
      }

      const normalized = this.normalizeEnvelope(eventName, envelope);
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
          console.error('Realtime listener failed', error);
        }
      });
    });

    if (forceRefresh && socket.connected) {
      this.rejoinConversationRooms();
      this.rejoinChatConversationRooms();
    }

    await this.waitForSocketConnection(socket);
  }

  waitForSocketConnection(socket) {
    if (!socket) {
      return Promise.reject(new Error('Socket indisponível para conexão.'));
    }

    if (socket.connected) {
      return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
      const timeoutMs = 7500;
      const timeout = setTimeout(() => {
        cleanup();
        reject(new Error('Timeout ao conectar no realtime.'));
      }, timeoutMs);

      const cleanup = () => {
        clearTimeout(timeout);
        socket.off('connect', onConnect);
        socket.off('connect_error', onError);
      };

      const onConnect = () => {
        cleanup();
        resolve();
      };

      const onError = (error) => {
        cleanup();
        reject(error instanceof Error ? error : new Error('Falha ao conectar no realtime.'));
      };

      socket.on('connect', onConnect);
      socket.on('connect_error', onError);
    });
  }

  async reconnectWithFreshToken() {
    try {
      this.disconnect();
      await this.ensureConnected(true);
    } catch (error) {
      console.error('Realtime reconnect failed', error);
      this.scheduleReconnect();
    }
  }

  scheduleReconnect() {
    if (this.reconnectTimer) {
      return;
    }

    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      void this.ensureConnected(true).catch((error) => {
        console.error('Realtime scheduled reconnect failed', error);
        this.scheduleReconnect();
      });
    }, 1200);
  }

  async joinConversation(conversationId) {
    const id = Number.parseInt(String(conversationId), 10);
    if (!id || id <= 0) {
      return false;
    }

    this.joinedConversations.add(id);
    try {
      await this.ensureConnected();
    } catch (error) {
      console.error('Realtime join failed to connect', error);
      return false;
    }

    if (!this.socket) {
      return false;
    }

    const joinToken = await this.fetchJoinToken(id);
    if (!joinToken) {
      return false;
    }

    return this.emitJoinConversation(id, joinToken);
  }

  leaveConversation(conversationId) {
    const id = Number.parseInt(String(conversationId), 10);
    if (!id || id <= 0) {
      return;
    }

    this.joinedConversations.delete(id);

    if (this.socket?.connected) {
      this.socket.emit('conversation.leave', { conversationId: id });
    }

    if (
      this.handlers.size === 0 &&
      this.joinedConversations.size === 0 &&
      this.joinedChatConversations.size === 0
    ) {
      this.disconnect();
    }
  }

  async joinChatConversation(conversationId) {
    const id = Number.parseInt(String(conversationId), 10);
    if (!id || id <= 0) {
      return false;
    }

    this.joinedChatConversations.add(id);
    try {
      await this.ensureConnected();
    } catch (error) {
      console.error('Realtime chat join failed to connect', error);
      return false;
    }

    if (!this.socket) {
      return false;
    }

    const joinToken = await this.fetchChatJoinToken(id);
    if (!joinToken) {
      return false;
    }

    return this.emitJoinChatConversation(id, joinToken);
  }

  leaveChatConversation(conversationId) {
    const id = Number.parseInt(String(conversationId), 10);
    if (!id || id <= 0) {
      return;
    }

    this.joinedChatConversations.delete(id);

    if (this.socket?.connected) {
      this.socket.emit('chat.conversation.leave', { conversationId: id });
    }

    if (
      this.handlers.size === 0 &&
      this.joinedConversations.size === 0 &&
      this.joinedChatConversations.size === 0
    ) {
      this.disconnect();
    }
  }

  async rejoinConversationRooms() {
    const toRetry = [];
    for (const conversationId of this.joinedConversations) {
      try {
        const joinToken = await this.fetchJoinToken(conversationId);
        if (!joinToken) {
          toRetry.push(conversationId);
          continue;
        }
        const ok = await this.emitJoinConversation(conversationId, joinToken);
        if (!ok) {
          toRetry.push(conversationId);
        }
      } catch (_error) {
        toRetry.push(conversationId);
      }
    }

    if (toRetry.length > 0) {
      setTimeout(() => {
        for (const id of toRetry) {
          if (this.joinedConversations.has(id)) {
            void this.joinConversation(id);
          }
        }
      }, 3000);
    }
  }

  async rejoinChatConversationRooms() {
    const toRetry = [];
    for (const conversationId of this.joinedChatConversations) {
      try {
        const joinToken = await this.fetchChatJoinToken(conversationId);
        if (!joinToken) {
          toRetry.push(conversationId);
          continue;
        }
        const ok = await this.emitJoinChatConversation(conversationId, joinToken);
        if (!ok) {
          toRetry.push(conversationId);
        }
      } catch (_error) {
        toRetry.push(conversationId);
      }
    }

    if (toRetry.length > 0) {
      setTimeout(() => {
        for (const id of toRetry) {
          if (this.joinedChatConversations.has(id)) {
            void this.joinChatConversation(id);
          }
        }
      }, 3000);
    }
  }

  async emitJoinConversation(conversationId, joinToken) {
    if (!this.socket) {
      return false;
    }

    return new Promise((resolve) => {
      this.socket.timeout(5000).emit(
        'conversation.join',
        {
          conversationId,
          token: joinToken,
        },
        (error, response) => {
          if (error || !response?.ok) {
            resolve(false);
            return;
          }
          resolve(true);
        }
      );
    });
  }

  async emitJoinChatConversation(conversationId, joinToken) {
    if (!this.socket) {
      return false;
    }

    return new Promise((resolve) => {
      this.socket.timeout(5000).emit(
        'chat.conversation.join',
        {
          conversationId,
          token: joinToken,
        },
        (error, response) => {
          if (error || !response?.ok) {
            resolve(false);
            return;
          }
          resolve(true);
        }
      );
    });
  }

  async fetchSocketToken() {
    const response = await api.post('/realtime/token');
    return response.data ?? null;
  }

  async fetchJoinToken(conversationId) {
    try {
      const response = await api.post(`/realtime/conversations/${conversationId}/join-token`);
      return response.data?.token ?? null;
    } catch (_error) {
      return null;
    }
  }

  async fetchChatJoinToken(conversationId) {
    try {
      const response = await api.post(`/realtime/chat-conversations/${conversationId}/join-token`);
      return response.data?.token ?? null;
    } catch (_error) {
      return null;
    }
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

  // varre o mapa inteiro a cada evento recebido, oK no volume atual,
  // mas vira gargalo se as conversas simultâneas ou a frequência de mensagens crescerem 
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

const realtimeClient = new RealtimeClient();

export default realtimeClient;
