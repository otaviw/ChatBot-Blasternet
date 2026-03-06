import { io } from 'socket.io-client';
import api from './api';

const SUPPORTED_EVENTS = new Set([
  'message.created',
  'bot.updated',
  'conversation.transferred',
  'notification.created',
]);

const MAX_SEEN_ITEMS = 1500;
const SEEN_TTL_MS = 5 * 60 * 1000;

class RealtimeClient {
  constructor() {
    this.socket = null;
    this.connectPromise = null;
    this.handlers = new Map();
    this.joinedConversations = new Set();
    this.seenRequestIds = new Map();
    this.seenMessageIds = new Map();
    this.seenTransferIds = new Map();
    this.reconnectTimer = null;
  }

  on(eventName, handler) {
    if (!SUPPORTED_EVENTS.has(eventName)) {
      throw new Error(`Realtime event nao suportado: ${eventName}`);
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

    if (this.handlers.size === 0 && this.joinedConversations.size === 0) {
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
      if (this.handlers.size > 0 || this.joinedConversations.size > 0) {
        this.scheduleReconnect();
      }
    });

    socket.onAny((eventName, envelope) => {
      if (!SUPPORTED_EVENTS.has(eventName)) {
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
    }
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

    if (this.handlers.size === 0 && this.joinedConversations.size === 0) {
      this.disconnect();
    }
  }

  async rejoinConversationRooms() {
    for (const conversationId of this.joinedConversations) {
      try {
        const joinToken = await this.fetchJoinToken(conversationId);
        if (!joinToken) {
          continue;
        }
        await this.emitJoinConversation(conversationId, joinToken);
      } catch (_error) {
        // Falha de rejoin não deve derrubar o restante da conexão.
      }
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

    const requestId = String(envelope?.meta?.requestId ?? '').trim();
    if (requestId !== '') {
      const scopedRequestKey = `${eventName}:${requestId}:${this.buildRequestDedupeKey(eventName, envelope?.payload)}`;
      if (this.seenRequestIds.has(scopedRequestKey)) {
        return true;
      }
      this.seenRequestIds.set(scopedRequestKey, now);
    }

    if (eventName === 'message.created') {
      const messageId = Number.parseInt(String(envelope?.payload?.messageId ?? ''), 10);
      if (messageId > 0) {
        if (this.seenMessageIds.has(messageId)) {
          return true;
        }
        this.seenMessageIds.set(messageId, now);
      }
    }

    if (eventName === 'conversation.transferred') {
      const transferId = Number.parseInt(String(envelope?.payload?.transferId ?? ''), 10);
      if (transferId > 0) {
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

    if (eventName === 'message.created') {
      const messageId = Number.parseInt(String(payload.messageId ?? ''), 10);
      return messageId > 0 ? `message:${messageId}` : 'message:unknown';
    }

    if (eventName === 'conversation.transferred') {
      const transferId = Number.parseInt(String(payload.transferId ?? ''), 10);
      return transferId > 0 ? `transfer:${transferId}` : 'transfer:unknown';
    }

    if (eventName === 'notification.created') {
      const notificationId = Number.parseInt(String(payload?.notification?.id ?? ''), 10);
      return notificationId > 0 ? `notification:${notificationId}` : 'notification:unknown';
    }

    const companyId = Number.parseInt(String(payload.companyId ?? ''), 10);
    if (companyId > 0) {
      return `company:${companyId}`;
    }

    return 'generic';
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

const realtimeClient = new RealtimeClient();

export default realtimeClient;
