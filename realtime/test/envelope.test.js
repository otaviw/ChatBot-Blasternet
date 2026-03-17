import assert from 'node:assert/strict';
import test from 'node:test';
import { emitEnvelope, normalizeEnvelope } from '../src/envelope.js';

test('normalizeEnvelope returns null when event is missing', () => {
  const normalized = normalizeEnvelope({
    rooms: ['company:1'],
    payload: { ok: true },
  });

  assert.equal(normalized, null);
});

test('normalizeEnvelope filters invalid rooms and deduplicates valid rooms', () => {
  const normalized = normalizeEnvelope({
    event: 'message.created',
    rooms: ['company:1', ' company:1 ', 'chat:conversation:7', 'invalid-room', 'user:44'],
    payload: { text: 'hello' },
    meta: { requestId: 'req-1', timestamp: '2026-03-17T11:00:00.000Z', actorId: '18' },
  });

  assert.ok(normalized);
  assert.equal(normalized.event, 'message.created');
  assert.deepEqual(normalized.rooms, ['company:1', 'chat:conversation:7', 'user:44']);
  assert.deepEqual(normalized.payload, { text: 'hello' });
  assert.deepEqual(normalized.meta, {
    requestId: 'req-1',
    timestamp: '2026-03-17T11:00:00.000Z',
    actorId: 18,
  });
});

test('normalizeEnvelope generates defaults for meta and null actorId', () => {
  const normalized = normalizeEnvelope({
    event: 'message.status.updated',
    rooms: ['conversation:900'],
    payload: {},
    meta: { actorId: 'not-a-number' },
  });

  assert.ok(normalized);
  assert.equal(normalized.event, 'message.status.updated');
  assert.deepEqual(normalized.rooms, ['conversation:900']);
  assert.equal(normalized.meta.actorId, null);
  assert.equal(typeof normalized.meta.requestId, 'string');
  assert.ok(normalized.meta.requestId.length > 0);
  assert.equal(typeof normalized.meta.timestamp, 'string');
  assert.ok(normalized.meta.timestamp.length > 0);
});

test('emitEnvelope sends message to each room preserving event and payload', () => {
  const emitted = [];
  const io = {
    to(room) {
      return {
        emit(eventName, message) {
          emitted.push({ room, eventName, message });
        },
      };
    },
  };

  const envelope = {
    event: 'conversation.transferred',
    rooms: ['company:3', 'conversation:77'],
    payload: { conversation_id: 77, to_assigned_type: 'user' },
    meta: { requestId: 'req-2', timestamp: '2026-03-17T11:30:00.000Z', actorId: 9 },
  };

  emitEnvelope(io, envelope);

  assert.deepEqual(emitted, [
    {
      room: 'company:3',
      eventName: 'conversation.transferred',
      message: {
        event: 'conversation.transferred',
        payload: { conversation_id: 77, to_assigned_type: 'user' },
        meta: { requestId: 'req-2', timestamp: '2026-03-17T11:30:00.000Z', actorId: 9 },
      },
    },
    {
      room: 'conversation:77',
      eventName: 'conversation.transferred',
      message: {
        event: 'conversation.transferred',
        payload: { conversation_id: 77, to_assigned_type: 'user' },
        meta: { requestId: 'req-2', timestamp: '2026-03-17T11:30:00.000Z', actorId: 9 },
      },
    },
  ]);
});
