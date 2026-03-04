import './SupportRequestPage.css';
import { useState } from 'react';
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

function SupportRequestPage() {
  const { data, loading, error } = usePageData('/dashboard');
  const { logout } = useLogout();
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState('');
  const [successTicket, setSuccessTicket] = useState(null);

  const submitTicket = async (event) => {
    event.preventDefault();
    setBusy(true);
    setActionError('');
    setSuccessTicket(null);

    try {
      const response = await api.post('/suporte/solicitacoes', {
        subject: subject.trim(),
        message: message.trim(),
      });

      setSuccessTicket(response.data?.ticket ?? null);
      setSubject('');
      setMessage('');
    } catch (err) {
      setActionError(err.response?.data?.message || 'Falha ao enviar solicitacao de suporte.');
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout>
        <p className="text-sm text-[#64748b]">Carregando suporte...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Nao foi possivel carregar o modulo de suporte.</p>
      </Layout>
    );
  }

  const role = data.role === 'admin' ? 'admin' : 'company';
  const companyLabel = data.role === 'company'
    ? data.companyName ?? 'Empresa'
    : 'Sistema';

  return (
    <Layout
      role={role}
      companyName={data.role === 'company' ? companyLabel : undefined}
      onLogout={logout}
    >
      <h1 className="text-xl font-medium mb-2">Abrir solicitacao de suporte</h1>
      <p className="text-sm text-[#64748b] mb-6">
        Envie um chamado para o time de suporte com o maximo de detalhes possivel.
      </p>

      <section className="border border-[#e3e3e0] rounded-lg p-4 mb-6">
        <h2 className="font-medium mb-3">Dados do solicitante</h2>
        <ul className="text-sm space-y-1">
          <li>Nome: <strong>{data.user?.name ?? '-'}</strong></li>
          <li>Contato: <strong>{data.user?.email ?? '-'}</strong></li>
          <li>Empresa: <strong>{companyLabel}</strong></li>
        </ul>
      </section>

      <section className="border border-[#e3e3e0] rounded-lg p-4">
        <form onSubmit={submitTicket} className="space-y-4 max-w-3xl">
          <label className="block text-sm">
            Titulo da solicitacao (assunto)
            <input
              type="text"
              value={subject}
              onChange={(event) => setSubject(event.target.value)}
              required
              maxLength={190}
              placeholder="Ex.: erro ao enviar resposta manual"
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white"
            />
          </label>

          <label className="block text-sm">
            Descricao completa do problema
            <textarea
              value={message}
              onChange={(event) => setMessage(event.target.value)}
              required
              rows={7}
              maxLength={8000}
              placeholder="Descreva o problema, quando acontece e o impacto no atendimento."
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white"
            />
          </label>

          <button
            type="submit"
            disabled={busy}
            className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
          >
            {busy ? 'Enviando...' : 'Enviar solicitacao'}
          </button>
        </form>

        {actionError && <p className="text-sm text-red-600 mt-3">{actionError}</p>}
        {successTicket && (
          <p className="text-sm text-green-700 mt-3">
            Solicitacao criada com sucesso. Numero #{formatTicketNumber(successTicket.ticket_number)}.
          </p>
        )}
      </section>
    </Layout>
  );
}

export default SupportRequestPage;

