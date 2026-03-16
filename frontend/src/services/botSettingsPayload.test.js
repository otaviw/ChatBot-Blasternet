import { describe, expect, it } from 'vitest';
import {
  coerceInactivityCloseHours,
  normalizeKeywordReplies,
  normalizeServiceAreas,
} from './botSettingsPayload';

describe('botSettingsPayload', () => {
  describe('normalizeServiceAreas', () => {
    it('remove vazios e dedup case-insensitive preservando o primeiro label', () => {
      expect(normalizeServiceAreas(['  Suporte ', 'suporte', '', null, 'Vendas'])).toEqual([
        'Suporte',
        'Vendas',
      ]);
    });

    it('retorna [] quando entrada for null', () => {
      expect(normalizeServiceAreas(null)).toEqual([]);
    });
  });

  describe('normalizeKeywordReplies', () => {
    it('mantém apenas itens com keyword e reply preenchidos', () => {
      expect(
        normalizeKeywordReplies([
          { keyword: 'a', reply: 'b' },
          { keyword: ' ', reply: 'b' },
          { keyword: 'a', reply: ' ' },
          null,
        ]),
      ).toEqual([{ keyword: 'a', reply: 'b' }]);
    });

    it('retorna [] quando entrada for null', () => {
      expect(normalizeKeywordReplies(null)).toEqual([]);
    });
  });

  describe('coerceInactivityCloseHours', () => {
    it('usa fallback quando valor é inválido', () => {
      expect(coerceInactivityCloseHours('abc', 24)).toBe(24);
      expect(coerceInactivityCloseHours(0, 24)).toBe(24);
      expect(coerceInactivityCloseHours(-1, 24)).toBe(24);
    });

    it('converte valor válido', () => {
      expect(coerceInactivityCloseHours('12', 24)).toBe(12);
      expect(coerceInactivityCloseHours(48, 24)).toBe(48);
    });
  });
});

