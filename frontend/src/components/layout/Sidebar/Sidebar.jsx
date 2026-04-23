import { useEffect, useMemo } from 'react';
import { useNotificationsContext } from '@/hooks/useNotificationsContext';
import { NOTIFICATION_MODULE } from '@/constants/notifications';
import useBrand from '@/hooks/useBrand';
import BrandLogo from '@/components/branding/BrandLogo/BrandLogo.jsx';

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
  politica: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <polyline points="14 2 14 8 20 8" />
      <line x1="8" y1="13" x2="16" y2="13" />
      <line x1="8" y1="17" x2="14" y2="17" />
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
  agendamentos: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <rect x="3" y="4" width="18" height="18" rx="2" />
      <line x1="16" y1="2" x2="16" y2="6" />
      <line x1="8" y1="2" x2="8" y2="6" />
      <line x1="3" y1="10" x2="21" y2="10" />
      <path d="M8 14h.01" />
      <path d="M12 14h.01" />
      <path d="M16 14h.01" />
      <path d="M8 18h.01" />
      <path d="M12 18h.01" />
      <path d="M16 18h.01" />
    </svg>
  ),
  contatos: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2z" />
      <path d="M8 10a4 4 0 1 1 8 0" />
      <path d="M6 20a6 6 0 0 1 12 0" />
      <line x1="16" y1="8" x2="20" y2="8" />
    </svg>
  ),
  campanhas: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M3 11v3a2 2 0 0 0 2 2h2l6 4V6l-6 4H5a2 2 0 0 0-2 1z" />
      <path d="M17 8a5 5 0 0 1 0 8" />
      <path d="M19.5 5.5a8.5 8.5 0 0 1 0 13" />
    </svg>
  ),
  chatIa: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 3v2M12 19v2M5 12H3M21 12h-2" />
      <circle cx="12" cy="12" r="2" />
    </svg>
  ),
  chamados: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" />
      <circle cx="12" cy="7" r="4" />
    </svg>
  ),
  novoChamado: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="10" />
      <path d="M12 8v8M8 12h8" />
    </svg>
  ),
  tags: (
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
      <line x1="7" y1="7" x2="7.01" y2="7" />
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
    IA: 'bot',
    'Configuracoes de IA': 'bot',
    'Configuracoes da IA': 'bot',
    'Analytics IA': 'chatIa',
    'Auditoria IA': 'chatIa',
    'Chat interno': 'chatInterno',
    'Equipe interna': 'chatInterno',
    Assistente: 'chatIa',
    'Chat IA': 'chatIa',
    Suporte: 'suporte',
    Solicitacoes: 'suporte',
    Chamados: 'chamados',
    'Meus chamados': 'chamados',
    'Novo chamado': 'novoChamado',
    'Abrir suporte': 'suporte',
    'Pedir ajuda': 'suporte',
    'Política de privacidade': 'politica',
    'Políticas e privacidade': 'politica',
    Notificacoes: 'notificacoes',
    Simulador: 'simulador',
    'Testar bot': 'simulador',
    Bot: 'bot',
    Contatos: 'contatos',
    Campanhas: 'campanhas',
    Agendamentos: 'agendamentos',
    Respostas: 'respostas',
    'Respostas rapidas': 'respostas',
    'Base de conhecimento': 'respostas',
    Tags: 'tags',
    Inicio: 'dashboard',
    Conversas: 'inbox',
  };
  return map[normalizedLabel] || 'dashboard';
};

const sidebarIconFor = (item) => {
  if (item?.icon && ICONS[item.icon]) {
    return item.icon;
  }
  return iconKey(item?.label);
};

