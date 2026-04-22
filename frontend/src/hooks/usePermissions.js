import useAuth from './useAuth';
import { hasPermission } from '@/constants/permissions';

/**
 * Returns a `can(permission)` function for the currently authenticated user.
 * Admins always return true; agents use their resolved permissions array.
 */
export default function usePermissions() {
  const { user } = useAuth();

  function can(permission) {
    if (!user) return false;
    return hasPermission(user.permissions ?? null, user.role, permission);
  }

  return { can, permissions: user?.permissions ?? null, role: user?.role ?? null };
}
