import './routes.css';
import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes, useParams } from 'react-router-dom';

const EntrarPage = lazy(() => import('@/pages/Entrar/EntrarPage.jsx'));
const EsqueceuSenhaPage = lazy(() => import('@/pages/EsqueceuSenha/EsqueceuSenhaPage.jsx'));
const RedefinirSenhaPage = lazy(() => import('@/pages/RedefinirSenha/RedefinirSenhaPage.jsx'));
const DashboardPage = lazy(() => import('@/pages/Dashboard/DashboardPage.jsx'));
const AdminCompaniesPage = lazy(() => import('@/pages/admin/AdminCompanies/AdminCompaniesPage.jsx'));
const AdminCompanyShowPage = lazy(() => import('@/pages/admin/AdminCompanyShow/AdminCompanyShowPage.jsx'));
const AdminSimulatorPage = lazy(() => import('@/pages/admin/AdminSimulator/AdminSimulatorPage.jsx'));
const AdminInboxPage = lazy(() => import('@/pages/admin/AdminInbox/AdminInboxPage.jsx'));
const AdminUsersPage = lazy(() => import('@/pages/admin/AdminUsers/AdminUsersPage.jsx'));
const AdminSupportTicketsPage = lazy(
  () => import('@/pages/admin/AdminSupportTickets/AdminSupportTicketsPage.jsx')
);
const AdminTicketIndex = lazy(() => import('@/pages/admin/AdminSupportTickets/AdminTicketIndex.jsx'));
const CompanyBotPage = lazy(() => import('@/pages/company/CompanyBot/CompanyBotPage.jsx'));
const CompanySimulatorPage = lazy(
  () => import('@/pages/company/CompanySimulator/CompanySimulatorPage.jsx')
);
const CompanyInboxPage = lazy(() => import('@/pages/company/CompanyInbox/CompanyInboxPage.jsx'));
const NotFoundPage = lazy(() => import('@/pages/NotFound/NotFoundPage.jsx'));
const CompanyQuickRepliesPage = lazy(
  () => import('@/pages/company/CompanyQuickReplies/CompanyQuickRepliesPage.jsx')
);
const CompanyKnowledgeBasePage = lazy(
  () => import('@/pages/company/CompanyKnowledgeBase/CompanyKnowledgeBasePage.jsx')
);
const CompanyUsersPage = lazy(() => import('@/pages/company/CompanyUsers/CompanyUsersPage.jsx'));
const AiSettingsPage = lazy(() => import('@/pages/company/AiSettings/AiSettingsPage.jsx'));
const CompanyAiAnalyticsPage = lazy(
  () => import('@/pages/company/CompanyAiAnalytics/CompanyAiAnalyticsPage.jsx')
);
const CompanyAiAuditPage = lazy(
  () => import('@/pages/company/CompanyAiAudit/CompanyAiAuditPage.jsx')
);
const CompanySupportTicketPage = lazy(
  () => import('@/pages/company/CompanySupportTickets/CompanySupportTicketPage.jsx')
);
const CompanyTicketIndex = lazy(
  () => import('@/pages/company/CompanySupportTickets/CompanyTicketIndex.jsx')
);
const SupportRequestPage = lazy(() => import('@/pages/support/SupportRequest/SupportRequestPage.jsx'));
const InternalChatPage = lazy(() => import('@/pages/shared/InternalChat/InternalChatPage.jsx'));
const InternalAiChatPage = lazy(() => import('@/pages/shared/InternalAiChat/InternalAiChatPage.jsx'));

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

function AppRoutes() {
  return (
    <Suspense fallback={<p className="text-sm text-[#706f6c]">Carregando página...</p>}>
      <Routes>
        <Route path="/" element={<Navigate to="/entrar" replace />} />
        <Route path="/entrar" element={<EntrarPage />} />
        <Route path="/esqueceu-senha" element={<EsqueceuSenhaPage />} />
        <Route path="/redefinir-senha" element={<RedefinirSenhaPage />} />
        <Route path="/dashboard" element={<DashboardPage />} />

        <Route path="/admin/empresas" element={<AdminCompaniesPage />} />
        <Route path="/admin/empresas/:companyId" element={<AdminCompanyShowRoute />} />
        <Route path="/admin/simulador" element={<AdminSimulatorPage />} />
        <Route path="/admin/conversas" element={<AdminInboxPage />} />
        <Route path="/admin/chat-interno" element={<InternalChatPage />} />
        <Route path="/admin/chat-ia" element={<InternalAiChatPage />} />
        <Route path="/admin/usuarios" element={<AdminUsersPage />} />
        <Route path="/admin/suporte" element={<AdminSupportTicketsPage />} />
        <Route
          path="/admin/suporte/solicitacoes/:ticketId"
          element={<AdminSupportTicketRoute />}
        />

        <Route path="/minha-conta/simulador" element={<CompanySimulatorPage />} />
        <Route path="/minha-conta/conversas" element={<CompanyInboxPage />} />
        <Route path="/minha-conta/chat-interno" element={<InternalChatPage />} />
        <Route path="/minha-conta/chat-ia" element={<InternalAiChatPage />} />
        <Route path="/minha-conta/ia/analytics" element={<CompanyAiAnalyticsPage />} />
        <Route path="/minha-conta/ia/auditoria" element={<CompanyAiAuditPage />} />
        <Route path="/minha-conta/ia/configuracoes" element={<AiSettingsPage />} />
        <Route path="/minha-conta/bot" element={<CompanyBotPage />} />
        <Route path="/minha-conta/base-conhecimento" element={<CompanyKnowledgeBasePage />} />
        <Route path="/minha-conta/respostas-rapidas" element={<CompanyQuickRepliesPage />} />
        <Route path="/minha-conta/usuarios" element={<CompanyUsersPage />} />
        <Route path="/minha-conta/suporte/solicitacoes" element={<CompanySupportTicketPage />} />
        <Route
          path="/minha-conta/suporte/solicitacoes/:ticketId"
          element={<CompanySupportTicketRoute />}
        />

        <Route path="/suporte" element={<SupportRequestPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  );
}

export default AppRoutes;

