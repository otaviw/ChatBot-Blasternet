import useNotifications from '@/hooks/useNotifications';
import { NotificationsContext } from './notificationsContextObject';

export function NotificationsProvider({ children, enabled = true, autoLoad = true, limit = 50 }) {
  const state = useNotifications({ enabled, autoLoad, limit });

  return (
    <NotificationsContext.Provider value={state}>
      {children}
    </NotificationsContext.Provider>
  );
}
