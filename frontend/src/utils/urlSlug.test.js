import { describe, expect, it } from 'vitest';
import { getSlugFromUrl } from './urlSlug';

describe('getSlugFromUrl', () => {
  it('retorna o primeiro segmento quando nao for rota reservada', () => {
    expect(getSlugFromUrl('/acme/dashboard')).toBe('acme');
  });

  it('ignora rotas reservadas', () => {
    expect(getSlugFromUrl('/login')).toBeNull();
    expect(getSlugFromUrl('/entrar')).toBeNull();
    expect(getSlugFromUrl('/dashboard')).toBeNull();
    expect(getSlugFromUrl('/api/users')).toBeNull();
    expect(getSlugFromUrl('/admin/empresas')).toBeNull();
  });

  it('aceita URL completa e ignora query/hash', () => {
    expect(getSlugFromUrl('https://example.com/minha-empresa/conversas?tab=1#section')).toBe(
      'minha-empresa'
    );
  });

  it('retorna null para caminho vazio ou invalido', () => {
    expect(getSlugFromUrl('')).toBeNull();
    expect(getSlugFromUrl('/')).toBeNull();
    expect(getSlugFromUrl('not-a-valid-url')).toBeNull();
  });

  it('trata rotas reservadas sem diferenciar maiusculas e minusculas', () => {
    expect(getSlugFromUrl('/Admin')).toBeNull();
    expect(getSlugFromUrl('/LOGIN')).toBeNull();
  });
});
