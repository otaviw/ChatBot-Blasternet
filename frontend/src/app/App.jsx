import './App.css';
import { useLocation } from 'react-router-dom';
import AppRoutes from './routes';
import { NotificationsProvider } from '@/contexts/NotificationsContext';
import ErrorBoundary from '@/components/ui/ErrorBoundary/ErrorBoundary.jsx';
import AppToaster from '@/components/ui/AppToaster/AppToaster.jsx';

export default function App() {
  const location = useLocation();
  const currentPath = location.pathname;
  const isAuthRoute = currentPath !== '/' && currentPath !== '/entrar';

  return (
    <ErrorBoundary resetKey={currentPath}>
      <AppToaster />
      <NotificationsProvider enabled={isAuthRoute} autoLoad={isAuthRoute}>
        <AppRoutes />
      </NotificationsProvider>
    </ErrorBoundary>
  );
}

