import { io } from 'socket.io-client';
import { post } from './apiClient';
import { error as logError } from '@/lib/logger';

class RealtimeTransport {
  constructor({ onConnect, onRawEvent, shouldAutoReconnect } = {}) {
    this.socket = null;
    this.connectPromise = null;
    this.reconnectTimer = null;
    this.onConnect = onConnect;
    this.onRawEvent = onRawEvent;
    this.shouldAutoReconnect = shouldAutoReconnect;
  }

  setCallbacks({ onConnect, onRawEvent, shouldAutoReconnect } = {}) {
    this.onConnect = onConnect;
    this.onRawEvent = onRawEvent;
    this.shouldAutoReconnect = shouldAutoReconnect;
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
      throw new Error('Token de realtime inválido.');
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
      this.onConnect?.();
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
      if (this.shouldAutoReconnect?.()) {
        this.scheduleReconnect();
      }
    });

    socket.onAny((eventName, envelope) => {
      this.onRawEvent?.(eventName, envelope);
    });

    if (forceRefresh && socket.connected) {
      this.onConnect?.();
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
      logError('Realtime reconnect failed', error);
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
        logError('Realtime scheduled reconnect failed', error);
        this.scheduleReconnect();
      });
    }, 1200);
  }

  emitLeaveConversation(conversationId) {
    if (this.socket?.connected) {
      this.socket.emit('conversation.leave', { conversationId });
    }
  }

  emitLeaveChatConversation(conversationId) {
    if (this.socket?.connected) {
      this.socket.emit('chat.conversation.leave', { conversationId });
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
    const response = await post('/realtime/token');
    return response.data ?? null;
  }

  async fetchJoinToken(conversationId) {
    try {
      const response = await post(`/realtime/conversations/${conversationId}/join-token`);
      return response.data?.token ?? null;
    } catch (_error) {
      return null;
    }
  }

  async fetchChatJoinToken(conversationId) {
    try {
      const response = await post(`/realtime/chat-conversations/${conversationId}/join-token`);
      return response.data?.token ?? null;
    } catch (_error) {
      return null;
    }
  }
}

export default RealtimeTransport;
