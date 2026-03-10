import http from 'node:http';
import express from 'express';
import Redis from 'ioredis';
import { Server } from 'socket.io';
import { config } from './config.js';
import { logger } from './logger.js';
import { verifyChatConversationJoinToken, verifyConversationJoinToken, verifySocketToken } from './auth.js';
import { emitEnvelope, normalizeEnvelope } from './envelope.js';

const ALLOWED_CLIENT_EVENTS = new Set([
  'conversation.join',
  'conversation.leave',
  'chat.conversation.join',
  'chat.conversation.leave',]);

const ADMIN_ROLES = new Set(['system_admin', 'admin']);

const app = express();
app.disable('x-powered-by');
app.use(express.json({ limit: '32kb' }));

app.get('/health', (_request, response) => {
  response.json({
    ok: true,
    service: 'realtime',
    timestamp: new Date().toISOString(),
  });
});

const server = http.createServer(app);

const isOriginAllowed = (origin) => {
  if (!origin) {
    return true;
  }

  if (config.corsOrigins.includes('*')) {
    return true;
  }

  return config.corsOrigins.includes(origin);
};

const io = new Server(server, {
  transports: ['websocket'],
  allowUpgrades: false,
  cors: {
    origin: (origin, callback) => {
      if (isOriginAllowed(origin)) {
        callback(null, true);
        return;
      }

      callback(new Error('origin_not_allowed'));
    },
    methods: ['GET', 'POST'],
    credentials: false,
  },
});

const handleIncomingEnvelope = (rawPayload, source) => {
  const normalized = normalizeEnvelope(rawPayload);
  if (!normalized) {
    logger.warn('realtime.envelope.invalid', { source });
    return false;
  }

  emitEnvelope(io, normalized);
  logger.info('realtime.envelope.emitted', {
    source,
    event: normalized.event,
    rooms: normalized.rooms,
    requestId: normalized.meta.requestId,
  });

  return true;
};

app.post('/internal/emit', (request, response) => {
  const internalKey = String(request.header('X-INTERNAL-KEY') ?? '');
  if (internalKey !== config.internal.key) {
    logger.warn('internal_emit.unauthorized', {
      ip: request.ip,
    });
    response.status(401).json({ message: 'unauthorized' });
    return;
  }

  const accepted = handleIncomingEnvelope(request.body, 'internal_http');
  if (!accepted) {
    response.status(422).json({ message: 'invalid_envelope' });
    return;
  }

  response.json({ ok: true });
});

io.use((socket, next) => {
  try {
    const token = String(socket.handshake.auth?.token ?? '');
    if (!token) {
      next(new Error('missing_auth_token'));
      return;
    }

    const user = verifySocketToken(token);
    socket.data.user = user;
    next();
  } catch (error) {
    logger.warn('socket.auth_failed', {
      socketId: socket.id,
      error: error instanceof Error ? error.message : 'unknown_error',
    });
    next(new Error('invalid_auth_token'));
  }
});

