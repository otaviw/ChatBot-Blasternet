import './Layout.css';
import { useEffect, useMemo, useRef, useState } from 'react';
import api from '@/services/api';
import { useNotificationsContext } from '@/contexts/NotificationsContext';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';

const ICONS = {
  dashboard: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <rect x="3" y="3" width="7" height="9" rx="1" />
      <rect x="14" y="3" width="7" height="5" rx="1" />
      <rect x="14" y="12" width="7" height="9" rx="1" />
      <rect x="3" y="16" width="7" height="5" rx="1" />
    </svg>
  ),
  empresas: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M3 21h18" /><path d="M5 21V7l8-4v18" /><path d="M19 21V11l-6-4" />
      <path d="M9 9v.01" /><path d="M9 12v.01" /><path d="M9 15v.01" /><path d="M9 18v.01" />
    </svg>
  ),
  usuarios: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />
    </svg>
  ),
  inbox: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="22 12 16 12 14 15 10 15 8 12 2 12" />
      <path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
    </svg>
  ),
  suporte: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
    </svg>
  ),
  notificacoes: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
      <path d="M13.73 21a2 2 0 0 1-3.46 0" />
    </svg>
  ),
  simulador: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polygon points="5 3 19 12 5 21 5 3" />
    </svg>
  ),
  bot: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 8V4H8" />
      <rect x="2" y="14" width="20" height="8" rx="2" />
      <path d="M6 18h.01" /><path d="M10 18h.01" /><path d="M14 18h.01" /><path d="M18 18h.01" />
      <path d="M16 8a4 4 0 0 1-8 0" />
    </svg>
  ),
  respostas: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
    </svg>
  ),
};

const iconKey = (label) => {
  const map = { Dashboard: 'dashboard', Empresas: 'empresas', Usuarios: 'usuarios', Inbox: 'inbox', Suporte: 'suporte', Solicitacoes: 'suporte', 'Abrir suporte': 'suporte', Notificacoes: 'notificacoes', Simulador: 'simulador', Bot: 'bot', Respostas: 'respostas' };
  return map[label] || 'dashboard';
};

const ICON_PROFILE = (
  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
    <circle cx="12" cy="7" r="4" />
  </svg>
);

