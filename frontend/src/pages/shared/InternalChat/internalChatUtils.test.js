import { describe, expect, it } from 'vitest';
import { formatDateTime, parseErrorMessage, parseRoleFromUser, toTimestamp } from './internalChatUtils';

describe('internalChatUtils', () => {
  describe('toTimestamp', () => {
    it('retorna 0 para valores vazios', () => {
      expect(toTimestamp(null)).toBe(0);
      expect(toTimestamp('')).toBe(0);
    });

    it('retorna 0 para data inválida', () => {
      expect(toTimestamp('invalid')).toBe(0);
    });

    it('retorna timestamp para data válida', () => {
      expect(toTimestamp('2026-03-16T00:00:00.000Z')).toBeGreaterThan(0);
    });
  });

  describe('formatDateTime', () => {
    it('retorna string vazia quando data é inválida', () => {
      expect(formatDateTime('invalid')).toBe('');
      expect(formatDateTime(null)).toBe('');
    });

    it('retorna string pt-BR para data válida', () => {
      const value = formatDateTime('2026-03-16T00:00:00.000Z');
      expect(typeof value).toBe('string');
      expect(value.length).toBeGreaterThan(0);
    });
  });

  describe('parseRoleFromUser', () => {
    it('normaliza system_admin para admin', () => {
      expect(parseRoleFromUser({ role: 'system_admin' })).toBe('admin');
      expect(parseRoleFromUser({ role: '  SYSTEM_ADMIN ' })).toBe('admin');
    });

    it('retorna company como padrão', () => {
      expect(parseRoleFromUser({ role: 'company_admin' })).toBe('company');
      expect(parseRoleFromUser(null)).toBe('company');
    });
  });

  describe('parseErrorMessage', () => {
    it('usa mensagem do backend quando disponível', () => {
      const err = { response: { data: { message: 'Falhou' } } };
      expect(parseErrorMessage(err, 'fallback')).toBe('Falhou');
    });

    it('usa fallback quando não houver mensagem', () => {
      expect(parseErrorMessage({}, 'fallback')).toBe('fallback');
    });
  });
});

