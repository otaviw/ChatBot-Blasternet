import { useContext } from 'react';
import { NotificationsContext } from '@/contexts/notificationsContextObject';

export function useNotificationsContext() {
  const context = useContext(NotificationsContext);
  if (!context) {
    throw new Error('useNotificationsContext deve ser usado dentro de NotificationsProvider.');
  }

  return context;
}
