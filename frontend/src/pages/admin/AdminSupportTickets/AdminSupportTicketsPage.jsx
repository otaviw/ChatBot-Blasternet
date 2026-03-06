import './AdminSupportTicketsPage.css';
import { useEffect, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';

function formatTicketNumber(value) {
  const number = Number.parseInt(String(value ?? ''), 10);
  if (!number || number < 0) {
    return '-';
  }

  return String(number).padStart(6, '0');
}

function formatDate(value) {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';
  return date.toLocaleString('pt-BR');
}

function TicketSection({
  title,
  emptyText,
  tickets,
  actionBusyId,
  onToggleStatus,
}) {
  return (
    <section className="border border-[#e3e3e0] rounded-lg p-4">
      <h2 className="font-medium mb-3">{title}</h2>
      {!tickets.length && <p className="text-sm text-[#64748b]">{emptyText}</p>}
      {!!tickets.length && (
        <ul className="space-y-3">
          {tickets.map((ticket) => {
            const nextStatus = ticket.status === 'open' ? 'closed' : 'open';
            const actionLabel = ticket.status === 'open' ? 'Marcar como fechada' : 'Reabrir';

            return (
              <li key={ticket.id} className="border border-[#e3e3e0] rounded p-3">
                <div className="flex items-center justify-between gap-3 mb-2">
                  <a
                    href={`/admin/suporte/solicitacoes/${ticket.id}`}
                    className="text-sm font-semibold text-[#0f172a] hover:underline"
                  >
                    #{formatTicketNumber(ticket.ticket_number)} - {ticket.subject}
                  </a>
                  <button
                    type="button"
                    onClick={() => onToggleStatus(ticket, nextStatus)}
                    disabled={actionBusyId === ticket.id}
                    className="px-3 py-1 text-xs rounded border border-[#d5d5d2]"
                  >
                    {actionBusyId === ticket.id ? 'Salvando...' : actionLabel}
                  </button>
                </div>

                <p className="text-sm text-[#334155] whitespace-pre-wrap">{ticket.message}</p>

                <div className="mt-3 text-xs text-[#64748b] space-y-1">
                  <p>Empresa: {ticket.company_name ?? ticket.requester_company_name ?? 'Sem empresa'}</p>
                  <p>Solicitante: {ticket.requester_name} ({ticket.requester_contact ?? '-'})</p>
                  <p>Criada em: {formatDate(ticket.created_at)}</p>
                  <p>Status: {ticket.status === 'open' ? 'aberta' : 'fechada'}</p>
                  {ticket.status === 'closed' && (
                    <p>Fechada em: {formatDate(ticket.closed_at)} {ticket.managed_by_name ? `por ${ticket.managed_by_name}` : ''}</p>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}

function AdminSupportTicketsPage() {
  const { data, loading, error } = usePageData('/admin/suporte/solicitacoes');
  const { logout } = useLogout();

  const [openTickets, setOpenTickets] = useState([]);
  const [closedTickets, setClosedTickets] = useState([]);
  const [companies, setCompanies] = useState([]);
  const [companyFilter, setCompanyFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [busy, setBusy] = useState(false);
  const [actionBusyId, setActionBusyId] = useState(null);
  const [actionError, setActionError] = useState('');

  useEffect(() => {
    if (!data) return;
    setOpenTickets(data.open_tickets ?? []);
    setClosedTickets(data.closed_tickets ?? []);
    setCompanies(data.companies ?? []);
    setCompanyFilter(String(data.filters?.company_id ?? ''));
    setStatusFilter(String(data.filters?.status ?? ''));
  }, [data]);

  const loadTickets = async (nextCompanyFilter = companyFilter, nextStatusFilter = statusFilter) => {
    setBusy(true);
    setActionError('');

    try {
      const params = new URLSearchParams();
      if (nextCompanyFilter) {
        params.set('company_id', nextCompanyFilter);
      }
      if (nextStatusFilter) {
        params.set('status', nextStatusFilter);
      }

      const suffix = params.toString() ? `?${params.toString()}` : '';
      const response = await api.get(`/admin/suporte/solicitacoes${suffix}`);
      setOpenTickets(response.data?.open_tickets ?? []);
      setClosedTickets(response.data?.closed_tickets ?? []);
      setCompanies(response.data?.companies ?? []);
    } catch (err) {
      setActionError(err.response?.data?.message || 'Falha ao carregar solicitações.');
    } finally {
      setBusy(false);
    }
  };

  const applyFilters = async (event) => {
    event.preventDefault();
    await loadTickets(companyFilter, statusFilter);
  };

  const clearFilters = async () => {
    setCompanyFilter('');
    setStatusFilter('');
    await loadTickets('', '');
  };

  const toggleStatus = async (ticket, nextStatus) => {
    setActionBusyId(ticket.id);
    setActionError('');
    try {
      await api.put(`/admin/suporte/solicitacoes/${ticket.id}/status`, {
        status: nextStatus,
      });
      await loadTickets(companyFilter, statusFilter);
    } catch (err) {
      setActionError(err.response?.data?.message || 'Falha ao atualizar status da solicitação.');
    } finally {
      setActionBusyId(null);
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#64748b]">Carregando solicitacoes de suporte...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Não foi possível carregar as solicitações de suporte.</p>
      </Layout>
    );
  }

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Solicitacoes de suporte</h1>
      <p className="text-sm text-[#64748b] mb-6">
        Gerencie chamados enviados pelos usuarios da plataforma.
      </p>

      <section className="border border-[#e3e3e0] rounded-lg p-4 mb-6">
        <form onSubmit={applyFilters} className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
          <label className="block text-sm">
            Empresa
            <select
              value={companyFilter}
              onChange={(event) => setCompanyFilter(event.target.value)}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white"
            >
              <option value="">Todas</option>
              <option value="none">Sem empresa</option>
              {companies.map((company) => (
                <option key={company.id} value={company.id}>
                  {company.name}
                </option>
              ))}
            </select>
          </label>

          <label className="block text-sm">
            Status
            <select
              value={statusFilter}
              onChange={(event) => setStatusFilter(event.target.value)}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white"
            >
              <option value="">Todos</option>
              <option value="open">Abertas</option>
              <option value="closed">Fechadas</option>
            </select>
          </label>

          <div className="flex gap-2">
            <button
              type="submit"
              disabled={busy}
              className="px-4 py-2 rounded bg-[#2563eb] text-white disabled:opacity-60"
            >
              {busy ? 'Filtrando...' : 'Filtrar'}
            </button>
            <button
              type="button"
              onClick={clearFilters}
              disabled={busy}
              className="px-4 py-2 rounded border border-[#d5d5d2]"
            >
              Limpar
            </button>
          </div>
        </form>
        {actionError && <p className="text-sm text-red-600 mt-3">{actionError}</p>}
      </section>

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <TicketSection
          title={`Abertas (${openTickets.length})`}
          emptyText="Nenhuma solicitação aberta para os filtros atuais."
          tickets={openTickets}
          actionBusyId={actionBusyId}
          onToggleStatus={toggleStatus}
        />

        <TicketSection
          title={`Fechadas (${closedTickets.length})`}
          emptyText="Nenhuma solicitação fechada para os filtros atuais."
          tickets={closedTickets}
          actionBusyId={actionBusyId}
          onToggleStatus={toggleStatus}
        />
      </div>
    </Layout>
  );
}

export default AdminSupportTicketsPage;