function Layout({ children, role, companyName, onLogout, fullWidth }) {
  const isLogged = Boolean(role);
  const currentPath = window.location.pathname;
  const [canManageUsers, setCanManageUsers] = useState(false);
  const [sidebarExpanded, setSidebarExpanded] = useState(false);
  const [supportAccordionOpen, setSupportAccordionOpen] = useState(false);
  const [notificationsPanelOpen, setNotificationsPanelOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [profileEditName, setProfileEditName] = useState(false);
  const [profileName, setProfileName] = useState('');
  const [profileSaveLoading, setProfileSaveLoading] = useState(false);
  const [profileSaveError, setProfileSaveError] = useState('');
  const [userData, setUserData] = useState(null);
  const [notificationBusyById, setNotificationBusyById] = useState({});
  const profileRef = useRef(null);
  const {
    unreadByModule,
    totalUnread,
    notifications,
    loading: notificationsLoading,
    error: notificationsError,
    refresh: refreshNotifications,
    markAsRead,
  } = useNotificationsContext();

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (profileRef.current && !profileRef.current.contains(e.target)) setProfileOpen(false);
    };
    if (profileOpen) document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, [profileOpen]);

  const buildNotificationTarget = (notification, uiRole) => {
    const referenceType = String(notification?.reference_type ?? '');
    const referenceId = Number.parseInt(String(notification?.reference_id ?? ''), 10);
    const module = String(notification?.module ?? '');
    if (referenceType === NOTIFICATION_REFERENCE_TYPE.CONVERSATION && referenceId > 0) {
      return uiRole === 'company' ? `/minha-conta/conversas?conversationId=${referenceId}` : '/admin/conversas';
    }
    if (referenceType === NOTIFICATION_REFERENCE_TYPE.SUPPORT_TICKET && referenceId > 0) {
      return uiRole === 'admin' ? `/admin/suporte/solicitacoes/${referenceId}` : `/minha-conta/suporte/solicitacoes/${referenceId}`;
    }
    if (module === NOTIFICATION_MODULE.INBOX) return uiRole === 'company' ? '/minha-conta/conversas' : '/admin/conversas';
    if (module === NOTIFICATION_MODULE.SUPPORT) return uiRole === 'admin' ? '/admin/suporte' : '/minha-conta/suporte/solicitacoes';
    return null;
  };

  const notificationModuleLabel = (module) => {
    const v = String(module ?? '').trim();
    if (v === NOTIFICATION_MODULE.INBOX) return 'Inbox';
    if (v === NOTIFICATION_MODULE.SUPPORT) return 'Suporte';
    if (v === NOTIFICATION_MODULE.GENERAL) return 'Geral';
    return v || 'Geral';
  };

  const formatNotificationDate = (dateValue) => {
    if (!dateValue) return '-';
    const ts = new Date(dateValue).getTime();
    return Number.isFinite(ts) ? new Date(ts).toLocaleString('pt-BR') : '-';
  };

  const uiRole = role === 'admin' ? 'admin' : 'company';

  const handleNotificationMarkAsRead = async (notificationId) => {
    const id = Number.parseInt(String(notificationId ?? ''), 10);
    if (!id) return;
    setNotificationBusyById((p) => ({ ...p, [id]: true }));
    try {
      await markAsRead(id);
    } finally {
      setNotificationBusyById((p) => ({ ...p, [id]: false }));
    }
  };

  const handleNotificationOpenTarget = async (notification) => {
    const id = Number.parseInt(String(notification?.id ?? ''), 10);
    if (!id) return;
    const targetHref = buildNotificationTarget(notification, uiRole);
    if (!targetHref) return;
    setNotificationBusyById((p) => ({ ...p, [id]: true }));
    try {
      if (!notification?.is_read) await markAsRead(id);
    } finally {
      setNotificationBusyById((p) => ({ ...p, [id]: false }));
    }
    setNotificationsPanelOpen(false);
    window.location.href = targetHref;
  };

  useEffect(() => {
    let canceled = false;

    if (role !== 'company') {
      setCanManageUsers(false);
      return () => { canceled = true; };
    }

    api.get('/me')
      .then((response) => {
        if (canceled) return;
        setCanManageUsers(Boolean(response.data?.user?.can_manage_users));
      })
      .catch(() => {
        if (canceled) return;
        setCanManageUsers(false);
      });

    return () => { canceled = true; };
  }, [role]);

  useEffect(() => {
    if (!isLogged) return;
    let canceled = false;
    api.get('/me')
      .then((res) => {
        if (canceled) return;
        setUserData(res.data?.user ?? null);
      })
      .catch(() => {});
    return () => { canceled = true; };
  }, [isLogged]);

  const handleSaveName = async (e) => {
    e.preventDefault();
    const name = String(profileName ?? '').trim();
    if (!name) return;
    setProfileSaveLoading(true);
    setProfileSaveError('');
    try {
      const res = await api.patch('/me', { name });
      setUserData(res.data?.user ?? userData);
      setProfileEditName(false);
      setProfileName('');
    } catch (err) {
      setProfileSaveError(err.response?.data?.message ?? 'Erro ao salvar.');
    } finally {
      setProfileSaveLoading(false);
    }
  };

  const adminMainLinks = [
    { href: '/dashboard', label: 'Dashboard' },
    { href: '/admin/empresas', label: 'Empresas' },
    { href: '/admin/usuarios', label: 'Usuarios' },
    { href: '/admin/conversas', label: 'Inbox', module: NOTIFICATION_MODULE.INBOX },
    { href: '/admin/simulador', label: 'Simulador' },
  ];

  const adminSupportLinks = [
    { href: '/admin/suporte', label: 'Solicitacoes', module: NOTIFICATION_MODULE.SUPPORT },
    { href: '/suporte', label: 'Abrir suporte', module: NOTIFICATION_MODULE.SUPPORT },
  ];

  const companyMainLinks = useMemo(() => {
    const links = [
      { href: '/dashboard', label: 'Dashboard' },
      { href: '/minha-conta/bot', label: 'Bot' },
      { href: '/minha-conta/conversas', label: 'Inbox', module: NOTIFICATION_MODULE.INBOX },
      { href: '/minha-conta/simulador', label: 'Simulador' },
      { href: '/minha-conta/respostas-rapidas', label: 'Respostas' },
    ];
    if (canManageUsers) links.push({ href: '/minha-conta/usuarios', label: 'Usuarios' });
    return links;
  }, [canManageUsers]);

  const companySupportLinks = [
    { href: '/suporte', label: 'Suporte', module: NOTIFICATION_MODULE.SUPPORT },
    { href: '/minha-conta/suporte/solicitacoes', label: 'Solicitacoes', module: NOTIFICATION_MODULE.SUPPORT },
  ];

  const mainLinks = role === 'admin' ? adminMainLinks : role === 'company' ? companyMainLinks : [];
  const supportLinks = role === 'admin' ? adminSupportLinks : role === 'company' ? companySupportLinks : [];
  const hasSidebar = isLogged && (mainLinks.length > 0 || supportLinks.length > 0);

  const handleLogout = (event) => {
    if (!onLogout) return;
    event.preventDefault();
    onLogout();
  };

  const isActive = (href) => {
    if (currentPath === href) return true;
    if (href === '/dashboard') return false;
    return currentPath.startsWith(`${href}/`);
  };

  const isAnySupportActive = () => supportLinks.some((item) => isActive(item.href));

  useEffect(() => {
    if (isAnySupportActive()) setSupportAccordionOpen(true);
  }, [currentPath, role]);

  const unreadCountForLink = (item) => {
    if (!item?.module) return 0;
    if (item.module === NOTIFICATION_MODULE.CENTER) return Number(totalUnread ?? 0);
    return Number(unreadByModule?.[item.module] ?? 0);
  };

  const totalUnreadCount = Number(totalUnread ?? 0);

  return (
    <div className="min-h-screen relative text-[#171717] layout-wrapper">
      {hasSidebar && (
        <aside className={`layout-sidebar ${sidebarExpanded ? 'layout-sidebar--expanded' : ''}`}>
          <button
            type="button"
            className="layout-sidebar__toggle"
            onClick={() => setSidebarExpanded((v) => !v)}
            title={sidebarExpanded ? 'Recolher menu' : 'Expandir menu'}
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <line x1="3" y1="12" x2="21" y2="12" />
              <line x1="3" y1="6" x2="21" y2="6" />
              <line x1="3" y1="18" x2="21" y2="18" />
            </svg>
          </button>
          <nav className="layout-sidebar__nav">
            {mainLinks.map((item) => {
              const unreadCount = unreadCountForLink(item);
              return (
                <a
                  key={item.href}
                  href={item.href}
                  className={`layout-sidebar__link ${isActive(item.href) ? 'layout-sidebar__link--active' : ''}`}
                  title={item.label}
                >
                  <span className="layout-sidebar__icon">{ICONS[iconKey(item.label)]}</span>
                  <span className="layout-sidebar__label">{item.label}</span>
                  {unreadCount > 0 && (
                    <span className="layout-sidebar__badge">{unreadCount > 99 ? '99+' : unreadCount}</span>
                  )}
                </a>
              );
            })}
            {supportLinks.length > 0 && (
              <div className="layout-sidebar__accordion">
                <button
                  type="button"
                  className={`layout-sidebar__accordion-trigger ${supportAccordionOpen || isAnySupportActive() ? 'layout-sidebar__accordion-trigger--active' : ''}`}
                  onClick={() => {
                    setSupportAccordionOpen((v) => !v);
                    if (!sidebarExpanded) setSidebarExpanded(true);
                  }}
                  title="Suporte"
                >
                  <span className="layout-sidebar__icon">{ICONS.suporte}</span>
                  <span className="layout-sidebar__label">Suporte</span>
                  {(() => {
                    const total = supportLinks.reduce((sum, i) => sum + unreadCountForLink(i), 0);
                    return total > 0 ? (
                      <span className="layout-sidebar__badge">{total > 99 ? '99+' : total}</span>
                    ) : null;
                  })()}
                  <span className={`layout-sidebar__accordion-chevron ${supportAccordionOpen ? 'layout-sidebar__accordion-chevron--open' : ''}`}>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <polyline points="6 9 12 15 18 9" />
                    </svg>
                  </span>
                </button>
                <div className={`layout-sidebar__accordion-content ${supportAccordionOpen ? 'layout-sidebar__accordion-content--open' : ''}`}>
                  <div>
                    {supportLinks.map((item) => {
                      const unreadCount = unreadCountForLink(item);
                      return (
                        <a
                          key={item.href}
                          href={item.href}
                          className={`layout-sidebar__link layout-sidebar__link--nested ${isActive(item.href) ? 'layout-sidebar__link--active' : ''}`}
                          title={item.label}
                        >
                          <span className="layout-sidebar__icon">{ICONS[iconKey(item.label)]}</span>
                          <span className="layout-sidebar__label">{item.label}</span>
                          {unreadCount > 0 && (
                            <span className="layout-sidebar__badge">{unreadCount > 99 ? '99+' : unreadCount}</span>
                          )}
                        </a>
                      );
                    })}
                  </div>
                </div>
              </div>
            )}
          </nav>
        </aside>
      )}

      <div className="layout-main">
        <header className="layout-header">
          <a
            href={isLogged ? '/dashboard' : '/entrar'}
            className="layout-header__logo"
          >
            <span className="h-2 w-2 rounded-full bg-[#2563eb]" />
            Blasternet ChatBot
            {role === 'company' && companyName && (
              <span className="hidden xl:inline text-[#737373] text-xs">/ {companyName}</span>
            )}
            {role === 'admin' && (
              <span className="rounded-full bg-[#dbeafe] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#1d4ed8]">Admin</span>
            )}
          </a>

          {isLogged && (
            <div className="layout-header__actions">
              <div className="layout-notifications">
                <button
                  type="button"
                  className={`layout-header__btn ${notificationsPanelOpen ? 'layout-header__btn--active' : ''}`}
                  onClick={() => setNotificationsPanelOpen((v) => !v)}
                  title="Notificações"
                >
                  {ICONS.notificacoes}
                  {totalUnreadCount > 0 && (
                    <span className="layout-header__badge">{totalUnreadCount > 99 ? '99+' : totalUnreadCount}</span>
                  )}
                  <span className="layout-header__btn-label">Notificações</span>
                </button>
              </div>
              <div className="layout-profile" ref={profileRef}>
                <button
                  type="button"
                  className="layout-header__btn layout-header__btn--profile"
                  onClick={(e) => { e.stopPropagation(); setProfileOpen((v) => !v); }}
                  title="Perfil"
                >
                  {ICON_PROFILE}
                  <span className="layout-header__btn-label">Perfil</span>
                </button>
                {profileOpen && (
                  <div className="layout-profile__dropdown" onClick={(e) => e.stopPropagation()}>
                    <div className="layout-profile__info">
                      <span className="layout-profile__name">{userData?.name ?? 'Usuário'}</span>
                      <span className="layout-profile__email">{userData?.email ?? ''}</span>
                      {role === 'company' && companyName && (
                        <span className="layout-profile__company">{companyName}</span>
                      )}
                    </div>
                    {profileEditName ? (
                      <form onSubmit={handleSaveName} className="layout-profile__edit">
                        <input
                          type="text"
                          value={profileName}
                          onChange={(e) => setProfileName(e.target.value)}
                          placeholder="Nome"
                          className="layout-profile__input"
                          autoFocus
                        />
                        {profileSaveError && <p className="layout-profile__error">{profileSaveError}</p>}
                        <div className="layout-profile__edit-actions">
                          <button type="submit" className="layout-profile__btn layout-profile__btn--primary" disabled={profileSaveLoading}>
                            Salvar
                          </button>
                          <button type="button" className="layout-profile__btn" onClick={() => { setProfileEditName(false); setProfileName(''); setProfileSaveError(''); }}>
                            Cancelar
                          </button>
                        </div>
                      </form>
                    ) : (
                      <button
                        type="button"
                        className="layout-profile__item"
                        onClick={() => { setProfileEditName(true); setProfileName(userData?.name ?? ''); }}
                      >
                        Gerenciar nome
                      </button>
                    )}
                    <button
                      type="button"
                      className="layout-profile__item layout-profile__item--logout"
                      onClick={(e) => { e.preventDefault(); setProfileOpen(false); handleLogout(e); }}
                    >
                      Sair
                    </button>
                  </div>
                )}
              </div>
            </div>
          )}
        </header>

        <main className={`layout-main__content ${fullWidth ? 'layout-main__content--full' : ''}`}>{children}</main>
      </div>

      {isLogged && notificationsPanelOpen && (
        <>
          <div
            className="layout-notifications__backdrop"
            onClick={() => setNotificationsPanelOpen(false)}
            aria-hidden
          />
          <aside className="layout-notifications__panel">
            <div className="layout-notifications__header">
              <h2 className="layout-notifications__title">Notificações</h2>
              <button
                type="button"
                className="layout-notifications__close"
                onClick={() => setNotificationsPanelOpen(false)}
                title="Fechar"
              >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <line x1="18" y1="6" x2="6" y2="18" />
                  <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
              </button>
            </div>
            <div className="layout-notifications__toolbar">
              <span className="layout-notifications__count">Não lidas: <strong>{totalUnreadCount}</strong></span>
              <button
                type="button"
                className="layout-notifications__refresh"
                onClick={() => void refreshNotifications()}
                disabled={notificationsLoading}
              >
                {notificationsLoading ? 'Atualizando...' : 'Atualizar'}
              </button>
            </div>
            <div className="layout-notifications__content">
              {notificationsError ? (
                <p className="layout-notifications__error">{notificationsError}</p>
              ) : !notifications.length ? (
                <p className="layout-notifications__empty">Nenhuma notificação no momento.</p>
              ) : (
                <ul className="layout-notifications__list">
                  {notifications.map((notification) => {
                    const targetHref = buildNotificationTarget(notification, uiRole);
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
                        <p className="layout-notifications__item-title">{notification.title}</p>
                        <p className="layout-notifications__item-text">{notification.text}</p>
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
          </aside>
        </>
      )}
    </div>
  );
}

export default Layout;
