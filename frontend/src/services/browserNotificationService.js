function resolveNavigationUrl(notification) {
  const isAdmin = window.location.pathname.startsWith('/admin');
  const refType = notification?.reference_type ?? '';
  const refId = Number.parseInt(String(notification?.reference_id ?? ''), 10);
  const meta = notification?.reference_meta ?? {};

  if (refType === 'conversation') {
    if (refId > 0) {
      return isAdmin ? '/admin/conversas' : `/minha-conta/conversas?conversationId=${refId}`;
    }
    return isAdmin ? '/admin/conversas' : '/minha-conta/conversas';
  }

  if (refType === 'chat_conversation') {
    if (refId > 0) {
      return isAdmin
        ? `/admin/chat-interno?conversationId=${refId}`
        : `/minha-conta/chat-interno?conversationId=${refId}`;
    }
    return isAdmin ? '/admin/chat-interno' : '/minha-conta/chat-interno';
  }

  if (refType === 'support_ticket') {
    const ticketId = Number.parseInt(String(meta?.ticket_id ?? refId ?? ''), 10);
    if (ticketId > 0) {
      return isAdmin
        ? `/admin/suporte/solicitacoes/${ticketId}`
        : `/minha-conta/suporte/solicitacoes/${ticketId}`;
    }

    return isAdmin ? '/admin/suporte' : '/minha-conta/suporte/solicitacoes';
  }

  return null;
}

async function getSwRegistration() {
  if (!('serviceWorker' in navigator)) {
    return null;
  }

  try {
    const existing = await navigator.serviceWorker.getRegistration();
    if (existing) {
      return existing;
    }
  } catch {
  }

  try {
    return await Promise.race([
      navigator.serviceWorker.ready,
      new Promise((resolve) => {
        setTimeout(() => resolve(null), 1200);
      }),
    ]);
  } catch {
    return null;
  }
}

const browserNotificationService = {
  isSupported() {
    return typeof window !== 'undefined' && 'Notification' in window;
  },

  getPermission() {
    if (!this.isSupported()) {
      return 'unsupported';
    }

    return Notification.permission;
  },

  async requestPermission() {
    if (!this.isSupported()) {
      return 'unsupported';
    }

    if (Notification.permission !== 'default') {
      return Notification.permission;
    }

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
    if (!this.canNotify() || !appNotification) {
      return null;
    }

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
      badge: iconUrl,
      tag: `app-notification-${appNotification.id}`,
      renotify: false,
      data: url ? { url } : undefined,
    };

    const registration = await getSwRegistration();
    if (registration) {
      try {
        await registration.showNotification(title, options);
        return true;
      } catch {
      }
    }

    try {
      const notification = new Notification(title, options);

      notification.onclick = (event) => {
        event.preventDefault();
        window.focus();
        if (url) {
          window.location.href = url;
        }
        notification.close();
      };

      return notification;
    } catch {
      return null;
    }
  },
};

export default browserNotificationService;
