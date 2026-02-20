import React, { useState } from 'react';
import Layout from '../../components/Layout';
import usePageData from '../../hooks/usePageData';
import useLogout from '../../hooks/useLogout';
import api from '../../lib/api';

function CompanySimulatorPage() {
  const { data, loading, error } = usePageData('/minha-conta/bot');
  const { logout } = useLogout();
  const [from, setFrom] = useState('5511999999999');
  const [text, setText] = useState('');
  const [sendOutbound, setSendOutbound] = useState(true);
  const [result, setResult] = useState(null);
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);

  const runSimulation = async (event) => {
    event.preventDefault();
    if (!data?.company?.id) return;

    setBusy(true);
    setActionError('');
    setResult(null);

    try {
      const response = await api.post('/simular/mensagem', {
        company_id: data.company.id,
        from,
        text,
        send_outbound: sendOutbound,
      });
      setResult(response.data);
    } catch (err) {
      setActionError(err.response?.data?.message || 'Falha ao simular mensagem.');
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando simulador...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !data?.company) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar o simulador.</p>
      </Layout>
    );
  }

  return (
    <Layout role="company" companyName={data.company.name} onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Simulador - {data.company.name}</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
        Teste respostas do bot sem depender da Meta.
      </p>

      <form onSubmit={runSimulation} className="space-y-4 max-w-2xl">
        <label className="block text-sm">
          Telefone do cliente
          <input
            type="text"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
          />
        </label>

        <label className="block text-sm">
          Mensagem recebida
          <textarea
            value={text}
            onChange={(e) => setText(e.target.value)}
            rows={4}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
          />
        </label>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={sendOutbound}
            onChange={(e) => setSendOutbound(e.target.checked)}
          />
          Tentar envio externo (se tiver credenciais)
        </label>

        <button
          type="submit"
          disabled={busy}
          className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
        >
          {busy ? 'Simulando...' : 'Simular mensagem'}
        </button>
      </form>

      {actionError && <p className="text-sm text-red-600 mt-4">{actionError}</p>}

      {result && (
        <section className="mt-6 border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-2">Resultado</h2>
          <ul className="text-sm space-y-1">
            <li>Conversa ID: {result.conversation?.id}</li>
            <li>Inbound ID: {result.in_message?.id}</li>
            <li>Outbound ID: {result.out_message?.id ?? '-'}</li>
            <li>Resposta do bot: {result.reply ?? '(sem resposta automatica: conversa em modo manual)'}</li>
            <li>Envio externo: {result.was_sent ? 'Sim' : 'Nao'}</li>
          </ul>
        </section>
      )}
    </Layout>
  );
}

export default CompanySimulatorPage;
