import { useEffect, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
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

function TicketSection({
    title,
    emptyText,
    tickets,
}) {
    return (
        <section className="border border-[#e3e3e0] rounded-lg p-4">
            <h2 className="font-medium mb-3">{title}</h2>
            {!tickets.length && <p className="text-sm text-[#64748b]">{emptyText}</p>}
            {!!tickets.length && (
                <ul className="space-y-3">
                    {tickets.map((ticket) => {
                        return (
                            <li key={ticket.id} className="border border-[#e3e3e0] rounded p-3">
                                <div className="flex items-center gap-3 mb-2">
                                    <a href={`/minha-conta/suporte/solicitacoes/${ticket.id}`} className="text-sm font-semibold text-[#0f172a] hover:underline">
                                        #{formatTicketNumber(ticket.ticket_number)} - {ticket.subject}
                                    </a>
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
    const { logout } = useLogout();

    const [openTickets, setOpenTickets] = useState([]);
    const [closedTickets, setClosedTickets] = useState([]);

    useEffect(() => {
        if (!data) return;
        setOpenTickets(data.open_tickets ?? []);
        setClosedTickets(data.closed_tickets ?? []);
    }, [data]);

    if (loading) {
        return (
            <Layout role="company" onLogout={logout}>
                <p className="text-sm text-[#64748b]">Carregando solicitações de suporte...</p>
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

    const role = data.role === 'admin' ? 'admin' : 'company';

    return (
        <Layout
            role={role}
            companyName={role === 'company' ? (data.company_name ?? 'Empresa') : undefined}
            onLogout={logout}
        >
            <h1 className="text-xl font-medium mb-2">Minhas solicitações de suporte</h1>
            <p className="text-sm text-[#64748b] mb-6">
                Acompanhe apenas as solicitações abertas por você.
            </p>

            <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <TicketSection
                    title={`Abertas (${openTickets.length})`}
                    emptyText="Você não possui solicitações abertas."
                    tickets={openTickets}
                />

                <TicketSection
                    title={`Fechadas (${closedTickets.length})`}
                    emptyText="Você não possui solicitações fechadas."
                    tickets={closedTickets}
                />
            </div>
        </Layout>
    );
}

export default CompanySupportTicketPage;
