import './routes.css';
import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes, useLocation, useParams } from 'react-router-dom';
import useAuth from '@/hooks/useAuth';
import { PERM, hasPermission } from '@/constants/permissions';
import { getScopedAuthPaths } from '@/utils/tenantRouting';

const EntrarPage = lazy(() => import('@/pages/Entrar/EntrarPage.jsx'));
const EsqueceuSenhaPage = lazy(() => import('@/pages/EsqueceuSenha/EsqueceuSenhaPage.jsx'));
const RedefinirSenhaPage = lazy(() => import('@/pages/RedefinirSenha/RedefinirSenhaPage.jsx'));
const DashboardPage = lazy(() => import('@/pages/Dashboard/DashboardPage.jsx'));
const AdminCompaniesPage = lazy(() => import('@/pages/admin/AdminCompanies/AdminCompaniesPage.jsx'));
const AdminCompanyShowPage = lazy(() => import('@/pages/admin/AdminCompanyShow/AdminCompanyShowPage.jsx'));
const CompanyEditPage = lazy(() => import('@/pages/admin/CompanyEdit/CompanyEditPage.jsx'));
const AdminMyResellerPage = lazy(
  () => import('@/pages/admin/AdminMyReseller/AdminMyResellerPage.jsx')
);
const AdminInboxPage = lazy(() => import('@/pages/admin/AdminInbox/AdminInboxPage.jsx'));
const AdminUsersPage = lazy(() => import('@/pages/admin/AdminUsers/AdminUsersPage.jsx'));
const AdminSupportTicketsPage = lazy(
  () => import('@/pages/admin/AdminSupportTickets/AdminSupportTicketsPage.jsx')
);
const AdminTicketIndex = lazy(() => import('@/pages/admin/AdminSupportTickets/AdminTicketIndex.jsx'));
const CompanyBotPage = lazy(() => import('@/pages/company/CompanyBot/CompanyBotPage.jsx'));
const CompanyInboxPage = lazy(() => import('@/pages/company/CompanyInbox/CompanyInboxPage.jsx'));
const NotFoundPage = lazy(() => import('@/pages/NotFound/NotFoundPage.jsx'));
const CompanyQuickRepliesPage = lazy(
  () => import('@/pages/company/CompanyQuickReplies/CompanyQuickRepliesPage.jsx')
);
const CompanyKnowledgeBasePage = lazy(
  () => import('@/pages/company/CompanyKnowledgeBase/CompanyKnowledgeBasePage.jsx')
);
const CompanyUsersPage = lazy(() => import('@/pages/company/CompanyUsers/CompanyUsersPage.jsx'));
const CompanyContactsPage = lazy(() => import('@/pages/company/Contacts/ContactsPage.jsx'));
const CampaignsPage = lazy(() => import('@/pages/company/Campaigns/CampaignsPage.jsx'));
const CompanyAppointmentsPage = lazy(
  () => import('@/pages/company/CompanyAppointments/CompanyAppointmentsPage.jsx')
);
const CompanyTagsPage = lazy(() => import('@/pages/company/CompanyTags/CompanyTagsPage.jsx'));
const AiSettingsPage = lazy(() => import('@/pages/company/AiSettings/AiSettingsPage.jsx'));
const CompanyAiAnalyticsPage = lazy(
  () => import('@/pages/company/CompanyAiAnalytics/CompanyAiAnalyticsPage.jsx')
);
const CompanyAiAuditPage = lazy(
  () => import('@/pages/company/CompanyAiAudit/CompanyAiAuditPage.jsx')
);
const CompanyAuditPage = lazy(() => import('@/pages/company/CompanyAudit/CompanyAuditPage.jsx'));
const CompanySupportTicketPage = lazy(
  () => import('@/pages/company/CompanySupportTickets/CompanySupportTicketPage.jsx')
);
const CompanyTicketIndex = lazy(
  () => import('@/pages/company/CompanySupportTickets/CompanyTicketIndex.jsx')
);
const SupportRequestPage = lazy(() => import('@/pages/support/SupportRequest/SupportRequestPage.jsx'));
const InternalChatPage = lazy(() => import('@/pages/shared/InternalChat/InternalChatPage.jsx'));
const InternalAiChatPage = lazy(() => import('@/pages/shared/InternalAiChat/InternalAiChatPage.jsx'));

