import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import Layout from '@/components/layout/Layout/Layout.jsx';
import api from '@/services/api';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import Notice from '@/components/ui/Notice/Notice.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import { Field, TextInput } from '@/components/ui/FormControls/FormControls.jsx';
import { showError, showSuccess } from '@/services/toastService';

function RedefinirSenhaPage() {
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') ?? '';
  const email = searchParams.get('email') ?? '';

  const [success, setSuccess] = useState(false);
  const [busy, setBusy] = useState(false);
  const {
    register,
    handleSubmit,
    watch,
    formState: { errors, isValid },
  } = useForm({
    mode: 'onChange',
    reValidateMode: 'onChange',
    defaultValues: {
      password: '',
      password_confirmation: '',
    },
  });

  const passwordValue = watch('password');

  const onSubmit = async (values) => {
    setBusy(true);

    try {
      await api.get('/sanctum/csrf-cookie');
      await api.post('/reset-password', {
        token,
        email,
        password: values.password,
        password_confirmation: values.password_confirmation,
      });
      setSuccess(true);
      showSuccess('Senha redefinida com sucesso.');
    } catch (err) {
      showError(err.response?.data?.message || 'Nao foi possivel redefinir a senha.');
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
              Link invalido. Solicite um novo link de redefinicao de senha.
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

          {success ? (
            <div className="space-y-4 mt-3">
              <p className="rounded-lg border border-emerald-200 bg-emerald-50/80 px-4 py-3 text-sm text-emerald-800">
                Senha redefinida com sucesso.
              </p>
              <p className="text-center text-sm text-[#737373]">
                <Link to="/entrar" className="text-[#2563eb] hover:underline">
                  Ir para o login
                </Link>
              </p>
            </div>
          ) : (
            <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4 mt-4">
              <Field label="Nova senha">
                <TextInput
                  type="password"
                  placeholder="Minimo 6 caracteres"
                  aria-invalid={errors.password ? 'true' : 'false'}
                  aria-describedby={errors.password ? 'reset-password-error' : undefined}
                  {...register('password', {
                    required: 'Informe a nova senha.',
                    minLength: { value: 6, message: 'Use ao menos 6 caracteres.' },
                  })}
                />
              </Field>
              {errors.password ? (
                <p id="reset-password-error" className="text-xs text-red-600" role="alert">
                  {errors.password.message}
                </p>
              ) : null}

              <Field label="Confirmar nova senha">
                <TextInput
                  type="password"
                  placeholder="Repita a nova senha"
                  aria-invalid={errors.password_confirmation ? 'true' : 'false'}
                  aria-describedby={errors.password_confirmation ? 'reset-password-confirmation-error' : undefined}
                  {...register('password_confirmation', {
                    required: 'Confirme a nova senha.',
                    validate: (value) => value === passwordValue || 'As senhas precisam ser iguais.',
                  })}
                />
              </Field>
              {errors.password_confirmation ? (
                <p id="reset-password-confirmation-error" className="text-xs text-red-600" role="alert">
                  {errors.password_confirmation.message}
                </p>
              ) : null}

              <Button type="submit" variant="primary" className="w-full" disabled={busy || !isValid}>
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
