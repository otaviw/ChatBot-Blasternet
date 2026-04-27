import { describe, expect, it } from 'vitest';
import { validateEventPayload } from './validateEventPayload';
import { REALTIME_EVENTS } from '@/constants/realtimeEvents';

describe('validateEventPayload', () => {
  describe('payload inválido (qualquer evento)', () => {
    it('rejeita null', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_CREATED, null);
      expect(result.ok).toBe(false);
    });

    it('rejeita string', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_CREATED, 'texto');
      expect(result.ok).toBe(false);
    });

    it('rejeita array', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_CREATED, [1, 2]);
      expect(result.ok).toBe(false);
    });
  });

  describe('evento sem validador específico', () => {
    it('aceita qualquer objeto para evento sem regras (BOT_UPDATED)', () => {
      const result = validateEventPayload(REALTIME_EVENTS.BOT_UPDATED, { qualquer: 'coisa' });
      expect(result.ok).toBe(true);
    });

    it('aceita objeto vazio para evento sem regras', () => {
      const result = validateEventPayload(REALTIME_EVENTS.CAMPAIGN_UPDATED, {});
      expect(result.ok).toBe(true);
    });
  });

  describe('MESSAGE_CREATED', () => {
    it('aceita payload com conversation_id numérico', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_CREATED, { conversation_id: 42 });
      expect(result.ok).toBe(true);
    });

    it('aceita payload com conversationId (camelCase)', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_CREATED, { conversationId: 42 });
      expect(result.ok).toBe(true);
    });

    it('rejeita payload sem conversation_id', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_CREATED, { text: 'oi' });
      expect(result.ok).toBe(false);
    });

    it('rejeita conversation_id zero', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_CREATED, { conversation_id: 0 });
      expect(result.ok).toBe(false);
    });

    it('rejeita conversation_id negativo', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_CREATED, { conversation_id: -1 });
      expect(result.ok).toBe(false);
    });
  });

  describe('MESSAGE_STATUS_UPDATED', () => {
    it('aceita payload com message_id', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_STATUS_UPDATED, { message_id: 10 });
      expect(result.ok).toBe(true);
    });

    it('aceita payload com messageId (camelCase)', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_STATUS_UPDATED, { messageId: 10 });
      expect(result.ok).toBe(true);
    });

    it('rejeita payload sem message_id', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_STATUS_UPDATED, { status: 'delivered' });
      expect(result.ok).toBe(false);
    });
  });

  describe('MESSAGE_REACTIONS_UPDATED', () => {
    it('aceita payload com message_id e reactions como array', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_REACTIONS_UPDATED, {
        message_id: 5,
        reactions: [{ emoji: '👍', reactor_phone: '5511...' }],
      });
      expect(result.ok).toBe(true);
    });

    it('aceita payload sem reactions (campo opcional)', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_REACTIONS_UPDATED, { message_id: 5 });
      expect(result.ok).toBe(true);
    });

    it('rejeita reactions não-array', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_REACTIONS_UPDATED, {
        message_id: 5,
        reactions: 'string_invalida',
      });
      expect(result.ok).toBe(false);
    });

    it('rejeita payload sem message_id', () => {
      const result = validateEventPayload(REALTIME_EVENTS.MESSAGE_REACTIONS_UPDATED, { reactions: [] });
      expect(result.ok).toBe(false);
    });
  });

  describe('CONVERSATION_TAGS_UPDATED', () => {
    it('aceita payload com conversation_id e tags como array', () => {
      const result = validateEventPayload(REALTIME_EVENTS.CONVERSATION_TAGS_UPDATED, {
        conversation_id: 1,
        tags: [{ id: 1, name: 'urgente', color: '#ff0000' }],
      });
      expect(result.ok).toBe(true);
    });

    it('aceita tags como array vazio', () => {
      const result = validateEventPayload(REALTIME_EVENTS.CONVERSATION_TAGS_UPDATED, {
        conversation_id: 1,
        tags: [],
      });
      expect(result.ok).toBe(true);
    });

    it('rejeita payload sem tags', () => {
      const result = validateEventPayload(REALTIME_EVENTS.CONVERSATION_TAGS_UPDATED, {
        conversation_id: 1,
      });
      expect(result.ok).toBe(false);
    });

    it('rejeita tags não-array', () => {
      const result = validateEventPayload(REALTIME_EVENTS.CONVERSATION_TAGS_UPDATED, {
        conversation_id: 1,
        tags: 'string_invalida',
      });
      expect(result.ok).toBe(false);
    });

    it('rejeita payload sem conversation_id', () => {
      const result = validateEventPayload(REALTIME_EVENTS.CONVERSATION_TAGS_UPDATED, { tags: [] });
      expect(result.ok).toBe(false);
    });
  });

  describe('NOTIFICATION_CREATED', () => {
    it('aceita payload com notification como objeto', () => {
      const result = validateEventPayload(REALTIME_EVENTS.NOTIFICATION_CREATED, {
        notification: { id: 1, message: 'Nova conversa' },
      });
      expect(result.ok).toBe(true);
    });

    it('rejeita payload sem notification', () => {
      const result = validateEventPayload(REALTIME_EVENTS.NOTIFICATION_CREATED, { id: 1 });
      expect(result.ok).toBe(false);
    });

    it('rejeita notification como string', () => {
      const result = validateEventPayload(REALTIME_EVENTS.NOTIFICATION_CREATED, {
        notification: 'texto',
      });
      expect(result.ok).toBe(false);
    });

    it('rejeita notification como array', () => {
      const result = validateEventPayload(REALTIME_EVENTS.NOTIFICATION_CREATED, {
        notification: [{ id: 1 }],
      });
      expect(result.ok).toBe(false);
    });
  });

  describe('APPOINTMENT_CREATED / APPOINTMENT_UPDATED', () => {
    it('aceita APPOINTMENT_CREATED com appointment válido', () => {
      const result = validateEventPayload(REALTIME_EVENTS.APPOINTMENT_CREATED, {
        appointment: { id: 10, customer_name: 'João' },
      });
      expect(result.ok).toBe(true);
    });

    it('rejeita APPOINTMENT_UPDATED sem appointment', () => {
      const result = validateEventPayload(REALTIME_EVENTS.APPOINTMENT_UPDATED, { id: 10 });
      expect(result.ok).toBe(false);
    });
  });
});