io.on('connection', (socket) => {
  const user = socket.data.user;
  const userRoom = `user:${user.userId}`;
  const chatUserRoom = `chat:user:${user.userId}`;
  const companyRoom = `company:${user.companyId}`;

  socket.join(userRoom);
  socket.join(chatUserRoom);
  socket.join(companyRoom);

  logger.info('socket.connected', {
    socketId: socket.id,
    userId: user.userId,
    companyId: user.companyId,
    roles: user.roles,
    joinedRooms: [companyRoom, userRoom, chatUserRoom],
  });

  const msUntilExpiry = Math.max(0, user.exp * 1000 - Date.now());
  const expirationTimer = setTimeout(() => {
    socket.emit('auth.expired');
    socket.disconnect(true);
  }, msUntilExpiry + 250);

  socket.on('conversation.join', (payload = {}, callback) => {
    try {
      const conversationId = Number.parseInt(String(payload.conversationId ?? ''), 10);
      const joinToken = String(payload.token ?? '');
      if (!conversationId || !joinToken) {
        throw new Error('invalid_join_payload');
      }

      const joinClaims = verifyConversationJoinToken(joinToken);
      if (joinClaims.userId !== user.userId) {
        throw new Error('join_user_mismatch');
      }

      if (joinClaims.conversationId !== conversationId) {
        throw new Error('join_conversation_mismatch');
      }

      if (user.companyId > 0 && joinClaims.companyId !== user.companyId) {
        throw new Error('join_company_mismatch');
      }

      const isAdminUser = user.roles.some((role) => ADMIN_ROLES.has(role));
      if (user.companyId === 0 && !isAdminUser) {
        throw new Error('join_missing_company_scope');
      }

      const room = `conversation:${conversationId}`;
      socket.join(room);

      logger.info('socket.join_conversation', {
        socketId: socket.id,
        userId: user.userId,
        conversationId,
      });

      if (typeof callback === 'function') {
        callback({ ok: true });
      }
    } catch (error) {
      logger.warn('socket.join_conversation_denied', {
        socketId: socket.id,
        userId: user.userId,
        error: error instanceof Error ? error.message : 'unknown_error',
      });
      if (typeof callback === 'function') {
        callback({ ok: false, message: 'join_denied' });
      }
    }
  });

  socket.on('conversation.leave', (payload = {}, callback) => {
    const conversationId = Number.parseInt(String(payload.conversationId ?? ''), 10);
    if (!conversationId) {
      if (typeof callback === 'function') {
        callback({ ok: false, message: 'invalid_conversation_id' });
      }
      return;
    }

    const room = `conversation:${conversationId}`;
    socket.leave(room);

    if (typeof callback === 'function') {
      callback({ ok: true });
    }
  });

  socket.onAny((eventName) => {
    if (ALLOWED_CLIENT_EVENTS.has(eventName)) {
      return;
    }

    logger.warn('socket.client_event_blocked', {
      socketId: socket.id,
      userId: user.userId,
      event: eventName,
    });
  });

  socket.on('disconnect', (reason) => {
    clearTimeout(expirationTimer);
    logger.info('socket.disconnected', {
      socketId: socket.id,
      userId: user.userId,
      reason,
    });
  });

  socket.on('chat.conversation.join', (payload = {}, callback) => {
    try {
      const conversationId = Number.parseInt(
        String(payload.conversationId ?? ''), 10
      );
      const joinToken = String(payload.token ?? '');

      if (!conversationId || !joinToken) {
        throw new Error('invalid_chat_join_payload');
      }

      const joinClaims = verifyChatConversationJoinToken(joinToken);
      if (joinClaims.userId !== user.userId) {
        throw new Error('chat_join_user_mismatch');
      }

      if (joinClaims.conversationId !== conversationId) {
        throw new Error('chat_join_conversation_mismatch');
      }

      if (user.companyId > 0 && joinClaims.companyId > 0 && joinClaims.companyId !== user.companyId) {
        throw new Error('chat_join_company_mismatch');
      }

      const room = `chat:conversation:${conversationId}`;
      socket.join(room);

      logger.info('socket.chat_join', {
        socketId: socket.id,
        userId: user.userId,
        conversationId,
      });

      if (typeof callback === 'function') {
        callback({ ok: true });
      }
    } catch (error) {
      logger.warn('socket.chat_join_denied', {
        socketId: socket.id,
        userId: user.userId,
        error: error instanceof Error ? error.message : 'unknown_error',
      });

      if (typeof callback === 'function') {
        callback({ ok: false, message: 'join_denied' });
      }
    }
  });

  socket.on('chat.conversation.leave', (payload = {}, callback) => {
    const conversationId = Number.parseInt(
      String(payload.conversationId ?? ''), 10
    );

    if (!conversationId) {
      if (typeof callback === 'function') {
        callback({ ok: false, message: 'invalid_conversation_id' });
      }
      return;
    }

    socket.leave(`chat:conversation:${conversationId}`);

    if (typeof callback === 'function') {
      callback({ ok: true });
    }
  });
});

const subscriber = new Redis(config.redis.url, {
  lazyConnect: true,
  maxRetriesPerRequest: null,
  retryStrategy: (times) => Math.min(times * 250, 5000),
});

let isSubscribed = false;
const redisChannelSuffix = String(config.redis.channel ?? 'realtime.events').trim() || 'realtime.events';
const redisChannelPattern = `*${redisChannelSuffix}`;

subscriber.on('ready', async () => {
  if (isSubscribed) {
    return;
  }

  try {
    await subscriber.psubscribe(redisChannelPattern);
    isSubscribed = true;
    logger.info('redis.psubscribed', {
      channelSuffix: redisChannelSuffix,
      pattern: redisChannelPattern,
    });
  } catch (error) {
    logger.error('redis.psubscribe_failed', {
      error: error instanceof Error ? error.message : 'unknown_error',
    });
  }
});

subscriber.on('pmessage', (pattern, channel, message) => {
  if (!String(channel).endsWith(redisChannelSuffix)) {
    return;
  }

  try {
    const payload = JSON.parse(message);
    handleIncomingEnvelope(payload, 'redis');
  } catch (error) {
    logger.error('redis.message_invalid_json', {
      pattern,
      channel,
      error: error instanceof Error ? error.message : 'unknown_error',
    });
  }
});

subscriber.on('close', () => {
  isSubscribed = false;
  logger.warn('redis.connection_closed');
});

subscriber.on('reconnecting', () => {
  logger.warn('redis.reconnecting');
});

subscriber.on('error', (error) => {
  logger.error('redis.error', {
    error: error instanceof Error ? error.message : 'unknown_error',
  });
});

subscriber.connect().catch((error) => {
  logger.error('redis.initial_connect_failed', {
    error: error instanceof Error ? error.message : 'unknown_error',
  });
});

server.listen(config.port, config.host, () => {
  logger.info('realtime.started', {
    host: config.host,
    port: config.port,
    nodeEnv: config.nodeEnv,
    channelSuffix: redisChannelSuffix,
    channelPattern: redisChannelPattern,
    corsOrigins: config.corsOrigins,
  });
});
