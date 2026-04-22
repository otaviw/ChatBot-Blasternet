import NotificationsTray from '@/components/layout/NotificationsTray/NotificationsTray.jsx';
import UserMenu from '@/components/layout/UserMenu/UserMenu.jsx';
import useThemeMode from '@/components/layout/ThemeSwitcher/useThemeMode.js';

export default function Topbar({
  hasSidebar,
  isMobile,
  isLogged,
  role,
  companyName,
  openSidebarMobile,
  userData,
  onUserDataChange,
  onLogout,
}) {
  const { themeMode, toggleThemeMode } = useThemeMode();

  return (
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
          Blasternet ChatBot
          {role === 'company' && companyName && (
            <span className="hidden xl:inline text-[#737373] text-xs">/ {companyName}</span>
          )}
          {role === 'admin' && (
            <span
              className="rounded-full bg-[#dbeafe] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#1d4ed8]"
              title="Área do administrador"
            >
              Administrador
            </span>
          )}
        </a>
      </div>

      {isLogged && (
        <div className="layout-header__actions">
          <NotificationsTray role={role} isLogged={isLogged} />
          <UserMenu
            role={role}
            companyName={companyName}
            userData={userData}
            onUserDataChange={onUserDataChange}
            onLogout={onLogout}
            themeMode={themeMode}
            onToggleTheme={toggleThemeMode}
          />
        </div>
      )}
    </header>
  );
}
