import { REALTIME_EVENTS } from '@/constants/realtimeEvents';
import { readPositiveInt } from './readPositiveInt';

/**
 * Valida o payload de um evento realtime antes de despachar para os handlers.
 * Retorna { ok: true } se o payload é válido, ou { ok: false, error: string } se não.
 *
 * Eventos sem validador específico são aceitos sem restrições para manter
 * compatibilidade quando novos eventos são adicionados no servidor.
 */
export function validateEventPayload(eventName, payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    return { ok: false, error: 'payload deve ser um objeto' };
  }

  const validator = VALIDATORS[eventName];
  if (!validator) {
    return { ok: true };
  }

  return validator(payload);
}

function requireConversationId(payload) {
  const id = readPositiveInt(payload, ['conversation_id', 'conversationId']);
  if (!id) {
    return { ok: false, error: 'conversation_id ausente ou inválido' };
  }
  return null;
}

const VALIDATORS = {
  [REALTIME_EVENTS.MESSAGE_CREATED]: (payload) => {
    return requireConversationId(payload) ?? { ok: true };
  },

  [REALTIME_EVENTS.MESSAGE_UPDATED]: (payload) => {
    return requireConversationId(payload) ?? { ok: true };
  },

  [REALTIME_EVENTS.MESSAGE_STATUS_UPDATED]: (payload) => {
    const id = readPositiveInt(payload, ['message_id', 'messageId', 'id']);
    if (!id) return { ok: false, error: 'message_id ausente ou inválido' };
    return { ok: true };
  },

  [REALTIME_EVENTS.MESSAGE_REACTIONS_UPDATED]: (payload) => {
    const id = readPositiveInt(payload, ['message_id', 'messageId', 'id']);
    if (!id) return { ok: false, error: 'message_id ausente ou inválido' };
    if (payload.reactions !== undefined && !Array.isArray(payload.reactions)) {
      return { ok: false, error: 'reactions deve ser um array' };
    }
    return { ok: true };
  },

  [REALTIME_EVENTS.CONVERSATION_TRANSFERRED]: (payload) => {
    return requireConversationId(payload) ?? { ok: true };
  },

  [REALTIME_EVENTS.CONVERSATION_TAGS_UPDATED]: (payload) => {
    const conversationError = requireConversationId(payload);
    if (conversationError) return conversationError;
    if (!Array.isArray(payload.tags)) {
      return { ok: false, error: 'tags deve ser um array' };
    }
    return { ok: true };
  },

  [REALTIME_EVENTS.CUSTOMER_TYPING]: (payload) => {
    return requireConversationId(payload) ?? { ok: true };
  },

  [REALTIME_EVENTS.NOTIFICATION_CREATED]: (payload) => {
    if (!payload.notification || typeof payload.notification !== 'object' || Array.isArray(payload.notification)) {
      return { ok: false, error: 'notification ausente ou inválido' };
    }
    return { ok: true };
  },

  [REALTIME_EVENTS.APPOINTMENT_CREATED]: (payload) => {
    if (!payload.appointment || typeof payload.appointment !== 'object') {
      return { ok: false, error: 'appointment ausente ou inválido' };
    }
    return { ok: true };
  },

  [REALTIME_EVENTS.APPOINTMENT_UPDATED]: (payload) => {
    if (!payload.appointment || typeof payload.appointment !== 'object') {
      return { ok: false, error: 'appointment ausente ou inválido' };
    }
    return { ok: true };
  },
};
