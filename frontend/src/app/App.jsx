import './App.css';
import AppRoutes from "./routes";
import { NotificationsProvider } from '@/contexts/NotificationsContext';

export default function App() {
  const currentPath = window.location.pathname;
  const isAuthRoute = currentPath !== '/' && currentPath !== '/entrar';

  return (
    <NotificationsProvider enabled={isAuthRoute} autoLoad={isAuthRoute}>
      <AppRoutes />
    </NotificationsProvider>
  );
}

