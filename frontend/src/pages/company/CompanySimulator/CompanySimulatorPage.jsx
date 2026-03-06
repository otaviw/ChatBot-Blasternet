import './CompanySimulatorPage.css';
import { useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import MessageSimulatorCard from '@/components/sections/simulator/MessageSimulatorCard/MessageSimulatorCard.jsx';
import SimulationResultCard from '@/components/sections/simulator/SimulationResultCard/SimulationResultCard.jsx';
import Notice from '@/components/ui/Notice/Notice.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';

function CompanySimulatorPage() {
  const { data, loading, error } = usePageData('/minha-conta/bot');
  const { logout } = useLogout();
  const [from, setFrom] = useState('5511999999999');
  const [text, setText] = useState('');
  const [imageFile, setImageFile] = useState(null);
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
      let response;
      if (imageFile) {
        const formData = new FormData();
        formData.append('company_id', String(data.company.id));
        formData.append('from', from);
        formData.append('text', text);
        formData.append('send_outbound', sendOutbound ? '1' : '0');
        formData.append('image', imageFile);
        response = await api.post('/simular/mensagem', formData);
      } else {
        response = await api.post('/simular/mensagem', {
          company_id: data.company.id,
          from,
          text,
          send_outbound: sendOutbound,
        });
      }
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
        <p className="text-sm text-[#64748b]">Carregando simulador...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !data?.company) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Não foi possível carregar o simulador.</p>
      </Layout>
    );
  }

  return (
    <Layout role="company" companyName={data.company.name} onLogout={logout}>
      <PageHeader
        title={`Simulador - ${data.company.name}`}
        subtitle="Teste respostas do bot sem depender da Meta e valide ajustes antes de publicar."
      />

      <MessageSimulatorCard
        title="Enviar mensagem de teste"
        subtitle="Use este formulário para validar fluxo automático e fallback."
        from={from}
        onFromChange={setFrom}
        text={text}
        onTextChange={setText}
        imageFile={imageFile}
        onImageChange={setImageFile}
        onRemoveImage={() => setImageFile(null)}
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
            { label: 'Conversa ID', value: result.conversation?.id ?? '-' },
            { label: 'Inbound ID', value: result.in_message?.id ?? '-' },
            { label: 'Tipo inbound', value: result.in_message?.content_type ?? 'text' },
            { label: 'Imagem inbound', value: result.in_message?.media_url ?? '-' },
            { label: 'Outbound ID', value: result.out_message?.id ?? '-' },
            {
              label: 'Resposta do bot',
              value: result.reply ?? '(sem resposta automatica: conversa em modo manual)',
            },
            { label: 'Envio externo', value: result.was_sent ? 'Sim' : 'Não' },
          ]}
        />
      )}
    </Layout>
  );
}

export default CompanySimulatorPage;




