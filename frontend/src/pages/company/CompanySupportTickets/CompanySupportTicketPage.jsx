import { useEffect, useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import { NOTIFICATION_MODULE, NOTIFICATION_REFERENCE_TYPE } from '@/constants/notifications';
import useNotifications from '@/hooks/useNotifications';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';

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

function buildTicketNotificationMap(notifications) {
  return (notifications ?? []).reduce((acc, notification) => {
    if (
      notification?.is_read ||
      notification?.module !== NOTIFICATION_MODULE.SUPPORT ||
      notification?.reference_type !== NOTIFICATION_REFERENCE_TYPE.SUPPORT_TICKET
    ) {
      return acc;
    }

    const ticketId = Number.parseInt(String(notification?.reference_id ?? ''), 10);
    if (!ticketId || ticketId <= 0) {
      return acc;
    }

    const current = acc[ticketId] ?? { hasNew: false, hasChat: false };
    if (notification?.type === 'support_ticket_created') {
      current.hasNew = true;
    }
    if (notification?.type === 'support_ticket_message') {
      current.hasChat = true;
    }

    acc[ticketId] = current;
    return acc;
  }, {});
}

function TicketSection({
  title,
  emptyText,
  tickets,
  ticketNotificationById,
}) {
  return (
    <section className="border border-[#e3e3e0] rounded-lg p-4">
      <h2 className="font-medium mb-3">{title}</h2>
      {!tickets.length && <p className="text-sm text-[#64748b]">{emptyText}</p>}
      {!!tickets.length && (
        <ul className="space-y-3">
          {tickets.map((ticket) => {
            const ticketNotification = ticketNotificationById?.[ticket.id] ?? { hasNew: false, hasChat: false };

            return (
              <li key={ticket.id} className="border border-[#e3e3e0] rounded p-3">
                <div className="flex items-center gap-2 mb-2 flex-wrap">
                  <a href={`/minha-conta/suporte/solicitacoes/${ticket.id}`} className="text-sm font-semibold text-[#0f172a] hover:underline">
                    #{formatTicketNumber(ticket.ticket_number)} - {ticket.subject}
                  </a>

                  {ticketNotification.hasNew && (
                    <span className="inline-flex items-center rounded-full border border-[#facc15] bg-[#fef9c3] px-2 py-0.5 text-[11px] font-medium text-[#854d0e]">
                      Nova
                    </span>
                  )}

                  {ticketNotification.hasChat && (
                    <span className="inline-flex items-center rounded-full border border-[#93c5fd] bg-[#eff6ff] px-2 py-0.5 text-[11px] font-medium text-[#1e40af]">
                      Chat
                    </span>
                  )}
                </div>

                <p className="text-sm text-[#334155] whitespace-pre-wrap">{ticket.message}</p>

                <div className="mt-3 text-xs text-[#64748b] space-y-1">
                  <p>Empresa: {ticket.company_name ?? ticket.requester_company_name ?? '-'}</p>
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

function CompanySupportTicketPage() {
  const { data, loading, error } = usePageData('/suporte/minhas-solicitacoes');
  const { notifications } = useNotifications({ limit: 200 });
  const { logout } = useLogout();

  const [openTickets, setOpenTickets] = useState([]);
  const [closedTickets, setClosedTickets] = useState([]);

  const ticketNotificationById = useMemo(
    () => buildTicketNotificationMap(notifications),
    [notifications]
  );

  useEffect(() => {
    if (!data) return;
    setOpenTickets(data.open_tickets ?? []);
    setClosedTickets(data.closed_tickets ?? []);
  }, [data]);

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#64748b]">Carregando solicitacoes de suporte...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Nao foi possivel carregar as solicitacoes de suporte.</p>
      </Layout>
    );
  }

  const role = data.role === 'admin' ? 'admin' : 'company';

  return (
    <Layout
      role={role}
      companyName={role === 'company' ? (data.company_name ?? 'Empresa') : undefined}
      onLogout={logout}
    >
      <h1 className="text-xl font-medium mb-2">Minhas solicitacoes de suporte</h1>
      <p className="text-sm text-[#64748b] mb-6">
        Acompanhe apenas as solicitacoes abertas por voce.
      </p>

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <TicketSection
          title={`Abertas (${openTickets.length})`}
          emptyText="Voce nao possui solicitacoes abertas."
          tickets={openTickets}
          ticketNotificationById={ticketNotificationById}
        />

        <TicketSection
          title={`Fechadas (${closedTickets.length})`}
          emptyText="Voce nao possui solicitacoes fechadas."
          tickets={closedTickets}
          ticketNotificationById={ticketNotificationById}
        />
      </div>
    </Layout>
  );
}

export default CompanySupportTicketPage;