export default function Sidebar({
  hasSidebar,
  isMobile,
  sidebarHovered,
  onSidebarHoverChange,
  sidebarMobileOpen,
  closeSidebarMobile,
  mainLinks,
  supportLinks,
  policyLinks,
  supportAccordionOpen,
  setSupportAccordionOpen,
  currentPath,
}) {
  const { unreadByModule, totalUnread } = useNotificationsContext();
  const brand = useBrand();
  const brandName = brand?.name || 'Blasternet ChatBot';

  const unreadCountForLink = (item) => {
    if (!item?.module) return 0;
    if (item.module === NOTIFICATION_MODULE.CENTER) return Number(totalUnread ?? 0);
    return Number(unreadByModule?.[item.module] ?? 0);
  };

  const isActive = (href) => {
    if (currentPath === href) return true;
    if (href === '/dashboard') return false;
    return currentPath.startsWith(`${href}/`);
  };

  const isAnySupportActive = useMemo(() => (
    supportLinks.some((item) => {
      if (currentPath === item.href) {
        return true;
      }
      if (item.href === '/dashboard') {
        return false;
      }
      return currentPath.startsWith(`${item.href}/`);
    })
  ), [currentPath, supportLinks]);

  useEffect(() => {
    if (isAnySupportActive) {
      setSupportAccordionOpen(true);
    }
  }, [isAnySupportActive, setSupportAccordionOpen]);

  if (!hasSidebar) {
    return null;
  }

  return (
    <>
      {!isMobile && (
        <aside
          className={`layout-sidebar ${sidebarHovered ? 'layout-sidebar--expanded' : ''}`}
          onMouseEnter={() => onSidebarHoverChange(true)}
          onMouseLeave={() => onSidebarHoverChange(false)}
        >
          <nav className="layout-sidebar__nav">
            {mainLinks.map((item) => {
              const unreadCount = unreadCountForLink(item);
              const linkTitle = item.ariaLabel || item.label;
              return (
                <a
                  key={item.href}
                  href={item.href}
                  className={`layout-sidebar__link ${isActive(item.href) ? 'layout-sidebar__link--active' : ''}`}
                  title={linkTitle}
                  aria-label={linkTitle}
                  aria-current={isActive(item.href) ? 'page' : undefined}
                >
                  <span className="layout-sidebar__icon">{ICONS[sidebarIconFor(item)]}</span>
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
                  id="sidebar-support-trigger"
                  type="button"
                  className={`layout-sidebar__accordion-trigger ${supportAccordionOpen || isAnySupportActive ? 'layout-sidebar__accordion-trigger--active' : ''}`}
                  onClick={() => setSupportAccordionOpen((v) => !v)}
                  title="Ajuda e suporte — expandir ou recolher"
                  aria-label="Ajuda e suporte — expandir ou recolher"
                  aria-expanded={supportAccordionOpen}
                  aria-controls="sidebar-support-panel"
                >
                  <span className="layout-sidebar__icon">{ICONS.suporte}</span>
                  <span className="layout-sidebar__label">Ajuda e suporte</span>
                  {(() => {
                    const total = supportLinks.reduce((sum, item) => sum + unreadCountForLink(item), 0);
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
                <div
                  id="sidebar-support-panel"
                  role="region"
                  aria-labelledby="sidebar-support-trigger"
                  className={`layout-sidebar__accordion-content ${supportAccordionOpen ? 'layout-sidebar__accordion-content--open' : ''}`}
                >
                  <div>
                    {supportLinks.map((item) => {
                      const unreadCount = unreadCountForLink(item);
                      const linkTitle = item.ariaLabel || item.label;
                      return (
                        <a
                          key={item.href}
                          href={item.href}
                          className={`layout-sidebar__link layout-sidebar__link--nested ${isActive(item.href) ? 'layout-sidebar__link--active' : ''}`}
                          title={linkTitle}
                          aria-label={linkTitle}
                          aria-current={isActive(item.href) ? 'page' : undefined}
                        >
                          <span className="layout-sidebar__icon">{ICONS[sidebarIconFor(item)]}</span>
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
            {policyLinks.length > 0 && (
              <div className="layout-sidebar__accordion layout-sidebar__accordion--stacked">
                {policyLinks.map((item) => {
                  const linkTitle = item.ariaLabel || item.label;
                  return (
                    <a
                      key={item.href}
                      href={item.href}
                      className={`layout-sidebar__link ${isActive(item.href) ? 'layout-sidebar__link--active' : ''}`}
                      title={linkTitle}
                      aria-label={linkTitle}
                      aria-current={isActive(item.href) ? 'page' : undefined}
                    >
                      <span className="layout-sidebar__icon">{ICONS[sidebarIconFor(item)]}</span>
                      <span className="layout-sidebar__label">{item.label}</span>
                    </a>
                  );
                })}
              </div>
            )}
          </nav>
        </aside>
      )}

      {isMobile && (
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
              <BrandLogo
                fallback={brandName}
                className="layout-sidebar__mobile-title"
                imgClassName="h-6 w-auto max-w-[180px] object-contain"
              />
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
                const linkTitle = item.ariaLabel || item.label;
                return (
                  <a
                    key={item.href}
                    href={item.href}
                    className={`layout-sidebar__link ${isActive(item.href) ? 'layout-sidebar__link--active' : ''}`}
                    title={linkTitle}
                    aria-label={linkTitle}
                    aria-current={isActive(item.href) ? 'page' : undefined}
                    onClick={closeSidebarMobile}
                  >
                    <span className="layout-sidebar__icon">{ICONS[sidebarIconFor(item)]}</span>
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
                    id="sidebar-support-trigger-mobile"
                    type="button"
                    className={`layout-sidebar__accordion-trigger ${supportAccordionOpen || isAnySupportActive ? 'layout-sidebar__accordion-trigger--active' : ''}`}
                    onClick={() => setSupportAccordionOpen((v) => !v)}
                    title="Ajuda e suporte — expandir ou recolher"
                    aria-label="Ajuda e suporte — expandir ou recolher"
                    aria-expanded={supportAccordionOpen}
                    aria-controls="sidebar-support-panel-mobile"
                  >
                    <span className="layout-sidebar__icon">{ICONS.suporte}</span>
                    <span className="layout-sidebar__label">Ajuda e suporte</span>
                    {(() => {
                      const total = supportLinks.reduce((sum, item) => sum + unreadCountForLink(item), 0);
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
                  <div
                    id="sidebar-support-panel-mobile"
                    role="region"
                    aria-labelledby="sidebar-support-trigger-mobile"
                    className={`layout-sidebar__accordion-content ${supportAccordionOpen ? 'layout-sidebar__accordion-content--open' : ''}`}
                  >
                    <div>
                      {supportLinks.map((item) => {
                        const unreadCount = unreadCountForLink(item);
                        const linkTitle = item.ariaLabel || item.label;
                        return (
                          <a
                            key={item.href}
                            href={item.href}
                            className={`layout-sidebar__link layout-sidebar__link--nested ${isActive(item.href) ? 'layout-sidebar__link--active' : ''}`}
                            title={linkTitle}
                            aria-label={linkTitle}
                            aria-current={isActive(item.href) ? 'page' : undefined}
                            onClick={closeSidebarMobile}
                          >
                            <span className="layout-sidebar__icon">{ICONS[sidebarIconFor(item)]}</span>
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
              {policyLinks.length > 0 && (
                <div className="layout-sidebar__accordion layout-sidebar__accordion--stacked">
                  {policyLinks.map((item) => {
                    const linkTitle = item.ariaLabel || item.label;
                    return (
                      <a
                        key={item.href}
                        href={item.href}
                        className={`layout-sidebar__link ${isActive(item.href) ? 'layout-sidebar__link--active' : ''}`}
                        title={linkTitle}
                        aria-label={linkTitle}
                        aria-current={isActive(item.href) ? 'page' : undefined}
                        onClick={closeSidebarMobile}
                      >
                        <span className="layout-sidebar__icon">{ICONS[sidebarIconFor(item)]}</span>
                        <span className="layout-sidebar__label">{item.label}</span>
                      </a>
                    );
                  })}
                </div>
              )}
            </nav>
          </aside>
        </>
      )}
    </>
  );
}
