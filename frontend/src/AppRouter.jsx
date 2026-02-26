import React from 'react';
import EntrarPage from './pages/EntrarPage';
import DashboardPage from './pages/DashboardPage';
import AdminCompaniesPage from './pages/admin/AdminCompaniesPage';
import AdminCompanyShowPage from './pages/admin/AdminCompanyShowPage';
import AdminSimulatorPage from './pages/admin/AdminSimulatorPage';
import AdminInboxPage from './pages/admin/AdminInboxPage';
import AdminUsersPage from './pages/admin/AdminUsersPage';
import CompanyBotPage from './pages/company/CompanyBotPage';
import CompanySimulatorPage from './pages/company/CompanySimulatorPage';
import CompanyInboxPage from './pages/company/CompanyInboxPage';
import NotFoundPage from './pages/NotFoundPage';
import CompanyQuickRepliesPage from './pages/company/CompanyQuickRepliesPage';
import CompanyUsersPage from './pages/company/CompanyUsersPage';

function AppRouter() {
  const path = window.location.pathname;
  const segments = path.replace(/^\/+|\/+$/g, '').split('/');

  if (path === '/' || path === '/entrar') {
    return <EntrarPage />;
  }

  if (path === '/dashboard') {
    return <DashboardPage />;
  }

  if (path === '/admin/empresas') {
    return <AdminCompaniesPage />;
  }

  if (path === '/admin/simulador') {
    return <AdminSimulatorPage />;
  }

  if (path === '/admin/conversas') {
    return <AdminInboxPage />;
  }

  if (path === '/admin/usuarios') {
    return <AdminUsersPage />;
  }

  if (segments[0] === 'admin' && segments[1] === 'empresas' && segments[2]) {
    return <AdminCompanyShowPage companyId={segments[2]} />;
  }

  if (path === '/minha-conta/simulador') {
    return <CompanySimulatorPage />;
  }

  if (path === '/minha-conta/conversas') {
    return <CompanyInboxPage />;
  }

  if (path === '/minha-conta/bot') {
    return <CompanyBotPage />;
  }

  if (path === '/minha-conta/respostas-rapidas') {
    return <CompanyQuickRepliesPage />;
  }

  if (path === '/minha-conta/usuarios') {
    return <CompanyUsersPage />;
  }

  return <NotFoundPage />;
}

export default AppRouter;
