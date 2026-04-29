import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { useNotificationsContext } from '@/hooks/useNotificationsContext';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import browserNotificationService from '@/services/browserNotificationService';
import NotificationPreferencesPanel from '@/components/layout/NotificationsTray/NotificationPreferencesPanel.jsx';

const ICON_NOTIFICATIONS = (
  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
    <path d="M13.73 21a2 2 0 0 1-3.46 0" />
  </svg>
);

export default function NotificationsTray({ role, isLogged }) {
  const navigate = useNavigate();
  const uiRole = role === 'admin' ? 'admin' : 'company';
  const canUseDom = typeof document !== 'undefined';

  const [notificationsPanelOpen, setNotificationsPanelOpen] = useState(false);
  const [notificationBusyById, setNotificationBusyById] = useState({});
  const [toastNotification, setToastNotification] = useState(null);
  const [clearAllConfirmOpen, setClearAllConfirmOpen] = useState(false);
  const [notifPrefsOpen, setNotifPrefsOpen] = useState(false);
  const [notifPermission, setNotifPermission] = useState(() => browserNotificationService.getPermission());
  const notificationsPanelId = 'layout-notifications-panel';
  const notificationsTitleId = 'layout-notifications-title';

  const lastToastNotificationIdRef = useRef(null);
  const notificationsCloseButtonRef = useRef(null);

  const {
    totalUnread,
    notifications,
    loading: notificationsLoading,
    error: notificationsError,
    refresh: refreshNotifications,
    markAsRead,
    deleteMany,
    clearAllLocally,
  } = useNotificationsContext();

  const totalUnreadCount = Number(totalUnread ?? 0);

  const buildNotificationTarget = (notification) => {
    const referenceType = String(notification?.reference_type ?? '');
    const referenceId = Number.parseInt(String(notification?.reference_id ?? ''), 10);
    const module = String(notification?.module ?? '');

    if (referenceType === NOTIFICATION_REFERENCE_TYPE.CONVERSATION && referenceId > 0) {
      return uiRole === 'company' ? `/minha-conta/conversas?conversationId=${referenceId}` : '/admin/conversas';
    }

    if (referenceType === NOTIFICATION_REFERENCE_TYPE.CHAT_CONVERSATION && referenceId > 0) {
      return uiRole === 'company'
        ? `/minha-conta/chat-interno?conversationId=${referenceId}`
        : `/admin/chat-interno?conversationId=${referenceId}`;
    }

    if (referenceType === NOTIFICATION_REFERENCE_TYPE.SUPPORT_TICKET && referenceId > 0) {
      return uiRole === 'admin'
        ? `/admin/suporte/solicitacoes/${referenceId}`
        : `/minha-conta/suporte/solicitacoes/${referenceId}`;
    }

    if (module === NOTIFICATION_MODULE.INBOX) {
      return uiRole === 'company' ? '/minha-conta/conversas' : '/admin/conversas';
    }

    if (module === NOTIFICATION_MODULE.INTERNAL_CHAT) {
      return uiRole === 'company' ? '/minha-conta/chat-interno' : '/admin/chat-interno';
    }

    if (module === NOTIFICATION_MODULE.SUPPORT) {
      return uiRole === 'admin' ? '/admin/suporte' : '/minha-conta/suporte/solicitacoes';
    }

    return null;
  };

  const notificationModuleLabel = (module) => {
    const value = String(module ?? '').trim();
    if (value === NOTIFICATION_MODULE.INBOX) return 'Conversas';
    if (value === NOTIFICATION_MODULE.INTERNAL_CHAT) return 'Equipe interna';
    if (value === NOTIFICATION_MODULE.SUPPORT) return 'Chamados';
    if (value === NOTIFICATION_MODULE.GENERAL) return 'Geral';
    return value || 'Geral';
  };

  const formatNotificationDate = (dateValue) => {
    if (!dateValue) return '-';
    const ts = new Date(dateValue).getTime();
    return Number.isFinite(ts) ? new Date(ts).toLocaleString('pt-BR') : '-';
  };

  const handleNotificationMarkAsRead = async (notificationId) => {
    const id = Number.parseInt(String(notificationId ?? ''), 10);
    if (!id) return;

    setNotificationBusyById((previous) => ({ ...previous, [id]: true }));
    try {
      await markAsRead(id);
    } finally {
      setNotificationBusyById((previous) => ({ ...previous, [id]: false }));
    }
  };

  const handleNotificationOpenTarget = async (notification) => {
    const id = Number.parseInt(String(notification?.id ?? ''), 10);
    if (!id) return;

    const targetHref = buildNotificationTarget(notification);
    if (!targetHref) return;

    setNotificationBusyById((previous) => ({ ...previous, [id]: true }));
    try {
      if (!notification?.is_read) {
        await markAsRead(id);
      }
    } finally {
      setNotificationBusyById((previous) => ({ ...previous, [id]: false }));
    }

    setNotificationsPanelOpen(false);
    navigate(targetHref);
  };

  const handleConfirmClearAllNotifications = async () => {
    setClearAllConfirmOpen(false);

    const ids = notifications.map((item) => Number(item.id)).filter((id) => id > 0);
    if (ids.length > 0) {
      await deleteMany(ids).catch(() => {});
      return;
    }

    clearAllLocally();
  };

  const handleRequestNotifPermission = async () => {
    const result = await browserNotificationService.requestPermission();
    setNotifPermission(result);
  };

  useEffect(() => {
    if (!isLogged) {
      setNotificationsPanelOpen(false);
      setToastNotification(null);
      setClearAllConfirmOpen(false);
      return;
    }

    if (!notifications.length || notificationsLoading) {
      return;
    }

    const latest = notifications[0];
    if (!latest || latest.is_read || notificationsPanelOpen) {
      return;
    }

    if (lastToastNotificationIdRef.current === latest.id) {
      return;
    }

    lastToastNotificationIdRef.current = latest.id;
    setToastNotification(latest);

    const timer = setTimeout(() => {
      setToastNotification(null);
    }, 5000);

    return () => clearTimeout(timer);
  }, [isLogged, notifications, notificationsLoading, notificationsPanelOpen]);

  useEffect(() => {
    if (!notificationsPanelOpen) {
      setClearAllConfirmOpen(false);
      return undefined;
    }

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        setNotificationsPanelOpen(false);
      }
    };

    window.addEventListener('keydown', onKeyDown);
    notificationsCloseButtonRef.current?.focus();

    return () => window.removeEventListener('keydown', onKeyDown);
  }, [notificationsPanelOpen]);

  useEffect(() => {
    if (!clearAllConfirmOpen) {
      return undefined;
    }

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        setClearAllConfirmOpen(false);
      }
    };

    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [clearAllConfirmOpen]);

  useEffect(() => {
    if (!browserNotificationService.isSupported() || !navigator.permissions) {
      return undefined;
    }

    let canceled = false;
    let permissionStatus = null;
    let onChange = null;

    navigator.permissions
      .query({ name: 'notifications' })
      .then((status) => {
        if (canceled) return;

        permissionStatus = status;
        onChange = () => {
          if (!canceled) {
            setNotifPermission(browserNotificationService.getPermission());
          }
        };

        permissionStatus.addEventListener('change', onChange);
      })
      .catch(() => {});

    return () => {
      canceled = true;
      if (permissionStatus && onChange) {
        permissionStatus.removeEventListener('change', onChange);
      }
    };
  }, []);

  const floatingContent = (
    <>
      {toastNotification && (
        <div className="layout-toast">
          <div className="layout-toast__content">
            <div className="layout-toast__header">
              <span className="layout-toast__title">{toastNotification.title || 'Nova notificacao'}</span>
              <button
                type="button"
                className="layout-toast__close"
                onClick={() => setToastNotification(null)}
                aria-label="Fechar notificacao"
              >
                x
              </button>
            </div>
            <p className="layout-toast__text">{toastNotification.text || ''}</p>
            <div className="layout-toast__actions">
              <button
                type="button"
                className="layout-toast__btn"
                onClick={() => {
                  const target = buildNotificationTarget(toastNotification);
                  setToastNotification(null);
                  if (target) {
                    void handleNotificationOpenTarget(toastNotification);
                  }
                }}
              >
                Ver
              </button>
            </div>
          </div>
        </div>
      )}

      <NotificationPreferencesPanel open={notifPrefsOpen} onClose={() => setNotifPrefsOpen(false)} />

      {notificationsPanelOpen && (
        <>
          <div
            className="layout-notifications__backdrop"
            onClick={() => setNotificationsPanelOpen(false)}
            aria-hidden
          />
          <aside
            id={notificationsPanelId}
            className="layout-notifications__panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby={notificationsTitleId}
          >
            <div className="layout-notifications__header">
              <h2 id={notificationsTitleId} className="layout-notifications__title">Notificacoes</h2>
              <div className="layout-notifications__header-actions">
                <button
                  type="button"
                  className="layout-notifications__config-btn"
                  onClick={() => {
                    setNotificationsPanelOpen(false);
                    setNotifPrefsOpen(true);
                  }}
                  title="Configurar notificações"
                  aria-label="Configurar notificações"
                >
                  <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <circle cx="10" cy="10" r="3" stroke="currentColor" strokeWidth="1.6" />
                    <path d="M10 2v2M10 16v2M2 10h2M16 10h2M4.22 4.22l1.42 1.42M14.36 14.36l1.42 1.42M4.22 15.78l1.42-1.42M14.36 5.64l1.42-1.42" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                  </svg>
                  Configurar
                </button>
                <button
                  type="button"
                  className="layout-notifications__close"
                  onClick={() => setNotificationsPanelOpen(false)}
                  ref={notificationsCloseButtonRef}
                  title="Fechar"
                  aria-label="Fechar painel de notificações"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                  </svg>
                </button>
              </div>
            </div>

            {browserNotificationService.isSupported() && notifPermission === 'default' && (
              <div className="layout-notifications__permission-banner">
                <div className="layout-notifications__permission-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                    <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                  </svg>
                </div>
                <p className="layout-notifications__permission-text">Ative as notificações do navegador para receber alertas mesmo em outras abas.</p>
                <button
                  type="button"
                  className="layout-notifications__permission-btn"
                  onClick={() => void handleRequestNotifPermission()}
                >
                  Ativar notificações
                </button>
              </div>
            )}

            {browserNotificationService.isSupported() && notifPermission === 'denied' && (
              <div className="layout-notifications__permission-banner layout-notifications__permission-banner--denied">
                <div className="layout-notifications__permission-icon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07" />
                  </svg>
                </div>
                <p className="layout-notifications__permission-text">Notificacoes bloqueadas no navegador. Libere nas configuracoes do site para ativa-las.</p>
              </div>
            )}

            <div className="layout-notifications__toolbar">
              <span className="layout-notifications__count">Nao lidas: <strong>{totalUnreadCount}</strong></span>
              <div className="layout-notifications__toolbar-actions">
                <button
                  type="button"
                  className="layout-notifications__clear"
                  onClick={() => setClearAllConfirmOpen(true)}
                  disabled={!notifications.length}
                >
                  Limpar todas
                </button>
                <button
                  type="button"
                  className="layout-notifications__refresh"
                  onClick={() => void refreshNotifications()}
                  disabled={notificationsLoading}
                >
                  {notificationsLoading ? 'Atualizando...' : 'Atualizar'}
                </button>
              </div>
            </div>

            <div className="layout-notifications__content">
              {notificationsError ? (
                <p className="layout-notifications__error">{notificationsError}</p>
              ) : !notifications.length ? (
                <p className="layout-notifications__empty">Nenhuma notificacao no momento.</p>
              ) : (
                <ul className="layout-notifications__list">
                  {notifications.map((notification) => {
                    const targetHref = buildNotificationTarget(notification);
                    const isBusy = Boolean(notificationBusyById[notification.id]);

                    return (
                      <li
                        key={notification.id}
                        className={`layout-notifications__item ${notification.is_read ? '' : 'layout-notifications__item--unread'}`}
                      >
                        <div className="layout-notifications__item-header">
                          <span className="layout-notifications__item-module">
                            {notificationModuleLabel(notification.module)}
                          </span>
                          {!notification.is_read && <span className="layout-notifications__item-new">Nova</span>}
                        </div>
                        <p className="layout-notifications__item-title">{notification.title || 'Notificacao'}</p>
                        <p className="layout-notifications__item-text">{notification.text || 'Sem descricao.'}</p>
                        <p className="layout-notifications__item-date">
                          {formatNotificationDate(notification.created_at)}
                          {notification.read_at ? ` | lida em ${formatNotificationDate(notification.read_at)}` : ''}
                        </p>
                        <div className="layout-notifications__item-actions">
                          {!notification.is_read && (
                            <button
                              type="button"
                              className="layout-notifications__item-btn layout-notifications__item-btn--secondary"
                              onClick={() => void handleNotificationMarkAsRead(notification.id)}
                              disabled={isBusy}
                            >
                              {isBusy ? '...' : 'Marcar como lida'}
                            </button>
                          )}
                          {targetHref && (
                            <button
                              type="button"
                              className="layout-notifications__item-btn layout-notifications__item-btn--primary"
                              onClick={() => void handleNotificationOpenTarget(notification)}
                              disabled={isBusy}
                            >
                              Abrir item
                            </button>
                          )}
                        </div>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>

            {clearAllConfirmOpen && (
              <div
                className="layout-notifications__confirm-backdrop"
                role="presentation"
                onClick={() => setClearAllConfirmOpen(false)}
              >
                <div
                  className="layout-notifications__confirm-modal"
                  role="dialog"
                  aria-modal="true"
                  aria-labelledby="layout-notifications-clear-title"
                  aria-describedby="layout-notifications-clear-text"
                  onClick={(event) => event.stopPropagation()}
                >
                  <h3 id="layout-notifications-clear-title" className="layout-notifications__confirm-title">
                    Limpar todas as notificações?
                  </h3>
                  <p id="layout-notifications-clear-text" className="layout-notifications__confirm-text">
                    Todas as notificações serão apagadas permanentemente.
                  </p>
                  <div className="layout-notifications__confirm-actions">
                    <button
                      type="button"
                      className="layout-notifications__item-btn"
                      onClick={() => setClearAllConfirmOpen(false)}
                    >
                      Cancelar
                    </button>
                    <button
                      type="button"
                      className="layout-notifications__item-btn layout-notifications__item-btn--primary"
                      onClick={handleConfirmClearAllNotifications}
                    >
                      Confirmar
                    </button>
                  </div>
                </div>
              </div>
            )}
          </aside>
        </>
      )}
    </>
  );

  if (!isLogged) {
    return null;
  }

  return (
    <>
      <div className="layout-notifications">
        <button
          type="button"
          className={`layout-header__btn ${notificationsPanelOpen ? 'layout-header__btn--active' : ''}`}
          onClick={() => setNotificationsPanelOpen((value) => !value)}
          title="Notificacoes"
          aria-expanded={notificationsPanelOpen ? 'true' : 'false'}
          aria-controls={notificationsPanelId}
          aria-label="Abrir notificações"
        >
          {ICON_NOTIFICATIONS}
          {totalUnreadCount > 0 && (
            <span className="layout-header__badge">{totalUnreadCount > 99 ? '99+' : totalUnreadCount}</span>
          )}
          <span className="layout-header__btn-label">Notificacoes</span>
        </button>
      </div>

      {canUseDom ? createPortal(floatingContent, document.body) : floatingContent}
    </>
  );
}
