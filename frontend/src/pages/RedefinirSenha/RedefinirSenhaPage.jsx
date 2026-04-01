import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import Layout from '@/components/layout/Layout/Layout.jsx';
import api from '@/services/api';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import Notice from '@/components/ui/Notice/Notice.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import { Field, TextInput } from '@/components/ui/FormControls/FormControls.jsx';

function RedefinirSenhaPage() {
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') ?? '';
  const email = searchParams.get('email') ?? '';

  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError('');

    try {
      await api.get('/sanctum/csrf-cookie');
      await api.post('/reset-password', {
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });
      setSuccess(true);
    } catch (err) {
      setError(err.response?.data?.message || 'Não foi possível redefinir a senha. Tente novamente.');
    } finally {
      setBusy(false);
    }
  };

  if (!token || !email) {
    return (
      <Layout>
        <div className="mx-auto max-w-md">
          <Card>
            <Notice tone="danger">
              Link inválido. Solicite um novo link de redefinição de senha.
            </Notice>
            <p className="mt-4 text-center text-sm text-[#737373]">
              <Link to="/esqueceu-senha" className="text-[#2563eb] hover:underline">
                Solicitar novo link
              </Link>
            </p>
          </Card>
        </div>
      </Layout>
    );
  }

  return (
    <Layout>
      <div className="mx-auto max-w-md">
        <Card>
          <PageHeader
            title="Redefinir senha"
            subtitle="Crie uma nova senha para a sua conta."
          />

          {error && <Notice tone="danger" className="mt-3">{error}</Notice>}

          {success ? (
            <div className="space-y-4 mt-3">
              <Notice tone="success">
                Senha redefinida com sucesso!
              </Notice>
              <p className="text-center text-sm text-[#737373]">
                <Link to="/entrar" className="text-[#2563eb] hover:underline">
                  Ir para o login
                </Link>
              </p>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4 mt-4">
              <Field label="Nova senha">
                <TextInput
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Mínimo 6 caracteres"
                  required
                />
              </Field>

              <Field label="Confirmar nova senha">
                <TextInput
                  type="password"
                  value={passwordConfirmation}
                  onChange={(e) => setPasswordConfirmation(e.target.value)}
                  placeholder="Repita a nova senha"
                  required
                />
              </Field>

              <Button type="submit" variant="primary" className="w-full" disabled={busy}>
                {busy ? 'Salvando...' : 'Salvar nova senha'}
              </Button>

              <p className="text-center text-sm text-[#737373]">
                <Link to="/entrar" className="text-[#2563eb] hover:underline">
                  Voltar para o login
                </Link>
              </p>
            </form>
          )}
        </Card>
      </div>
    </Layout>
  );
}

export default RedefinirSenhaPage;
