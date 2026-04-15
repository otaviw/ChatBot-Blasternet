import { describe, expect, it } from 'vitest';
import { readMessagePayload } from './readMessagePayload';

describe('readMessagePayload', () => {
  it('retorna payload.message quando for objeto', () => {
    const payload = { message: { id: 10, body: 'oi' } };

    expect(readMessagePayload(payload)).toEqual({ id: 10, body: 'oi' });
  });

  it('retorna null quando payload for inválido', () => {
    expect(readMessagePayload(null)).toBe(null);
  });

  it('retorna null quando message não for objeto', () => {
    expect(readMessagePayload({ message: 'texto' })).toBe(null);
  });
});
