import './Layout.css';
import { useEffect, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import api from '@/services/api';
import Sidebar from '@/components/layout/Sidebar/Sidebar.jsx';
import Topbar from '@/components/layout/Topbar/Topbar.jsx';
import {
  ADMIN_MAIN_LINKS,
  ADMIN_SUPPORT_LINKS,
  buildCompanyMainLinks,
  COMPANY_SUPPORT_LINKS,
  POLICY_LINKS,
} from './layoutLinks';

function Layout({ children, role, companyName, onLogout, fullWidth }) {
  const location = useLocation();
  const isLogged = Boolean(role);
  const currentPath = location.pathname;

  const [canManageUsers, setCanManageUsers] = useState(false);
  const [sidebarHovered, setSidebarHovered] = useState(false);
  const [sidebarMobileOpen, setSidebarMobileOpen] = useState(false);
  const [supportAccordionOpen, setSupportAccordionOpen] = useState(false);
  const [isMobile, setIsMobile] = useState(false);
  const [userData, setUserData] = useState(null);
  const [userDataResolved, setUserDataResolved] = useState(false);

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth <= 768);
    check();
    window.addEventListener('resize', check);
    return () => window.removeEventListener('resize', check);
  }, []);

  useEffect(() => {
    if (!isLogged) {
      setUserData(null);
      setUserDataResolved(false);
      setCanManageUsers(false);
      return undefined;
    }

    let canceled = false;
    setUserDataResolved(false);

    api.get('/me')
      .then((response) => {
        if (canceled) return;
        const user = response.data?.user ?? null;
        setUserData(user);
        setUserDataResolved(true);

        if (role === 'company') {
          setCanManageUsers(
            Boolean(user?.can_manage_users || (user?.role === 'company_admin' && user?.company_id))
          );
        } else {
          setCanManageUsers(false);
        }
      })
      .catch(() => {
        if (canceled) return;
        setUserData(null);
        setUserDataResolved(true);
        setCanManageUsers(false);
      });

    return () => {
      canceled = true;
    };
  }, [isLogged, role]);

  const companyMainLinks = useMemo(() => (
    buildCompanyMainLinks({
      userRole: userData?.role ?? null,
      userPerms: userData?.permissions ?? null,
      canManageUsers,
    })
  ), [canManageUsers, userData?.permissions, userData?.role]);

  const isResellerAdmin = role === 'admin' && userData?.role === 'reseller_admin';
  const isSystemAdmin = role === 'admin' && userData?.role === 'system_admin';
  const resellerAdminAllowedMain = useMemo(
    () => new Set([
      '/dashboard',
      '/admin/empresas',
      '/admin/usuarios',
      '/admin/chat-interno',
      '/admin/auditoria',
    ]),
    []
  );
  const superAdminHiddenMain = useMemo(() => new Set(['/admin/conversas', '/admin/auditoria']), []);
  const canRenderAdminLinks = role !== 'admin' || userDataResolved;

  const mainLinks = !canRenderAdminLinks
    ? []
    : role === 'admin'
    ? (isResellerAdmin
      ? ADMIN_MAIN_LINKS.filter((item) => resellerAdminAllowedMain.has(item.href))
      : isSystemAdmin
        ? ADMIN_MAIN_LINKS.filter((item) => !superAdminHiddenMain.has(item.href))
        : ADMIN_MAIN_LINKS)
    : role === 'company' ? companyMainLinks : [];
  const supportLinks = !canRenderAdminLinks
    ? []
    : role === 'admin'
    ? (isResellerAdmin ? COMPANY_SUPPORT_LINKS : ADMIN_SUPPORT_LINKS)
    : role === 'company' ? COMPANY_SUPPORT_LINKS : [];
  const policyLinks = POLICY_LINKS;
  const hasSidebar = isLogged && (mainLinks.length > 0 || supportLinks.length > 0 || policyLinks.length > 0);

  const openSidebarMobile = () => setSidebarMobileOpen(true);
  const closeSidebarMobile = () => setSidebarMobileOpen(false);

  return (
    <div className="min-h-screen relative text-[#171717] layout-wrapper">
      <Sidebar
        hasSidebar={hasSidebar}
        isMobile={isMobile}
        sidebarHovered={sidebarHovered}
        onSidebarHoverChange={setSidebarHovered}
        sidebarMobileOpen={sidebarMobileOpen}
        closeSidebarMobile={closeSidebarMobile}
        mainLinks={mainLinks}
        supportLinks={supportLinks}
        policyLinks={policyLinks}
        supportAccordionOpen={supportAccordionOpen}
        setSupportAccordionOpen={setSupportAccordionOpen}
        currentPath={currentPath}
      />

      <div className="layout-main">
        <Topbar
          hasSidebar={hasSidebar}
          isMobile={isMobile}
          isLogged={isLogged}
          role={role}
          companyName={companyName}
          openSidebarMobile={openSidebarMobile}
          userData={userData}
          onUserDataChange={setUserData}
          onLogout={onLogout}
        />

        <main className={`layout-main__content ${fullWidth ? 'layout-main__content--full' : ''}`}>{children}</main>
      </div>
    </div>
  );
}

export default Layout;
