import { getSlugFromUrl } from '@/utils/urlSlug';

export function getScopedAuthPaths(pathname) {
  const slug = getSlugFromUrl(pathname);

  return {
    slug,
    loginPath: slug ? `/${slug}/entrar` : '/entrar',
    dashboardPath: slug ? `/${slug}/dashboard` : '/dashboard',
  };
}

export function isLoginPath(pathname) {
  const normalizedPath = String(pathname ?? '').trim().toLowerCase();

  return (
    normalizedPath === '/entrar'
    || normalizedPath === '/login'
    || /\/[^/]+\/entrar$/.test(normalizedPath)
    || /\/[^/]+\/login$/.test(normalizedPath)
  );
}
