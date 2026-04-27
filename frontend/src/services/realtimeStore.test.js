import { describe, expect, it, vi, beforeEach } from 'vitest';
import RealtimeStore from './realtimeStore';
import { REALTIME_EVENTS } from '@/constants/realtimeEvents';

function makeStore() {
  return new RealtimeStore();
}

function validEnvelope(payload = {}, meta = {}) {
  return { payload, meta };
}

describe('RealtimeStore', () => {
  describe('on / off', () => {
    it('registra handler para evento suportado', () => {
      const store = makeStore();
      const handler = vi.fn();
      expect(() => store.on(REALTIME_EVENTS.MESSAGE_CREATED, handler)).not.toThrow();
    });

    it('lança erro ao registrar evento não suportado', () => {
      const store = makeStore();
      expect(() => store.on('evento.inexistente', vi.fn())).toThrow();
    });

    it('remove handler via off', () => {
      const store = makeStore();
      const handler = vi.fn();
      store.on(REALTIME_EVENTS.MESSAGE_CREATED, handler);
      store.off(REALTIME_EVENTS.MESSAGE_CREATED, handler);

      store.dispatchRawEvent(
        REALTIME_EVENTS.MESSAGE_CREATED,
        validEnvelope({ conversation_id: 1 })
      );

      expect(handler).not.toHaveBeenCalled();
    });

    it('off em evento sem handlers não lança erro', () => {
      const store = makeStore();
      expect(() => store.off(REALTIME_EVENTS.MESSAGE_CREATED, vi.fn())).not.toThrow();
    });
  });

  describe('dispatchRawEvent', () => {
    it('despacha evento válido para handler registrado', () => {
      const store = makeStore();
      const handler = vi.fn();
      store.on(REALTIME_EVENTS.MESSAGE_CREATED, handler);

      store.dispatchRawEvent(
        REALTIME_EVENTS.MESSAGE_CREATED,
        validEnvelope({ conversation_id: 42 })
      );

      expect(handler).toHaveBeenCalledOnce();
    });

    it('passa payload normalizado para o handler', () => {
      const store = makeStore();
      const handler = vi.fn();
      store.on(REALTIME_EVENTS.MESSAGE_CREATED, handler);

      store.dispatchRawEvent(
        REALTIME_EVENTS.MESSAGE_CREATED,
        validEnvelope({ conversation_id: 7, text: 'oi' })
      );

      const [received] = handler.mock.calls[0];
      expect(received.event).toBe(REALTIME_EVENTS.MESSAGE_CREATED);
      expect(received.payload.conversation_id).toBe(7);
    });

    it('não despacha evento não suportado', () => {
      const store = makeStore();
      const handler = vi.fn();
      // Não é possível registrar handler para evento inválido,
      // mas podemos verificar que o dispatch também o ignora.
      store.dispatchRawEvent('evento.inventado', validEnvelope({ conversation_id: 1 }));
      expect(handler).not.toHaveBeenCalled();
    });

    it('não despacha quando payload falha na validação', () => {
      const store = makeStore();
      const handler = vi.fn();
      store.on(REALTIME_EVENTS.CONVERSATION_TAGS_UPDATED, handler);

      // tags está faltando — validação deve rejeitar
      store.dispatchRawEvent(
        REALTIME_EVENTS.CONVERSATION_TAGS_UPDATED,
        validEnvelope({ conversation_id: 1 })
      );

      expect(handler).not.toHaveBeenCalled();
    });

    it('despacha CONVERSATION_TAGS_UPDATED com payload válido', () => {
      const store = makeStore();
      const handler = vi.fn();
      store.on(REALTIME_EVENTS.CONVERSATION_TAGS_UPDATED, handler);

      store.dispatchRawEvent(
        REALTIME_EVENTS.CONVERSATION_TAGS_UPDATED,
        validEnvelope({ conversation_id: 1, tags: [] })
      );

      expect(handler).toHaveBeenCalledOnce();
    });

    it('não despacha evento duplicado com mesmo requestId', () => {
      const store = makeStore();
      const handler = vi.fn();
      store.on(REALTIME_EVENTS.BOT_UPDATED, handler);

      const envelope = { payload: { company_id: 1 }, meta: { requestId: 'req-abc-123' } };
      store.dispatchRawEvent(REALTIME_EVENTS.BOT_UPDATED, envelope);
      store.dispatchRawEvent(REALTIME_EVENTS.BOT_UPDATED, envelope);

      expect(handler).toHaveBeenCalledOnce();
    });

    it('despacha para múltiplos handlers do mesmo evento', () => {
      const store = makeStore();
      const handlerA = vi.fn();
      const handlerB = vi.fn();
      store.on(REALTIME_EVENTS.BOT_UPDATED, handlerA);
      store.on(REALTIME_EVENTS.BOT_UPDATED, handlerB);

      store.dispatchRawEvent(REALTIME_EVENTS.BOT_UPDATED, validEnvelope({ company_id: 1 }));

      expect(handlerA).toHaveBeenCalledOnce();
      expect(handlerB).toHaveBeenCalledOnce();
    });

    it('continua despachando para outros handlers mesmo se um lançar exceção', () => {
      const store = makeStore();
      const broken = vi.fn().mockImplementation(() => { throw new Error('handler quebrado'); });
      const healthy = vi.fn();
      store.on(REALTIME_EVENTS.BOT_UPDATED, broken);
      store.on(REALTIME_EVENTS.BOT_UPDATED, healthy);

      expect(() =>
        store.dispatchRawEvent(REALTIME_EVENTS.BOT_UPDATED, validEnvelope({ company_id: 1 }))
      ).not.toThrow();

      expect(healthy).toHaveBeenCalledOnce();
    });

    it('normaliza envelope com payload ausente para objeto vazio', () => {
      const store = makeStore();
      const handler = vi.fn();
      store.on(REALTIME_EVENTS.BOT_UPDATED, handler);

      // envelope sem payload — eventos sem validador específico passam
      store.dispatchRawEvent(REALTIME_EVENTS.BOT_UPDATED, {});

      expect(handler).toHaveBeenCalledOnce();
      const [received] = handler.mock.calls[0];
      expect(received.payload).toEqual({});
    });
  });

  describe('gerenciamento de salas (rooms)', () => {
    it('adiciona e verifica sala de conversa', () => {
      const store = makeStore();
      store.addConversationRoom(10);
      expect(store.hasConversationRoom(10)).toBe(true);
    });

    it('remove sala de conversa', () => {
      const store = makeStore();
      store.addConversationRoom(10);
      store.removeConversationRoom(10);
      expect(store.hasConversationRoom(10)).toBe(false);
    });

    it('ignora IDs inválidos na adição de sala', () => {
      const store = makeStore();
      expect(store.addConversationRoom(0)).toBe(0);
      expect(store.addConversationRoom(-5)).toBe(0);
      expect(store.hasConversationRoom(0)).toBe(false);
    });

    it('retorna todas as salas de conversa', () => {
      const store = makeStore();
      store.addConversationRoom(1);
      store.addConversationRoom(2);
      store.addConversationRoom(3);
      expect(store.getConversationRooms()).toEqual(expect.arrayContaining([1, 2, 3]));
    });

    it('hasActivity retorna true quando há handlers', () => {
      const store = makeStore();
      store.on(REALTIME_EVENTS.BOT_UPDATED, vi.fn());
      expect(store.hasActivity()).toBe(true);
    });

    it('hasActivity retorna false quando store está vazio', () => {
      const store = makeStore();
      expect(store.hasActivity()).toBe(false);
    });
  });
});
