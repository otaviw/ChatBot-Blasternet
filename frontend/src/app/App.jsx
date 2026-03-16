import './App.css';
import { useLocation } from 'react-router-dom';
import AppRoutes from './routes';
import { NotificationsProvider } from '@/contexts/NotificationsContext';

export default function App() {
  const location = useLocation();
  const currentPath = location.pathname;
  const isAuthRoute = currentPath !== '/' && currentPath !== '/entrar';

  return (
    <NotificationsProvider enabled={isAuthRoute} autoLoad={isAuthRoute}>
      <AppRoutes />
    </NotificationsProvider>
  );
}

