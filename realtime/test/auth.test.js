import assert from 'node:assert/strict';
import test from 'node:test';
import jwt from 'jsonwebtoken';

process.env.REALTIME_JWT_SECRET = process.env.REALTIME_JWT_SECRET ?? 'test-realtime-jwt-secret';
process.env.REALTIME_INTERNAL_KEY = process.env.REALTIME_INTERNAL_KEY ?? 'test-realtime-internal-key';
process.env.REALTIME_JWT_ISSUER = process.env.REALTIME_JWT_ISSUER ?? 'http://localhost';
process.env.REALTIME_JWT_AUDIENCE = process.env.REALTIME_JWT_AUDIENCE ?? 'realtime';

const { verifySocketToken, verifyConversationJoinToken, verifyChatConversationJoinToken } = await import('../src/auth.js');

const signToken = (claims, options = {}) => {
  return jwt.sign(claims, process.env.REALTIME_JWT_SECRET, {
    algorithm: 'HS256',
    issuer: process.env.REALTIME_JWT_ISSUER,
    audience: process.env.REALTIME_JWT_AUDIENCE,
    ...options,
  });
};

test('verifySocketToken validates and normalizes socket token claims', () => {
  const token = signToken(
    {
      sub: '41',
      type: 'socket',
      companyId: '15',
      roles: ['agent', 'company_admin'],
    },
    { expiresIn: '120s' }
  );

  const claims = verifySocketToken(token);

  assert.equal(claims.userId, 41);
  assert.equal(claims.companyId, 15);
  assert.deepEqual(claims.roles, ['agent', 'company_admin']);
  assert.equal(typeof claims.exp, 'number');
  assert.ok(claims.exp > 0);
});

test('verifySocketToken rejects wrong token type', () => {
  const token = signToken(
    {
      sub: '10',
      type: 'conversation_join',
      companyId: 3,
      conversationId: 90,
    },
    { expiresIn: '120s' }
  );

  assert.throws(() => verifySocketToken(token), /invalid_socket_token_type/);
});

test('verifyConversationJoinToken rejects invalid conversation id', () => {
  const token = signToken(
    {
      sub: '12',
      type: 'conversation_join',
      companyId: 1,
      conversationId: 0,
    },
    { expiresIn: '120s' }
  );

  assert.throws(() => verifyConversationJoinToken(token), /invalid_join_conversation/);
});

test('verifyChatConversationJoinToken supports string role and normalizes company id', () => {
  const token = signToken(
    {
      sub: '88',
      type: 'chat_conversation_join',
      companyId: '0',
      conversationId: 304,
      roles: 'system_admin',
    },
    { expiresIn: '120s' }
  );

  const claims = verifyChatConversationJoinToken(token);

  assert.equal(claims.userId, 88);
  assert.equal(claims.companyId, 0);
  assert.equal(claims.conversationId, 304);
  assert.deepEqual(claims.roles, ['system_admin']);
});

test('verifyChatConversationJoinToken rejects tokens without exp', () => {
  const token = signToken(
    {
      sub: '99',
      type: 'chat_conversation_join',
      companyId: 2,
      conversationId: 401,
    },
    { noTimestamp: true }
  );

  assert.throws(() => verifyChatConversationJoinToken(token), /invalid_chat_join_exp/);
});
