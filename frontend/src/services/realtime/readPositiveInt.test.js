import { describe, expect, it } from 'vitest';
import { readPositiveInt } from './readPositiveInt';

describe('readPositiveInt', () => {
  it('retorna o primeiro inteiro positivo encontrado', () => {
    expect(readPositiveInt({ a: '0', b: '12' }, ['a', 'b'])).toBe(12);
  });

  it('retorna null quando nao encontrar valor valido', () => {
    expect(readPositiveInt({ a: '0', b: '-5' }, ['a', 'b'])).toBe(null);
  });

  it('retorna null quando source nao for objeto', () => {
    expect(readPositiveInt(null, ['id'])).toBe(null);
  });
});
