import { describe, expect, it } from 'vitest';
import {
  canRequestMessageSend,
  mergeMessagesById,
  parseRequestErrorMessage,
} from './internalAiChatUtils';

describe('internalAiChatUtils', () => {
  describe('parseRequestErrorMessage', () => {
    it('prioriza a mensagem da API para erro 422', () => {
      const error = { response: { status: 422, data: { message: 'Conteudo invalido.' } } };

      expect(
        parseRequestErrorMessage(error, {
          fallback422: 'Fallback 422',
          fallback404: 'Fallback 404',
          fallbackUnexpected: 'Fallback geral',
        }),
      ).toBe('Conteudo invalido.');
    });

    it('usa fallback de 422 quando nao houver mensagem da API', () => {
      const error = { response: { status: 422, data: {} } };

      expect(
        parseRequestErrorMessage(error, {
          fallback422: 'Fallback 422',
          fallback404: 'Fallback 404',
          fallbackUnexpected: 'Fallback geral',
        }),
      ).toBe('Fallback 422');
    });

    it('usa fallback de 404 quando nao houver mensagem da API', () => {
      const error = { response: { status: 404, data: {} } };

      expect(
        parseRequestErrorMessage(error, {
          fallback422: 'Fallback 422',
          fallback404: 'Fallback 404',
          fallbackUnexpected: 'Fallback geral',
        }),
      ).toBe('Fallback 404');
    });

    it('usa fallback inesperado para erro sem status conhecido', () => {
      expect(
        parseRequestErrorMessage(
          {},
          {
            fallback422: 'Fallback 422',
            fallback404: 'Fallback 404',
            fallbackUnexpected: 'Fallback geral',
          },
        ),
      ).toBe('Fallback geral');
    });
  });

  describe('mergeMessagesById', () => {
    it('mescla por id, preserva meta e ordena cronologicamente', () => {
      const merged = mergeMessagesById(
        [
          {
            id: 2,
            role: 'assistant',
            content: 'resposta antiga',
            created_at: '2026-03-20T10:00:00.000Z',
            meta: { source: 'old' },
          },
          {
            id: 1,
            role: 'user',
            content: 'mensagem do usuario',
            created_at: '2026-03-20T09:59:00.000Z',
          },
        ],
        [
          {
            id: 2,
            role: 'assistant',
            content: 'resposta atualizada',
            created_at: '2026-03-20T10:00:00.000Z',
          },
          null,
        ],
      );

      expect(merged.map((message) => message.id)).toEqual([1, 2]);
      expect(merged[1].content).toBe('resposta atualizada');
      expect(merged[1].meta).toEqual({ source: 'old' });
    });
  });

  describe('canRequestMessageSend', () => {
    it('bloqueia envio quando nao ha conversa selecionada', () => {
      expect(canRequestMessageSend({ conversationId: null, sendBusy: false })).toBe(false);
      expect(canRequestMessageSend({ conversationId: 0, sendBusy: false })).toBe(false);
    });

    it('bloqueia envio quando ja existe envio em andamento', () => {
      expect(canRequestMessageSend({ conversationId: 10, sendBusy: true })).toBe(false);
    });

    it('permite envio quando conversa eh valida e nao esta ocupada', () => {
      expect(canRequestMessageSend({ conversationId: 10, sendBusy: false })).toBe(true);
      expect(canRequestMessageSend({ conversationId: '10', sendBusy: false })).toBe(true);
    });
  });
});
