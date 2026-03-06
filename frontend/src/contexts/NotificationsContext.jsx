import { createContext, useContext } from 'react';
import useNotifications from '@/hooks/useNotifications';

const NotificationsContext = createContext(null);

export function NotificationsProvider({ children, enabled = true, autoLoad = true, limit = 50 }) {
  const state = useNotifications({ enabled, autoLoad, limit });

  return (
    <NotificationsContext.Provider value={state}>
      {children}
    </NotificationsContext.Provider>
  );
}

export function useNotificationsContext() {
  const context = useContext(NotificationsContext);
  if (!context) {
    throw new Error('useNotificationsContext deve ser usado dentro de NotificationsProvider.');
  }

  return context;
}
