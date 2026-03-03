import './AdminSimulatorPage.css';
import { useState, useEffect } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import MessageSimulatorCard from '@/components/sections/simulator/MessageSimulatorCard/MessageSimulatorCard.jsx';
import SimulationResultCard from '@/components/sections/simulator/SimulationResultCard/SimulationResultCard.jsx';
import Notice from '@/components/ui/Notice/Notice.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';

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
        <p className="text-sm text-[#64748b]">Carregando simulador...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Nao foi possivel carregar o simulador.</p>
      </Layout>
    );
  }

  const companies = data.companies ?? [];

  return (
    <Layout role="admin" onLogout={logout}>
      <PageHeader
        title="Simulador (admin)"
        subtitle="Execute testes de mensagem para qualquer empresa sem sair do painel."
      />

      <MessageSimulatorCard
        title="Enviar mensagem de teste"
        subtitle="Selecione uma empresa e valide o comportamento atual do bot."
        companies={companies}
        companyId={companyId}
        onCompanyChange={setCompanyId}
        from={from}
        onFromChange={setFrom}
        text={text}
        onTextChange={setText}
        sendOutbound={sendOutbound}
        onSendOutboundChange={setSendOutbound}
        onSubmit={runSimulation}
        busy={busy}
        busyLabel="Simulando..."
        submitLabel="Simular mensagem"
      />

      {actionError && <Notice tone="danger" className="mt-4 max-w-2xl">{actionError}</Notice>}

      {result && (
        <SimulationResultCard
          items={[
            { label: 'Empresa ID', value: result.company_id },
            { label: 'Conversa ID', value: result.conversation?.id ?? '-' },
            {
              label: 'Resposta do bot',
              value: result.reply ?? '(sem resposta automatica: conversa em modo manual)',
            },
            { label: 'Envio externo', value: result.was_sent ? 'Sim' : 'Nao' },
          ]}
        />
      )}
    </Layout>
  );
}

export default AdminSimulatorPage;




