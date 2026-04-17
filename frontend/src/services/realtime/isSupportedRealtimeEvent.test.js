import { describe, expect, it } from 'vitest';
import { isSupportedRealtimeEvent } from './isSupportedRealtimeEvent';

describe('isSupportedRealtimeEvent', () => {
  it('retorna true para evento suportado', () => {
    expect(isSupportedRealtimeEvent('message.created')).toBe(true);
  });

  it('retorna true para evento de campanha', () => {
    expect(isSupportedRealtimeEvent('campaign.updated')).toBe(true);
  });

  it('retorna false para evento desconhecido', () => {
    expect(isSupportedRealtimeEvent('abc.xyz')).toBe(false);
  });
});
