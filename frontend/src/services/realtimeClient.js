import RealtimeStore from './realtimeStore';
import RealtimeTransport from './realtimeTransport';
import { error as logError } from '@/lib/logger';

class RealtimeClient {
  constructor() {
    this.store = new RealtimeStore();
    this.transport = new RealtimeTransport({
      onConnect: () => {
        void this.rejoinConversationRooms();
        void this.rejoinChatConversationRooms();
      },
      onRawEvent: (eventName, envelope) => {
        this.store.dispatchRawEvent(eventName, envelope);
      },
      shouldAutoReconnect: () => this.store.hasActivity(),
    });
  }

  on(eventName, handler) {
    this.store.on(eventName, handler);
    void this.ensureConnected().catch((error) => {
      logError('Realtime connection failed', error);
    });

    return () => this.off(eventName, handler);
  }

  off(eventName, handler) {
    this.store.off(eventName, handler);
    this.disconnectIfIdle();
  }

  disconnect() {
    this.transport.disconnect();
  }

  async ensureConnected(forceRefresh = false) {
    return this.transport.ensureConnected(forceRefresh);
  }

  async joinConversation(conversationId) {
    const id = this.store.addConversationRoom(conversationId);
    if (!id) {
      return false;
    }

    try {
      await this.ensureConnected();
    } catch (error) {
      logError('Realtime join failed to connect', error);
      return false;
    }

    const joinToken = await this.transport.fetchJoinToken(id);
    if (!joinToken) {
      return false;
    }

    return this.transport.emitJoinConversation(id, joinToken);
  }

  leaveConversation(conversationId) {
    const id = this.store.removeConversationRoom(conversationId);
    if (!id) {
      return;
    }

    this.transport.emitLeaveConversation(id);
    this.disconnectIfIdle();
  }

  async joinChatConversation(conversationId) {
    const id = this.store.addChatConversationRoom(conversationId);
    if (!id) {
      return false;
    }

    try {
      await this.ensureConnected();
    } catch (error) {
      logError('Realtime chat join failed to connect', error);
      return false;
    }

    const joinToken = await this.transport.fetchChatJoinToken(id);
    if (!joinToken) {
      return false;
    }

    return this.transport.emitJoinChatConversation(id, joinToken);
  }

  leaveChatConversation(conversationId) {
    const id = this.store.removeChatConversationRoom(conversationId);
    if (!id) {
      return;
    }

    this.transport.emitLeaveChatConversation(id);
    this.disconnectIfIdle();
  }

  async rejoinConversationRooms() {
    const toRetry = [];
    const joinedConversations = this.store.getConversationRooms();

    for (const conversationId of joinedConversations) {
      try {
        const joinToken = await this.transport.fetchJoinToken(conversationId);
        if (!joinToken) {
          toRetry.push(conversationId);
          continue;
        }

        const ok = await this.transport.emitJoinConversation(conversationId, joinToken);
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
          if (this.store.hasConversationRoom(id)) {
            void this.joinConversation(id);
          }
        }
      }, 3000);
    }
  }

  async rejoinChatConversationRooms() {
    const toRetry = [];
    const joinedConversations = this.store.getChatConversationRooms();

    for (const conversationId of joinedConversations) {
      try {
        const joinToken = await this.transport.fetchChatJoinToken(conversationId);
        if (!joinToken) {
          toRetry.push(conversationId);
          continue;
        }

        const ok = await this.transport.emitJoinChatConversation(conversationId, joinToken);
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
          if (this.store.hasChatConversationRoom(id)) {
            void this.joinChatConversation(id);
          }
        }
      }, 3000);
    }
  }

  disconnectIfIdle() {
    if (this.store.hasActivity()) {
      return;
    }

    this.disconnect();
  }
}

const realtimeClient = new RealtimeClient();

export default realtimeClient;
