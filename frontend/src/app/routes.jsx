import './routes.css';
import { Navigate, Route, Routes, useParams } from 'react-router-dom';
import EntrarPage from '@/pages/Entrar/EntrarPage.jsx';
import DashboardPage from '@/pages/Dashboard/DashboardPage.jsx';
import AdminCompaniesPage from '@/pages/admin/AdminCompanies/AdminCompaniesPage.jsx';
import AdminCompanyShowPage from '@/pages/admin/AdminCompanyShow/AdminCompanyShowPage.jsx';
import AdminSimulatorPage from '@/pages/admin/AdminSimulator/AdminSimulatorPage.jsx';
import AdminInboxPage from '@/pages/admin/AdminInbox/AdminInboxPage.jsx';
import AdminUsersPage from '@/pages/admin/AdminUsers/AdminUsersPage.jsx';
import AdminSupportTicketsPage from '@/pages/admin/AdminSupportTickets/AdminSupportTicketsPage.jsx';
import AdminTicketIndex from '@/pages/admin/AdminSupportTickets/AdminTicketIndex.jsx';
import CompanyBotPage from '@/pages/company/CompanyBot/CompanyBotPage.jsx';
import CompanySimulatorPage from '@/pages/company/CompanySimulator/CompanySimulatorPage.jsx';
import CompanyInboxPage from '@/pages/company/CompanyInbox/CompanyInboxPage.jsx';
import NotFoundPage from '@/pages/NotFound/NotFoundPage.jsx';
import CompanyQuickRepliesPage from '@/pages/company/CompanyQuickReplies/CompanyQuickRepliesPage.jsx';
import CompanyUsersPage from '@/pages/company/CompanyUsers/CompanyUsersPage.jsx';
import CompanySupportTicketPage from '@/pages/company/CompanySupportTickets/CompanySupportTicketPage.jsx';
import CompanyTicketIndex from '@/pages/company/CompanySupportTickets/CompanyTicketIndex.jsx';
import SupportRequestPage from '@/pages/support/SupportRequest/SupportRequestPage.jsx';
import InternalChatPage from '@/pages/shared/InternalChat/InternalChatPage.jsx';

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
    <Routes>
      <Route path="/" element={<Navigate to="/entrar" replace />} />
      <Route path="/entrar" element={<EntrarPage />} />
      <Route path="/dashboard" element={<DashboardPage />} />

      <Route path="/admin/empresas" element={<AdminCompaniesPage />} />
      <Route path="/admin/empresas/:companyId" element={<AdminCompanyShowRoute />} />
      <Route path="/admin/simulador" element={<AdminSimulatorPage />} />
      <Route path="/admin/conversas" element={<AdminInboxPage />} />
      <Route path="/admin/chat-interno" element={<InternalChatPage />} />
      <Route path="/admin/usuarios" element={<AdminUsersPage />} />
      <Route path="/admin/suporte" element={<AdminSupportTicketsPage />} />
      <Route path="/admin/suporte/solicitacoes/:ticketId" element={<AdminSupportTicketRoute />} />

      <Route path="/minha-conta/simulador" element={<CompanySimulatorPage />} />
      <Route path="/minha-conta/conversas" element={<CompanyInboxPage />} />
      <Route path="/minha-conta/chat-interno" element={<InternalChatPage />} />
      <Route path="/minha-conta/bot" element={<CompanyBotPage />} />
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
  );
}

export default AppRoutes;

