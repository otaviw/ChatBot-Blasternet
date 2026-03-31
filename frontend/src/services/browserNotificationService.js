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

  notifyFromAppNotification(appNotification) {
    if (!this.canNotify() || !appNotification) return null;

    const title = String(appNotification.title || 'Nova notificacao');
    const body = String(appNotification.text || '');
    const url = resolveNavigationUrl(appNotification);

    try {
      const notification = new Notification(title, {
        body,
        icon: '/favicon.ico',
        tag: `app-notification-${appNotification.id}`,
      });

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