const LOGIN_PATHS = ['/entrar', '/login', '/:slug/entrar', '/:slug/login'];
const DASHBOARD_PATHS = ['/dashboard', '/:slug/dashboard'];

function renderAliasRoutes(paths, element) {
  return paths.map((path) => <Route key={path} path={path} element={element} />);
}

function useScopedAuthPaths() {
  const location = useLocation();
  return getScopedAuthPaths(location.pathname);
}

function SuperAdminRoute({ children }) {
  const { user, loading } = useAuth();
  const { dashboardPath } = useScopedAuthPaths();
  if (loading) return null;
  if (user?.role !== 'system_admin') return <Navigate to={dashboardPath} replace />;
  return children;
}

function AiManagementRoute({ children }) {
  const { user, loading } = useAuth();
  const { dashboardPath } = useScopedAuthPaths();
  if (loading) return null;
  if (user?.role !== 'system_admin') return <Navigate to={dashboardPath} replace />;
  return children;
}

function ResellerAdminRoute({ children }) {
  const { user, loading } = useAuth();
  const { dashboardPath } = useScopedAuthPaths();
  if (loading) return null;
  if (user?.role !== 'reseller_admin') return <Navigate to={dashboardPath} replace />;
  return children;
}

function PermissionRoute({ permission, children }) {
  const { user, loading } = useAuth();
  const { loginPath, dashboardPath } = useScopedAuthPaths();
  if (loading) return null;
  if (!user) return <Navigate to={loginPath} replace />;
  if (!hasPermission(user.permissions ?? null, user.role, permission)) {
    return <Navigate to={dashboardPath} replace />;
  }
  return children;
}

function AdminCompanyShowRoute() {
  const { companyId = '' } = useParams();
  return <AdminCompanyShowPage companyId={companyId} />;
}

function AdminSupportTicketRoute() {
  const { ticketId = '' } = useParams();
  return <AdminTicketIndex ticketId={ticketId} />;
}

function CompanySupportTicketRoute() {
  const { ticketId = '' } = useParams();
  return <CompanyTicketIndex ticketId={ticketId} />;
}

function CompanyEditRoute() {
  const { user, loading } = useAuth();
  const { loginPath, dashboardPath } = useScopedAuthPaths();

  if (loading) return null;
  if (!user) return <Navigate to={loginPath} replace />;

  const canAccess = user.role === 'reseller_admin';
  if (!canAccess) return <Navigate to={dashboardPath} replace />;

  return <CompanyEditPage />;
}

