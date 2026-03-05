import './EntrarPage.css';
import { useState, useEffect } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import api from '@/services/api';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import Notice from '@/components/ui/Notice/Notice.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import { Field, TextInput } from '@/components/ui/FormControls/FormControls.jsx';

function EntrarPage() {
  const { data, loading, error } = usePageData('/entrar');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (data?.authenticated) {
      window.location.href = '/dashboard';
    }
  }, [data]);

  const handleLogin = async (event) => {
    event.preventDefault();
    setBusy(true);
    setActionError('');

    try {
      await api.get('/sanctum/csrf-cookie');

      await api.post('/login', { email, password });
      window.location.href = '/dashboard';
    } catch (err) {
      setActionError(err.response?.data?.message || 'Falha no login.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Layout>
      <div className="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[1.05fr_.95fr]">
        <Card className="relative overflow-hidden">
          <div className="pointer-events-none absolute right-0 top-0 h-32 w-32 rounded-full bg-[#2563eb]/10 blur-2xl" />
          <PageHeader
            title="Acessar painel"
            subtitle="Entre com seu usuário para monitorar conversas, bot e atendimento manual em um único fluxo."
          />

          {loading && <Notice tone="info">Carregando dados de autenticação...</Notice>}
          {error && <Notice tone="danger">Erro ao carregar dados de entrada. Tente novamente.</Notice>}
          {actionError && <Notice tone="danger" className="mt-3">{actionError}</Notice>}

          {!loading && !error && (
            <form onSubmit={handleLogin} className="space-y-4">
              <Field label="Email">
                <TextInput
                  type="email"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  required
                />
              </Field>

              <Field label="Senha">
                <TextInput
                  type="password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  required
                />
              </Field>

              <Button type="submit" variant="primary" className="w-full" disabled={busy}>
                {busy ? 'Entrando...' : 'Entrar no painel'}
              </Button>
            </form>
          )}
        </Card>

        <div className="space-y-4">
          <Card>
            <h2 className="text-lg font-semibold text-[#0f172a] mb-2">Fluxo otimizado para operação</h2>
            <ul className="space-y-2 text-sm text-[#475569]">
              <li>Painel único para conversas, usuários e configurações do bot.</li>
              <li>Atualização em tempo real de mensagens e transferencias.</li>
              <li>Ações manuais com histórico completo para auditoria.</li>
            </ul>
          </Card>
        </div>
      </div>
    </Layout>
  );
}

export default EntrarPage;




