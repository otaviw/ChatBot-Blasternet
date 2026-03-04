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

function AdminSupportTicketShowPage({ ticketId }) {
  const { logout } = useLogout();
  const { data, loading, error } = usePageData(`/admin/suporte/solicitacoes/${ticketId}`);
  const [ticket, setTicket] = useState(null);
  const [actionState, setActionState] = useState('idle');
  const [actionError, setActionError] = useState('');

  useEffect(() => {
    if (!data?.ticket) return;
    setTicket(data.ticket);
    setActionError('');
    setActionState('idle');
  }, [data]);

  const updateTicketStatus = async (status) => {
    if (!ticket) return;
    setActionState('saving');
    setActionError('');

    try {
      const response = await api.put(`/admin/suporte/solicitacoes/${ticket.id}/status`, { status });
      setTicket(response.data?.ticket ?? { ...ticket, status });
      setActionState('saved');
      setTimeout(() => setActionState('idle'), 1200);
    } catch (err) {
      setActionState('error');
      setActionError(err.response?.data?.message || 'Falha ao atualizar o ticket.');
    }
  };

  const closeTicket = async () => {
    await updateTicketStatus('closed');
  };

  const reopenTicket = async () => {
    await updateTicketStatus('open');
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#64748b]">Carregando solicitacao...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !ticket) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-red-600">
          Nao foi possivel carregar a solicitacao.
        </p>
      </Layout>
    );
  }

  const isOpen = ticket.status === 'open';

  return (
    <Layout role="admin" onLogout={logout}>
      <div className="mb-4">
        <a href="/admin/suporte" className="text-sm text-[#64748b] hover:underline">
          {'<-'} Voltar para suporte
        </a>
      </div>

      <div className="flex items-start justify-between gap-3 mb-4">
        <div>
          <h1 className="text-xl font-medium">
            Solicitacao #{formatTicketNumber(ticket.ticket_number)}
          </h1>
          <p className="text-sm text-[#64748b] mt-1">
            {ticket.subject ?? '(sem assunto)'}
          </p>
        </div>

        <div className="flex items-center gap-2">
          <span
            className={
              `text-xs px-2 py-1 rounded border ` +
              (isOpen
                ? 'border-green-300 text-green-700 bg-green-50'
                : 'border-gray-300 text-gray-700 bg-gray-50')
            }
          >
            {isOpen ? 'Aberta' : 'Fechada'}
          </span>

          {isOpen ? (
            <button
              type="button"
              onClick={closeTicket}
              disabled={actionState === 'saving'}
              className="px-3 py-2 text-sm rounded bg-[#f53003] text-white disabled:opacity-60"
            >
              {actionState === 'saving' ? 'Fechando...' : 'Fechar'}
            </button>
          ) : (
            <button
              type="button"
              onClick={reopenTicket}
              disabled={actionState === 'saving'}
              className="px-3 py-2 text-sm rounded border border-[#d5d5d2] disabled:opacity-60"
            >
              {actionState === 'saving' ? 'Reabrindo...' : 'Reabrir'}
            </button>
          )}
        </div>
      </div>

      {actionState === 'error' && (
        <p className="text-sm text-red-600 mb-4">{actionError}</p>
      )}

      <section className="mb-6 border border-[#e3e3e0] rounded-lg p-4">
        <h2 className="font-medium mb-3">Dados do solicitante</h2>
        <ul className="text-sm space-y-1">
          <li><strong>Empresa:</strong> {ticket.company_name ?? ticket.requester_company_name ?? '-'}</li>
          <li><strong>Solicitante:</strong> {ticket.requester_name ?? '-'}</li>
          <li><strong>Contato:</strong> {ticket.requester_contact ?? '-'}</li>
          <li><strong>Criado em:</strong> {formatDate(ticket.created_at)}</li>
          {!isOpen && (
            <>
              <li><strong>Fechado em:</strong> {formatDate(ticket.closed_at)}</li>
              <li><strong>Fechado por:</strong> {ticket.managed_by_name ?? '-'}</li>
            </>
          )}
        </ul>
      </section>

      <section className="border border-[#e3e3e0] rounded-lg p-4">
        <h2 className="font-medium mb-3">Descricao</h2>
        <div className="text-sm whitespace-pre-wrap leading-relaxed text-[#1f1f1e]">
          {ticket.message ?? '(sem mensagem)'}
        </div>
      </section>
    </Layout>
  );
}

export default AdminSupportTicketShowPage;
