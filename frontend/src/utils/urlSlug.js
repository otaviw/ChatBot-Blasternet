const RESERVED_ROUTES = new Set([
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

function readPathname(url) {
  if (typeof url !== 'string') {
    return '';
  }

  const input = url.trim();
  if (!input) {
    return '';
  }

  // Accept both full URL and path-only inputs.
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
