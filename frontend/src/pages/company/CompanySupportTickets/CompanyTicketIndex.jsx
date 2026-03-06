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

function CompanyTicketIndex({ ticketId }) {
  const { logout } = useLogout();
  const { data, loading, error } = usePageData(`/suporte/minhas-solicitacoes/${ticketId}`);
  const [ticket, setTicket] = useState(null);

  useEffect(() => {
    if (!data?.ticket) return;
    setTicket(data.ticket);
  }, [data]);

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#64748b]">Carregando solicitação...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !ticket) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-red-600">
          Não foi possível carregar a solicitação.
        </p>
      </Layout>
    );
  }

  return (
    <Layout
      role="company"
      companyName={data.company_name ?? 'Empresa'}
      onLogout={logout}
    >
      <div className="mb-4">
        <a href="/minha-conta/suporte/solicitacoes" className="text-sm text-[#64748b] hover:underline">
          {'<-'} Voltar para minhas solicitações
        </a>
      </div>

      <div className="flex items-start justify-between gap-3 mb-4">
        <div>
          <h1 className="text-xl font-medium">
            Solicitação #{formatTicketNumber(ticket.ticket_number)}
          </h1>
          <p className="text-sm text-[#64748b] mt-1">
            {ticket.subject ?? '(sem assunto)'}
          </p>
        </div>

        <span
          className={
            `text-xs px-2 py-1 rounded border ` +
            (ticket.status === 'open'
              ? 'border-green-300 text-green-700 bg-green-50'
              : 'border-gray-300 text-gray-700 bg-gray-50')
          }
        >
          {ticket.status === 'open' ? 'Aberta' : 'Fechada'}
        </span>
      </div>

      <section className="mb-6 border border-[#e3e3e0] rounded-lg p-4">
        <h2 className="font-medium mb-3">Dados da solicitação</h2>
        <ul className="text-sm space-y-1">
          <li><strong>Empresa:</strong> {ticket.company_name ?? ticket.requester_company_name ?? '-'}</li>
          <li><strong>Solicitante:</strong> {ticket.requester_name ?? '-'}</li>
          <li><strong>Contato:</strong> {ticket.requester_contact ?? '-'}</li>
          <li><strong>Criado em:</strong> {formatDate(ticket.created_at)}</li>
          {ticket.status === 'closed' && (
            <>
              <li><strong>Fechado em:</strong> {formatDate(ticket.closed_at)}</li>
              <li><strong>Fechado por:</strong> {ticket.managed_by_name ?? '-'}</li>
            </>
          )}
        </ul>
      </section>

      <section className="border border-[#e3e3e0] rounded-lg p-4 mb-6">
        <h2 className="font-medium mb-3">Descrição</h2>
        <div className="text-sm whitespace-pre-wrap leading-relaxed text-[#1f1f1e]">
          {ticket.message ?? '(sem mensagem)'}
        </div>
      </section>

      {(ticket.attachments ?? []).length > 0 && (
        <section className="border border-[#e3e3e0] rounded-lg p-4">
          <h2 className="font-medium mb-3">Anexos ({ticket.attachments.length})</h2>
          <div className="flex flex-wrap gap-3">
            {ticket.attachments.map((att) => (
              <a
                key={att.id}
                href={att.url}
                target="_blank"
                rel="noreferrer"
                className="block"
              >
                {(att.mime_type ?? '').startsWith('image/') ? (
                  <img
                    src={att.url}
                    alt="Anexo"
                    className="w-24 h-24 object-cover rounded-lg border border-[#e5e5e5] hover:border-[#2563eb]"
                  />
                ) : (
                  <div className="w-24 h-24 rounded-lg border border-[#e5e5e5] flex items-center justify-center text-xs text-[#737373]">
                    Arquivo
                  </div>
                )}
              </a>
            ))}
          </div>
        </section>
      )}
    </Layout>
  );
}

export default CompanyTicketIndex;
