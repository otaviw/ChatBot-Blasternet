export const RESERVED_SLUGS = new Set([
  'login',
  'entrar',
  'dashboard',
  'api',
  'admin',
  'esqueceu-senha',
  'redefinir-senha',
  'suporte',
  'minha-conta',
  'companies',
]);

const RESERVED_ROUTES = RESERVED_SLUGS;

export const SLUG_REGEX = /^[a-z0-9-]+$/;

/**
 * Verifica se um slug é reservado pelo sistema de rotas.
 * Útil para validação em formulários antes de submeter ao backend.
 */
export function isReservedSlug(slug) {
  if (typeof slug !== 'string' || slug.trim() === '') {
    return false;
  }
  return RESERVED_SLUGS.has(slug.trim().toLowerCase());
}

/**
 * Verifica se um slug é válido: não reservado e com formato kebab-case correto.
 */
export function isValidSlug(slug) {
  if (typeof slug !== 'string' || slug.trim() === '') {
    return false;
  }
  const trimmed = slug.trim();
  return SLUG_REGEX.test(trimmed) && !RESERVED_SLUGS.has(trimmed.toLowerCase());
}

function readPathname(url) {
  if (typeof url !== 'string') {
    return '';
  }

  const input = url.trim();
  if (!input) {
    return '';
  }

  if (input.startsWith('/')) {
    return input;
  }

  try {
    return new URL(input).pathname;
  } catch (_error) {
    return '';
  }
}

export function getSlugFromUrl(url) {
  const pathname = readPathname(url);
  const firstSegment = pathname
    .split('/')
    .map((segment) => segment.trim())
    .find(Boolean);

  if (!firstSegment) {
    return null;
  }

  const normalizedSegment = firstSegment.toLowerCase();
  if (RESERVED_ROUTES.has(normalizedSegment)) {
    return null;
  }

  return firstSegment;
}
