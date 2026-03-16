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
  chatInterno: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M7 10h10" />
      <path d="M7 14h7" />
      <path d="M21 12a8 8 0 0 1-8 8H4l-1 1V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8z" />
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
  const normalizedLabel = String(label ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim();
  const map = {
    Dashboard: 'dashboard',
    Empresas: 'empresas',
    Usuarios: 'usuarios',
    Inbox: 'inbox',
    'Chat interno': 'chatInterno',
    Suporte: 'suporte',
    Solicitacoes: 'suporte',
    'Abrir suporte': 'suporte',
    Notificacoes: 'notificacoes',
    Simulador: 'simulador',
    Bot: 'bot',
    Respostas: 'respostas',
  };
  return map[normalizedLabel] || 'dashboard';
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
  const [sidebarHovered, setSidebarHovered] = useState(false);
  const [sidebarMobileOpen, setSidebarMobileOpen] = useState(false);
  const [supportAccordionOpen, setSupportAccordionOpen] = useState(false);
  const [notificationsPanelOpen, setNotificationsPanelOpen] = useState(false);
  const [isMobile, setIsMobile] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [profileEditName, setProfileEditName] = useState(false);
  const [profileName, setProfileName] = useState('');
  const [profileSaveLoading, setProfileSaveLoading] = useState(false);
  const [profileSaveError, setProfileSaveError] = useState('');
  const [profileEditPassword, setProfileEditPassword] = useState(false);
  const [passwordCurrent, setPasswordCurrent] = useState('');
  const [passwordNew, setPasswordNew] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [passwordSaveLoading, setPasswordSaveLoading] = useState(false);
  const [passwordSaveError, setPasswordSaveError] = useState('');
  const [passwordSaveSuccess, setPasswordSaveSuccess] = useState('');
  const [userData, setUserData] = useState(null);
  const [notificationBusyById, setNotificationBusyById] = useState({});
  const profileRef = useRef(null);
  const lastToastNotificationIdRef = useRef(null);
  const [toastNotification, setToastNotification] = useState(null);
  const [clearAllConfirmOpen, setClearAllConfirmOpen] = useState(false);
  const {
    unreadByModule,
    totalUnread,
    notifications,
    loading: notificationsLoading,
    error: notificationsError,
    refresh: refreshNotifications,
    markAsRead,
    clearAllLocally,
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
    if (referenceType === NOTIFICATION_REFERENCE_TYPE.CHAT_CONVERSATION && referenceId > 0) {
      return uiRole === 'company'
        ? `/minha-conta/chat-interno?conversationId=${referenceId}`
        : `/admin/chat-interno?conversationId=${referenceId}`;
    }
    if (referenceType === NOTIFICATION_REFERENCE_TYPE.SUPPORT_TICKET && referenceId > 0) {
      return uiRole === 'admin' ? `/admin/suporte/solicitacoes/${referenceId}` : `/minha-conta/suporte/solicitacoes/${referenceId}`;
    }
    if (module === NOTIFICATION_MODULE.INBOX) return uiRole === 'company' ? '/minha-conta/conversas' : '/admin/conversas';
    if (module === NOTIFICATION_MODULE.INTERNAL_CHAT) return uiRole === 'company' ? '/minha-conta/chat-interno' : '/admin/chat-interno';
    if (module === NOTIFICATION_MODULE.SUPPORT) return uiRole === 'admin' ? '/admin/suporte' : '/minha-conta/suporte/solicitacoes';
    return null;
  };

  const notificationModuleLabel = (module) => {
    const v = String(module ?? '').trim();
    if (v === NOTIFICATION_MODULE.INBOX) return 'Inbox';
    if (v === NOTIFICATION_MODULE.INTERNAL_CHAT) return 'Chat interno';
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

  const handleConfirmClearAllNotifications = () => {
    clearAllLocally();
    setClearAllConfirmOpen(false);
  };

  useEffect(() => {
    if (!notificationsPanelOpen) {
      setClearAllConfirmOpen(false);
    }
  }, [notificationsPanelOpen]);

  useEffect(() => {
    let canceled = false;

    if (role !== 'company') {
      setCanManageUsers(false);
      return () => { canceled = true; };
    }

    api.get('/me')
      .then((response) => {
        if (canceled) return;
        const user = response.data?.user;
        setCanManageUsers(Boolean(user?.can_manage_users || (user?.role === 'company_admin' && user?.company_id)));
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

  const handleSavePassword = async (e) => {
    e.preventDefault();
    setPasswordSaveError('');
    setPasswordSaveSuccess('');

    if (!passwordCurrent.trim()) {
      setPasswordSaveError('Informe a senha atual.');
      return;
    }
    if (passwordNew.length < 6) {
      setPasswordSaveError('A nova senha deve ter pelo menos 6 caracteres.');
      return;
    }
    if (passwordNew !== passwordConfirm) {
      setPasswordSaveError('A confirmacao da nova senha nao confere.');
      return;
    }

    setPasswordSaveLoading(true);
    try {
      await api.put('/me/password', {
        current_password: passwordCurrent,
        password: passwordNew,
        password_confirmation: passwordConfirm,
      });
      setPasswordSaveSuccess('Senha alterada com sucesso.');
      setPasswordCurrent('');
      setPasswordNew('');
      setPasswordConfirm('');
    } catch (err) {
      setPasswordSaveError(err.response?.data?.message ?? 'Erro ao alterar senha.');
    } finally {
      setPasswordSaveLoading(false);
    }
  };

  const resetPasswordForm = () => {
    setProfileEditPassword(false);
    setPasswordCurrent('');
    setPasswordNew('');
    setPasswordConfirm('');
    setPasswordSaveError('');
    setPasswordSaveSuccess('');
  };

  const adminMainLinks = [
    { href: '/dashboard', label: 'Dashboard' },
    { href: '/admin/empresas', label: 'Empresas' },
    { href: '/admin/usuarios', label: 'Usuários' },
    { href: '/admin/conversas', label: 'Inbox', module: NOTIFICATION_MODULE.INBOX },
    { href: '/admin/chat-interno', label: 'Chat interno', module: NOTIFICATION_MODULE.INTERNAL_CHAT },
    { href: '/admin/simulador', label: 'Simulador' },
  ];

  const adminSupportLinks = [
    { href: '/admin/suporte', label: 'Solicitações', module: NOTIFICATION_MODULE.SUPPORT },
    { href: '/suporte', label: 'Abrir suporte' },
  ];

  const companyMainLinks = useMemo(() => {
    const links = [
      { href: '/dashboard', label: 'Dashboard' },
      { href: '/minha-conta/bot', label: 'Bot' },
      { href: '/minha-conta/conversas', label: 'Inbox', module: NOTIFICATION_MODULE.INBOX },
      { href: '/minha-conta/chat-interno', label: 'Chat interno', module: NOTIFICATION_MODULE.INTERNAL_CHAT },
      { href: '/minha-conta/simulador', label: 'Simulador' },
      { href: '/minha-conta/respostas-rapidas', label: 'Respostas' },
    ];
    if (canManageUsers) links.push({ href: '/minha-conta/usuarios', label: 'Usuários' });
    return links;
  }, [canManageUsers]);

  const companySupportLinks = [
    { href: '/suporte', label: 'Suporte' },
    { href: '/minha-conta/suporte/solicitacoes', label: 'Solicitações', module: NOTIFICATION_MODULE.SUPPORT },
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

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth <= 768);
    check();
    window.addEventListener('resize', check);
    return () => window.removeEventListener('resize', check);
  }, []);

  const unreadCountForLink = (item) => {
    if (!item?.module) return 0;
    if (item.module === NOTIFICATION_MODULE.CENTER) return Number(totalUnread ?? 0);
    return Number(unreadByModule?.[item.module] ?? 0);
  };

  const totalUnreadCount = Number(totalUnread ?? 0);

  useEffect(() => {
    if (!notifications.length || notificationsLoading) {
      return;
    }

    const latest = notifications[0];
    if (!latest || latest.is_read) {
      return;
    }

    if (notificationsPanelOpen) {
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
  }, [notifications, notificationsLoading, notificationsPanelOpen]);

  const openSidebarMobile = () => setSidebarMobileOpen(true);
  const closeSidebarMobile = () => setSidebarMobileOpen(false);

  return (
    <div className="min-h-screen relative text-[#171717] layout-wrapper">
      {hasSidebar && !isMobile && (
        <aside
          className={`layout-sidebar ${sidebarHovered ? 'layout-sidebar--expanded' : ''}`}
          onMouseEnter={() => setSidebarHovered(true)}
          onMouseLeave={() => setSidebarHovered(false)}
        >
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
                  onClick={() => setSupportAccordionOpen((v) => !v)}
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

      {hasSidebar && isMobile && (
        <>
          {sidebarMobileOpen && (
            <div
              className="layout-sidebar__backdrop"
              onClick={closeSidebarMobile}
              aria-hidden
            />
          )}
          <aside
            className={`layout-sidebar layout-sidebar--mobile ${sidebarMobileOpen ? 'layout-sidebar--mobile-open' : ''}`}
          >
            <div className="layout-sidebar__mobile-header">
              <span className="layout-sidebar__mobile-title">Menu</span>
              <button
                type="button"
                className="layout-sidebar__mobile-close"
                onClick={closeSidebarMobile}
                title="Fechar menu"
                aria-label="Fechar menu"
              >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                </svg>
              </button>
            </div>
            <nav className="layout-sidebar__nav">
              {mainLinks.map((item) => {
                const unreadCount = unreadCountForLink(item);
                return (
                  <a
                    key={item.href}
                    href={item.href}
                    className={`layout-sidebar__link ${isActive(item.href) ? 'layout-sidebar__link--active' : ''}`}
                    title={item.label}
                    onClick={closeSidebarMobile}
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
                    onClick={() => setSupportAccordionOpen((v) => !v)}
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
                            onClick={closeSidebarMobile}
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
        </>
      )}

      <div className="layout-main">
        <header className="layout-header">
          <div className="layout-header__left">
            {hasSidebar && isMobile && (
              <button
                type="button"
                className="layout-header__hamburger"
                onClick={openSidebarMobile}
                title="Abrir menu"
                aria-label="Abrir menu"
              >
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <line x1="3" y1="6" x2="21" y2="6" /><line x1="3" y1="12" x2="21" y2="12" /><line x1="3" y1="18" x2="21" y2="18" />
                </svg>
              </button>
            )}
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
          </div>

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
                        onClick={() => { setProfileEditName(true); setProfileName(userData?.name ?? ''); resetPasswordForm(); }}
                      >
                        Gerenciar nome
                      </button>
                    )}
                    {profileEditPassword ? (
                      <form onSubmit={handleSavePassword} className="layout-profile__edit">
                        <input
                          type="password"
                          value={passwordCurrent}
                          onChange={(e) => { setPasswordCurrent(e.target.value); setPasswordSaveError(''); setPasswordSaveSuccess(''); }}
                          placeholder="Senha atual"
                          className="layout-profile__input"
                          autoComplete="current-password"
                          autoFocus
                        />
                        <input
                          type="password"
                          value={passwordNew}
                          onChange={(e) => { setPasswordNew(e.target.value); setPasswordSaveError(''); setPasswordSaveSuccess(''); }}
                          placeholder="Nova senha (min. 6 caracteres)"
                          className="layout-profile__input"
                          autoComplete="new-password"
                        />
                        <input
                          type="password"
                          value={passwordConfirm}
                          onChange={(e) => { setPasswordConfirm(e.target.value); setPasswordSaveError(''); setPasswordSaveSuccess(''); }}
                          placeholder="Confirmar nova senha"
                          className="layout-profile__input"
                          autoComplete="new-password"
                        />
                        {passwordSaveError && <p className="layout-profile__error">{passwordSaveError}</p>}
                        {passwordSaveSuccess && <p className="layout-profile__success">{passwordSaveSuccess}</p>}
                        <div className="layout-profile__edit-actions">
                          <button type="submit" className="layout-profile__btn layout-profile__btn--primary" disabled={passwordSaveLoading}>
                            {passwordSaveLoading ? 'Salvando...' : 'Alterar senha'}
                          </button>
                          <button type="button" className="layout-profile__btn" onClick={resetPasswordForm}>
                            Cancelar
                          </button>
                        </div>
                      </form>
                    ) : (
                      <button
                        type="button"
                        className="layout-profile__item"
                        onClick={() => { setProfileEditPassword(true); setProfileEditName(false); setProfileName(''); setProfileSaveError(''); }}
                      >
                        Alterar senha
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

      {toastNotification && (
        <div className="layout-toast">
          <div className="layout-toast__content">
            <div className="layout-toast__header">
              <span className="layout-toast__title">{toastNotification.title || 'Nova notificação'}</span>
              <button
                type="button"
                className="layout-toast__close"
                onClick={() => setToastNotification(null)}
              >
                ×
              </button>
            </div>
            <p className="layout-toast__text">{toastNotification.text}</p>
            <div className="layout-toast__actions">
              <button
                type="button"
                className="layout-toast__btn"
                onClick={() => {
                  const target = buildNotificationTarget(toastNotification, uiRole);
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
                  onClick={(event) => event.stopPropagation()}
                >
                  <h3 id="layout-notifications-clear-title" className="layout-notifications__confirm-title">
                    Limpar todas as notificacoes?
                  </h3>
                  <p className="layout-notifications__confirm-text">
                    Isso remove as notificacoes apenas da interface. Nada sera apagado do banco.
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
    </div>
  );
}

export default Layout;

