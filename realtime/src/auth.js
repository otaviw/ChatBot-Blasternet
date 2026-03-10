import jwt from 'jsonwebtoken';
import { config } from './config.js';

const parseInteger = (value, fallback = null) => {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  if (!Number.isFinite(parsed)) {
    return fallback;
  }

  return parsed;
};

const normalizeRoles = (roles) => {
  if (Array.isArray(roles)) {
    return roles.map((role) => String(role)).filter(Boolean);
  }

  if (typeof roles === 'string' && roles.trim() !== '') {
    return [roles.trim()];
  }

  return [];
};

const verifyBaseToken = (token) => {
  return jwt.verify(token, config.jwt.secret, {
    algorithms: ['HS256'],
    issuer: config.jwt.issuer,
    audience: config.jwt.audience,
  });
};

export const verifySocketToken = (token) => {
  const payload = verifyBaseToken(token);
  if (payload?.type !== 'socket') {
    throw new Error('invalid_socket_token_type');
  }

  const userId = parseInteger(payload.sub, null);
  if (!userId || userId <= 0) {
    throw new Error('invalid_socket_user');
  }

  const companyId = parseInteger(payload.companyId, 0) ?? 0;
  const exp = parseInteger(payload.exp, null);
  if (!exp) {
    throw new Error('invalid_socket_exp');
  }

  return {
    userId,
    companyId: companyId > 0 ? companyId : 0,
    roles: normalizeRoles(payload.roles),
    exp,
  };
};

export const verifyConversationJoinToken = (token) => {
  const payload = verifyBaseToken(token);
  if (payload?.type !== 'conversation_join') {
    throw new Error('invalid_join_token_type');
  }

  const userId = parseInteger(payload.sub, null);
  const conversationId = parseInteger(payload.conversationId, null);
  const companyId = parseInteger(payload.companyId, 0) ?? 0;
  const exp = parseInteger(payload.exp, null);

  if (!userId || userId <= 0) {
    throw new Error('invalid_join_user');
  }

  if (!conversationId || conversationId <= 0) {
    throw new Error('invalid_join_conversation');
  }

  if (!exp) {
    throw new Error('invalid_join_exp');
  }

  return {
    userId,
    conversationId,
    companyId: companyId > 0 ? companyId : 0,
    roles: normalizeRoles(payload.roles),
    exp,
  };
};

export const verifyChatConversationJoinToken = (token) => {
  const payload = verifyBaseToken(token);
  if (payload?.type !== 'chat_conversation_join') {
    throw new Error('invalid_chat_join_token_type');
  }

  const userId = parseInteger(payload.sub, null);
  const conversationId = parseInteger(payload.conversationId, null);
  const companyId = parseInteger(payload.companyId, 0) ?? 0;
  const exp = parseInteger(payload.exp, null);

  if (!userId || userId <= 0) {
    throw new Error('invalid_chat_join_user');
  }

  if (!conversationId || conversationId <= 0) {
    throw new Error('invalid_chat_join_conversation');
  }

  if (!exp) {
    throw new Error('invalid_chat_join_exp');
  }

  return {
    userId,
    conversationId,
    companyId: companyId > 0 ? companyId : 0,
    roles: normalizeRoles(payload.roles),
    exp,
  };
};
