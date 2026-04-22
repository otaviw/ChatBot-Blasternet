function resolveNavigationUrl(notification) {
  const isAdmin = window.location.pathname.startsWith('/admin');
  const refType = notification?.reference_type ?? '';
  const refId = notification?.reference_id ?? null;
  const meta = notification?.reference_meta ?? {};

  if (refType === 'conversation') {
    return isAdmin ? '/admin/conversas' : '/minha-conta/conversas';
  }

  if (refType === 'chat_conversation') {
    return isAdmin ? '/admin/chat-interno' : '/minha-conta/chat-interno';
  }

  if (refType === 'support_ticket') {
    const ticketId = meta?.ticket_id ?? refId;
    if (ticketId) {
      return isAdmin
        ? `/admin/suporte/solicitacoes/${ticketId}`
        : `/minha-conta/suporte/solicitacoes/${ticketId}`;
    }
    return isAdmin ? '/admin/suporte' : '/minha-conta/suporte/solicitacoes';
  }

  return null;
}

async function getSwRegistration() {
  if (!('serviceWorker' in navigator)) return null;
  try {
    return await navigator.serviceWorker.ready;
  } catch {
    return null;
  }
}

const browserNotificationService = {
  isSupported() {
    return typeof window !== 'undefined' && 'Notification' in window;
  },

  getPermission() {
    if (!this.isSupported()) return 'unsupported';
    return Notification.permission;
  },

  async requestPermission() {
    if (!this.isSupported()) return 'unsupported';
    if (Notification.permission !== 'default') return Notification.permission;
    try {
      return await Notification.requestPermission();
    } catch {
      return 'denied';
    }
  },

  canNotify() {
    return this.isSupported() && Notification.permission === 'granted';
  },

  async notifyFromAppNotification(appNotification) {
    if (!this.canNotify() || !appNotification) return null;

    const title = String(appNotification.title || 'Nova notificacao');
    const body = String(appNotification.text || '');
    const url = resolveNavigationUrl(appNotification);

    const iconUrl = (() => {
      try {
        return new URL('/favicon.ico', window.location.origin).href;
      } catch {
        return '/favicon.ico';
      }
    })();

    const options = {
      body,
      icon: iconUrl,
      tag: `app-notification-${appNotification.id}`,
      renotify: false,
      data: url ? { url } : undefined,
    };

    // Tenta via Service Worker primeiro — compatível com macOS Safari 16.4+, iOS, Chrome, Firefox
    const registration = await getSwRegistration();
    if (registration) {
      try {
        await registration.showNotification(title, options);
        return true;
      } catch (err) {
        console.error('[BrowserNotification] SW showNotification falhou, tentando fallback:', err);
      }
    }

    // Fallback direto via Notification API (Chrome/Firefox sem SW)
    try {
      const notification = new Notification(title, options);

      notification.onerror = (event) => {
        console.error('[BrowserNotification] Erro ao exibir notificação:', event);
      };

      notification.onclick = (event) => {
        event.preventDefault();
        window.focus();
        if (url) {
          window.location.href = url;
        }
        notification.close();
      };

      return notification;
    } catch (error) {
      console.error('[BrowserNotification] Falha ao criar notificação:', error);
      return null;
    }
  },
};

export default browserNotificationService;
