import { describe, expect, it } from 'vitest';
import { getSlugFromUrl, isReservedSlug, isValidSlug, RESERVED_SLUGS, SLUG_REGEX } from './urlSlug';

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

describe('isReservedSlug', () => {
  it('retorna true para slugs reservados', () => {
    expect(isReservedSlug('login')).toBe(true);
    expect(isReservedSlug('dashboard')).toBe(true);
    expect(isReservedSlug('api')).toBe(true);
    expect(isReservedSlug('admin')).toBe(true);
  });

  it('e case-insensitive', () => {
    expect(isReservedSlug('Admin')).toBe(true);
    expect(isReservedSlug('LOGIN')).toBe(true);
  });

  it('retorna false para slugs validos', () => {
    expect(isReservedSlug('minha-empresa')).toBe(false);
    expect(isReservedSlug('acme')).toBe(false);
  });

  it('retorna false para valor vazio ou invalido', () => {
    expect(isReservedSlug('')).toBe(false);
    expect(isReservedSlug(null)).toBe(false);
    expect(isReservedSlug(undefined)).toBe(false);
  });
});

describe('isValidSlug', () => {
  it('retorna true para slugs validos', () => {
    expect(isValidSlug('minha-empresa')).toBe(true);
    expect(isValidSlug('acme123')).toBe(true);
    expect(isValidSlug('empresa-x')).toBe(true);
  });

  it('retorna false para slugs reservados', () => {
    expect(isValidSlug('login')).toBe(false);
    expect(isValidSlug('admin')).toBe(false);
    expect(isValidSlug('api')).toBe(false);
    expect(isValidSlug('dashboard')).toBe(false);
  });

  it('retorna false para formato invalido', () => {
    expect(isValidSlug('Minha Empresa')).toBe(false);
    expect(isValidSlug('empresa_x')).toBe(false);
    expect(isValidSlug('ACME')).toBe(false);
    expect(isValidSlug('')).toBe(false);
  });

  it('retorna false para valor nao string', () => {
    expect(isValidSlug(null)).toBe(false);
    expect(isValidSlug(undefined)).toBe(false);
  });
});

describe('RESERVED_SLUGS', () => {
  it('contem as rotas minimas exigidas', () => {
    expect(RESERVED_SLUGS.has('login')).toBe(true);
    expect(RESERVED_SLUGS.has('dashboard')).toBe(true);
    expect(RESERVED_SLUGS.has('api')).toBe(true);
    expect(RESERVED_SLUGS.has('admin')).toBe(true);
  });
});

describe('SLUG_REGEX', () => {
  it('aceita kebab-case valido', () => {
    expect(SLUG_REGEX.test('minha-empresa')).toBe(true);
    expect(SLUG_REGEX.test('acme123')).toBe(true);
  });

  it('rejeita formatos invalidos', () => {
    expect(SLUG_REGEX.test('Minha Empresa')).toBe(false);
    expect(SLUG_REGEX.test('empresa_x')).toBe(false);
    expect(SLUG_REGEX.test('')).toBe(false);
  });
});