function AppRoutes() {
  return (
    <Suspense fallback={<p className="text-sm text-[#525252]" role="status" aria-live="polite">Carregando página...</p>}>
      <Routes>
        <Route path="/" element={<Navigate to="/entrar" replace />} />
        {renderAliasRoutes(LOGIN_PATHS, <EntrarPage />)}
        <Route path="/esqueceu-senha" element={<EsqueceuSenhaPage />} />
        <Route path="/redefinir-senha" element={<RedefinirSenhaPage />} />
        {renderAliasRoutes(DASHBOARD_PATHS, <DashboardPage />)}

        {/* Rotas administrativas gerais: acesso por papel especifico no proprio route guard/componente */}
        <Route path="/admin/empresas" element={<AdminCompaniesPage />} />
        <Route path="/admin/empresas/:companyId" element={<AdminCompanyShowRoute />} />
        <Route
          path="/admin/minha-revenda"
          element={<ResellerAdminRoute><AdminMyResellerPage /></ResellerAdminRoute>}
        />
        <Route path="/companies/:id/edit" element={<CompanyEditRoute />} />
        <Route path="/admin/conversas" element={<ResellerAdminRoute><AdminInboxPage /></ResellerAdminRoute>} />
        <Route path="/admin/auditoria" element={<ResellerAdminRoute><CompanyAuditPage /></ResellerAdminRoute>} />
        <Route path="/admin/chat-interno" element={<InternalChatPage />} />
        <Route path="/admin/chat-ia" element={<InternalAiChatPage />} />
        <Route path="/admin/usuarios" element={<AdminUsersPage />} />
        <Route path="/admin/suporte" element={<AdminSupportTicketsPage />} />
        <Route
          path="/admin/suporte/solicitacoes/:ticketId"
          element={<AdminSupportTicketRoute />}
        />

        {/* Rotas de company account com permissao explicita via PermissionRoute */}
        <Route path="/minha-conta/conversas" element={<PermissionRoute permission={PERM.PAGE_INBOX}><CompanyInboxPage /></PermissionRoute>} />
        <Route path="/minha-conta/chat-interno" element={<PermissionRoute permission={PERM.PAGE_INTERNAL_CHAT}><InternalChatPage /></PermissionRoute>} />
        {/* Rotas de IA: atualmente restritas a system_admin (SuperAdminRoute/AiManagementRoute) */}
        <Route path="/minha-conta/chat-ia" element={<SuperAdminRoute><InternalAiChatPage /></SuperAdminRoute>} />
        <Route path="/minha-conta/ia/analytics" element={<AiManagementRoute><CompanyAiAnalyticsPage /></AiManagementRoute>} />
        <Route path="/minha-conta/ia/auditoria" element={<AiManagementRoute><CompanyAiAuditPage /></AiManagementRoute>} />
        <Route path="/minha-conta/auditoria" element={<PermissionRoute permission={PERM.PAGE_AUDIT}><CompanyAuditPage /></PermissionRoute>} />
        <Route path="/minha-conta/ia/configuracoes" element={<AiManagementRoute><AiSettingsPage /></AiManagementRoute>} />
        {/* Rotas operacionais de company sem PermissionRoute explicito: regra aplicada no backend e/ou pagina */}
        <Route path="/minha-conta/bot" element={<CompanyBotPage />} />
        <Route path="/minha-conta/base-conhecimento" element={<AiManagementRoute><CompanyKnowledgeBasePage /></AiManagementRoute>} />
        <Route path="/minha-conta/respostas-rapidas" element={<PermissionRoute permission={PERM.PAGE_QUICK_REPLIES}><CompanyQuickRepliesPage /></PermissionRoute>} />
        <Route path="/minha-conta/usuarios" element={<CompanyUsersPage />} />
        <Route path="/minha-conta/contatos" element={<PermissionRoute permission={PERM.PAGE_CONTACTS}><CompanyContactsPage /></PermissionRoute>} />
        <Route path="/minha-conta/campanhas" element={<PermissionRoute permission={PERM.PAGE_CAMPAIGNS}><CampaignsPage /></PermissionRoute>} />
        <Route path="/minha-conta/agendamentos" element={<PermissionRoute permission={PERM.PAGE_APPOINTMENTS}><CompanyAppointmentsPage /></PermissionRoute>} />
        <Route path="/minha-conta/tags" element={<PermissionRoute permission={PERM.PAGE_TAGS}><CompanyTagsPage /></PermissionRoute>} />
        <Route path="/minha-conta/suporte/solicitacoes" element={<CompanySupportTicketPage />} />
        <Route
          path="/minha-conta/suporte/solicitacoes/:ticketId"
          element={<CompanySupportTicketRoute />}
        />

        {/* Rota publica autenticada para solicitacao de suporte geral */}
        <Route path="/suporte" element={<SupportRequestPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  );
}

export default AppRoutes;

