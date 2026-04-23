import './App.css';
import { useEffect, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import AppRoutes from './routes';
import { NotificationsProvider } from '@/contexts/NotificationsContext';
import { BrandProvider } from '@/contexts/BrandContext';
import ErrorBoundary from '@/components/ui/ErrorBoundary/ErrorBoundary.jsx';
import AppToaster from '@/components/ui/AppToaster/AppToaster.jsx';
import { useNotificationsContext } from '@/hooks/useNotificationsContext';
import useFaviconBadge from '@/hooks/useFaviconBadge';
import { isLoginPath } from '@/utils/tenantRouting';

function NotificationBadgeSync() {
  const location = useLocation();
  const { totalUnread } = useNotificationsContext();
  const [isTabFocused, setIsTabFocused] = useState(
    () => document.visibilityState === 'visible' && document.hasFocus()
  );

  useEffect(() => {
    const updateFocusState = () => {
      setIsTabFocused(document.visibilityState === 'visible' && document.hasFocus());
    };

    document.addEventListener('visibilitychange', updateFocusState);
    window.addEventListener('focus', updateFocusState);
    window.addEventListener('blur', updateFocusState);

    return () => {
      document.removeEventListener('visibilitychange', updateFocusState);
      window.removeEventListener('focus', updateFocusState);
      window.removeEventListener('blur', updateFocusState);
    };
  }, []);

  const isInboxRoute = location.pathname === '/minha-conta/conversas' || location.pathname === '/admin/conversas';
  const badgeCount = useMemo(
    () => (isInboxRoute && isTabFocused ? 0 : Number(totalUnread || 0)),
    [isInboxRoute, isTabFocused, totalUnread]
  );

  useFaviconBadge(badgeCount);
  return null;
}

export default function App() {
  const location = useLocation();
  const currentPath = location.pathname;
  const isAuthRoute = currentPath !== '/' && !isLoginPath(currentPath);

  return (
    <BrandProvider>
      <ErrorBoundary resetKey={currentPath}>
        <AppToaster />
        <NotificationsProvider enabled={isAuthRoute} autoLoad={isAuthRoute}>
          <NotificationBadgeSync />
          <AppRoutes />
        </NotificationsProvider>
      </ErrorBoundary>
    </BrandProvider>
  );
}

