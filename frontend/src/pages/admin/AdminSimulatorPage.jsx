import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import usePageData from '../../hooks/usePageData';
import useLogout from '../../hooks/useLogout';
import api from '../../lib/api';

function AdminSimulatorPage() {
  const { data, loading, error } = usePageData('/admin/empresas');
  const { logout } = useLogout();
  const [companyId, setCompanyId] = useState('');
  const [from, setFrom] = useState('5511999999999');
  const [text, setText] = useState('');
  const [sendOutbound, setSendOutbound] = useState(true);
  const [result, setResult] = useState(null);
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const firstCompanyId = data?.companies?.[0]?.id;
    if (!firstCompanyId) return;
    if (!companyId) {
      setCompanyId(String(firstCompanyId));
    }
  }, [data, companyId]);

  const runSimulation = async (event) => {
    event.preventDefault();
    if (!companyId) return;

    setBusy(true);
    setActionError('');
    setResult(null);

    try {
      const response = await api.post('/simular/mensagem', {
        company_id: Number(companyId),
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
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando simulador...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar o simulador.</p>
      </Layout>
    );
  }

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Simulador (admin)</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
        Execute testes de mensagem para qualquer empresa.
      </p>

      <form onSubmit={runSimulation} className="space-y-4 max-w-2xl">
        <label className="block text-sm">
          Empresa
          <select
            value={companyId}
            onChange={(e) => setCompanyId(e.target.value)}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
          >
            {(data.companies ?? []).map((company) => (
              <option key={company.id} value={company.id}>
                {company.name}
              </option>
            ))}
          </select>
        </label>

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
            <li>Empresa ID: {result.company_id}</li>
            <li>Conversa ID: {result.conversation?.id}</li>
            <li>Resposta do bot: {result.reply ?? '(sem resposta automatica: conversa em modo manual)'}</li>
            <li>Envio externo: {result.was_sent ? 'Sim' : 'Nao'}</li>
          </ul>
        </section>
      )}
    </Layout>
  );
}

export default AdminSimulatorPage;
