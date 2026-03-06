import './routes.css';
import EntrarPage from "@/pages/Entrar/EntrarPage.jsx";
import DashboardPage from "@/pages/Dashboard/DashboardPage.jsx";
import AdminCompaniesPage from "@/pages/admin/AdminCompanies/AdminCompaniesPage.jsx";
import AdminCompanyShowPage from "@/pages/admin/AdminCompanyShow/AdminCompanyShowPage.jsx";
import AdminSimulatorPage from "@/pages/admin/AdminSimulator/AdminSimulatorPage.jsx";
import AdminInboxPage from "@/pages/admin/AdminInbox/AdminInboxPage.jsx";
import AdminUsersPage from "@/pages/admin/AdminUsers/AdminUsersPage.jsx";
import AdminSupportTicketsPage from "@/pages/admin/AdminSupportTickets/AdminSupportTicketsPage.jsx";
import AdminTicketIndex from "@/pages/admin/AdminSupportTickets/AdminTicketIndex.jsx";
import CompanyBotPage from "@/pages/company/CompanyBot/CompanyBotPage.jsx";
import CompanySimulatorPage from "@/pages/company/CompanySimulator/CompanySimulatorPage.jsx";
import CompanyInboxPage from "@/pages/company/CompanyInbox/CompanyInboxPage.jsx";
import NotFoundPage from "@/pages/NotFound/NotFoundPage.jsx";
import CompanyQuickRepliesPage from "@/pages/company/CompanyQuickReplies/CompanyQuickRepliesPage.jsx";
import CompanyUsersPage from "@/pages/company/CompanyUsers/CompanyUsersPage.jsx";
import CompanySupportTicketPage from "@/pages/company/CompanySupportTickets/CompanySupportTicketPage.jsx";
import CompanyTicketIndex from "@/pages/company/CompanySupportTickets/CompanyTicketIndex.jsx";
import SupportRequestPage from "@/pages/support/SupportRequest/SupportRequestPage.jsx";

function AppRoutes() {
  const path = window.location.pathname;
  const segments = path.replace(/^\/+|\/+$/g, "").split("/");

  if (path === "/" || path === "/entrar") {
    return <EntrarPage />;
  }

  if (path === "/dashboard") {
    return <DashboardPage />;
  }

  if (path === "/admin/empresas") {
    return <AdminCompaniesPage />;
  }

  if (path === "/admin/simulador") {
    return <AdminSimulatorPage />;
  }

  if (path === "/admin/conversas") {
    return <AdminInboxPage />;
  }

  if (path === "/admin/usuarios") {
    return <AdminUsersPage />;
  }

  if (path === "/admin/suporte") {
    return <AdminSupportTicketsPage />;
  }

  if (
    segments[0] === "admin" &&
    segments[1] === "suporte" &&
    segments[2] === "solicitacoes" &&
    segments[3]
  ) {
    return <AdminTicketIndex ticketId={segments[3]} />;
  }

  if (segments[0] === "admin" && segments[1] === "empresas" && segments[2]) {
    return <AdminCompanyShowPage companyId={segments[2]} />;
  }

  if (path === "/minha-conta/simulador") {
    return <CompanySimulatorPage />;
  }

  if (path === "/minha-conta/conversas") {
    return <CompanyInboxPage />;
  }

  if (path === "/minha-conta/bot") {
    return <CompanyBotPage />;
  }

  if (path === "/minha-conta/respostas-rapidas") {
    return <CompanyQuickRepliesPage />;
  }

  if (path === "/minha-conta/usuarios") {
    return <CompanyUsersPage />;
  }

  if (path === "/minha-conta/suporte/solicitacoes") {
    return <CompanySupportTicketPage />;
  }

  if (
    segments[0] === "minha-conta" &&
    segments[1] === "suporte" &&
    segments[2] === "solicitacoes" &&
    segments[3]
  ) {
    return <CompanyTicketIndex ticketId={segments[3]} />;
  }

  if (path === "/suporte") {
    return <SupportRequestPage />;
  }

  return <NotFoundPage />;
}

export default AppRoutes;

