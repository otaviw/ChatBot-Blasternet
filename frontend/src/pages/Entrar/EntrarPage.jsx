import './EntrarPage.css';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import api from '@/services/api';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import Notice from '@/components/ui/Notice/Notice.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import { Field, TextInput } from '@/components/ui/FormControls/FormControls.jsx';
import { showError } from '@/services/toastService';

function EntrarPage() {
  const { data, loading, error } = usePageData('/entrar');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (data?.authenticated) {
      window.location.href = '/dashboard';
    }
  }, [data]);

  const handleLogin = async (event) => {
    event.preventDefault();
    setBusy(true);

    try {
      await api.get('/sanctum/csrf-cookie');
      await api.post('/login', { email, password });
      window.location.href = '/dashboard';
    } catch (err) {
      showError(err.response?.data?.message || 'Falha no login.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Layout>
      <div className="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[1.05fr_.95fr]">
        <Card>
          <PageHeader
            title="Acessar painel"
            subtitle="Entre com seu usuário para monitorar conversas, bot e atendimento manual."
          />

          {loading ? <Notice tone="info">Carregando dados de autenticação...</Notice> : null}
          {error ? <Notice tone="danger">Erro ao carregar dados de entrada. Tente novamente.</Notice> : null}

          {!loading && !error ? (
            <form onSubmit={handleLogin} className="space-y-4">
              <Field label="E-mail">
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

              <p className="text-center text-sm text-[#737373]">
                <Link to="/esqueceu-senha" className="text-[#2563eb] hover:underline">
                  Esqueci minha senha
                </Link>
              </p>
            </form>
          ) : null}
        </Card>

        <div className="space-y-4">
          <Card>
            <h2 className="text-base font-semibold text-[#171717] mb-2">Fluxo otimizado para operação</h2>
            <ul className="space-y-2 text-sm text-[#737373] leading-relaxed">
              <li>Painel único para conversas, usuários e configurações do bot.</li>
              <li>Atualizacao em tempo real de mensagens e transferencias.</li>
              <li>Ações manuais com histórico completo para auditoria.</li>
            </ul>
          </Card>
        </div>
      </div>
    </Layout>
  );
}

export default EntrarPage;
