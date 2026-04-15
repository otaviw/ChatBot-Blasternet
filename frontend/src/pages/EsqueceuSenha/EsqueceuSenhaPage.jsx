import { useState } from 'react';
import { Link } from 'react-router-dom';
import Layout from '@/components/layout/Layout/Layout.jsx';
import api from '@/services/api';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import { Field, TextInput } from '@/components/ui/FormControls/FormControls.jsx';
import { showError, showSuccess } from '@/services/toastService';

function EsqueceuSenhaPage() {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setBusy(true);

    try {
      await api.get('/sanctum/csrf-cookie');
      await api.post('/forgot-password', { email });
      setSent(true);
      showSuccess('Se o email existir, o link de redefinição foi enviado.');
    } catch (err) {
      showError(err.response?.data?.message || 'Ocorreu um erro. Tente novamente.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Layout>
      <div className="mx-auto max-w-md">
        <Card>
          <PageHeader
            title="Esqueceu a senha?"
            subtitle="Informe seu email e enviaremos um link para redefinir sua senha."
          />

          {sent ? (
            <p className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50/80 px-4 py-3 text-sm text-emerald-800">
              Se o email estiver cadastrado, voce recebera as instrucoes em breve.
            </p>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4 mt-4">
              <Field label="E-mail">
                <TextInput
                  type="email"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  placeholder="seu@email.com"
                  required
                />
              </Field>

              <Button type="submit" variant="primary" className="w-full" disabled={busy}>
                {busy ? 'Enviando...' : 'Enviar link de redefinição'}
              </Button>
            </form>
          )}

          <p className="mt-4 text-center text-sm text-[#737373]">
            <Link to="/entrar" className="text-[#2563eb] hover:underline">
              Voltar para o login
            </Link>
          </p>
        </Card>
      </div>
    </Layout>
  );
}

export default EsqueceuSenhaPage;
