import { describe, expect, it } from 'vitest';
import {
  appendUniqueMessage,
  buildRealtimeMessage,
  buildRealtimeMessageReactionsPatch,
  buildRealtimeMessageStatusPatch,
  conversationActivityTimestamp,
  mergeMessagesChronologically,
  normalizeEventConversation,
  normalizeMessageReactions,
  sortConversationsByActivity,
  toTimestamp,
} from './inboxRealtimeUtils';

describe('inboxRealtimeUtils', () => {
  describe('toTimestamp', () => {
    it('retorna 0 para valores falsy', () => {
      expect(toTimestamp(null)).toBe(0);
      expect(toTimestamp(undefined)).toBe(0);
      expect(toTimestamp('')).toBe(0);
    });

    it('retorna 0 para data inválida', () => {
      expect(toTimestamp('not-a-date')).toBe(0);
    });

    it('retorna timestamp válido para ISO string', () => {
      expect(toTimestamp('2026-03-16T10:00:00.000Z')).toBeGreaterThan(0);
    });
  });

  describe('conversationActivityTimestamp', () => {
    it('usa o maior entre last_message_at/updated_at/created_at', () => {
      const conv = {
        created_at: '2026-03-10T00:00:00.000Z',
        updated_at: '2026-03-12T00:00:00.000Z',
        last_message_at: '2026-03-15T00:00:00.000Z',
      };
      expect(conversationActivityTimestamp(conv)).toBe(toTimestamp(conv.last_message_at));
    });
  });

  describe('sortConversationsByActivity', () => {
    it('ordena por atividade desc e depois por id desc', () => {
      const base = '2026-03-10T00:00:00.000Z';
      const items = [
        { id: 10, updated_at: base },
        { id: 11, updated_at: base },
        { id: 1, updated_at: '2026-03-11T00:00:00.000Z' },
      ];
      const sorted = sortConversationsByActivity(items);
      expect(sorted.map((c) => c.id)).toEqual([1, 11, 10]);
      expect(items.map((c) => c.id)).toEqual([10, 11, 1]); // não muta o array original
    });
  });

  describe('appendUniqueMessage', () => {
    it('não adiciona quando message é inválida', () => {
      const current = [{ id: 1 }];
      expect(appendUniqueMessage(current, null)).toBe(current);
      expect(appendUniqueMessage(current, { text: 'x' })).toBe(current);
    });

    it('não duplica mensagens pelo id', () => {
      const current = [{ id: 1, text: 'a' }];
      const next = appendUniqueMessage(current, { id: 1, text: 'b' });
      expect(next).toEqual([{ id: 1, text: 'a' }]);
    });

    it('adiciona quando id não existe', () => {
      const current = [{ id: 1 }];
      const next = appendUniqueMessage(current, { id: 2 });
      expect(next).toEqual([{ id: 1 }, { id: 2 }]);
    });
  });

  describe('mergeMessagesChronologically', () => {
    it('mescla por id e ordena crescente por id, preferindo campos do incoming', () => {
      const current = [{ id: 2, text: 'old' }, { id: 1, text: 'a' }];
      const incoming = [{ id: 2, text: 'new', delivery_status: 'sent' }, { id: 3, text: 'c' }];
      expect(mergeMessagesChronologically(current, incoming)).toEqual([
        { id: 1, text: 'a' },
        { id: 2, text: 'new', delivery_status: 'sent' },
        { id: 3, text: 'c' },
      ]);
    });
  });

  describe('normalizeEventConversation', () => {
    it('retorna null quando payload não é objeto', () => {
      expect(normalizeEventConversation(null)).toBe(null);
      expect(normalizeEventConversation('x')).toBe(null);
    });

    it('normaliza id a partir de id', () => {
      expect(normalizeEventConversation({ id: '10' })).toEqual({ id: 10 });
    });

    it('normaliza id a partir de conversation_id', () => {
      expect(normalizeEventConversation({ conversation_id: '11', foo: 'bar' })).toEqual({
        conversation_id: '11',
        foo: 'bar',
        id: 11,
      });
    });
  });

  describe('normalizeMessageReactions', () => {
    it('retorna [] quando reactions não é array', () => {
      expect(normalizeMessageReactions(null)).toEqual([]);
      expect(normalizeMessageReactions({})).toEqual([]);
    });

    it('filtra itens inválidos e normaliza campos', () => {
      const raw = [
        null,
        { emoji: '' },
        { emoji: '👍', reactorPhone: ' 5511999 ', reactedAt: 123, id: '7' },
        { emoji: '🔥', reactor_phone: ' 5511888 ', reacted_at: '2026-03-16T00:00:00Z' },
      ];
      expect(normalizeMessageReactions(raw)).toEqual([
        {
          id: 7,
          reactor_phone: '5511999',
          emoji: '👍',
          reacted_at: '123',
        },
        {
          id: null,
          reactor_phone: '5511888',
          emoji: '🔥',
          reacted_at: '2026-03-16T00:00:00Z',
        },
      ]);
    });
  });

  describe('buildRealtimeMessage', () => {
    it('faz fallback para payload.message quando campos estão aninhados', () => {
      const msg = buildRealtimeMessage(
        {
          message: {
            direction: 'in',
            type: 'text',
            text: 'oi',
            content_type: 'text',
            media_url: 'x',
          },
        },
        55,
        99,
      );
      expect(msg).toMatchObject({
        id: 99,
        conversation_id: 55,
        direction: 'in',
        type: 'text',
        text: 'oi',
        content_type: 'text',
        media_url: 'x',
      });
    });
  });

  describe('buildRealtimeMessageStatusPatch', () => {
    it('monta patch mínimo com fallback de nomes', () => {
      expect(
        buildRealtimeMessageStatusPatch(
          { whatsapp_message_id: 'w', deliveryStatus: 'delivered', delivered_at: 'x' },
          2,
          3,
        ),
      ).toEqual({
        id: 3,
        conversation_id: 2,
        whatsapp_message_id: 'w',
        delivery_status: 'delivered',
        sent_at: null,
        delivered_at: 'x',
        read_at: null,
        failed_at: null,
      });
    });
  });

  describe('buildRealtimeMessageReactionsPatch', () => {
    it('normaliza reactions no patch', () => {
      expect(
        buildRealtimeMessageReactionsPatch({ reactions: [{ emoji: '👍', reactor_phone: '1' }] }, 1, 2),
      ).toEqual({
        id: 2,
        conversation_id: 1,
        reactions: [
          {
            id: null,
            reactor_phone: '1',
            emoji: '👍',
            reacted_at: null,
          },
        ],
      });
    });
  });
});

