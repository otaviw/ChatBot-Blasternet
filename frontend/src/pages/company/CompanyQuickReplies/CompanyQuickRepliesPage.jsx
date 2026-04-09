import './CompanyQuickRepliesPage.css';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
import ConfirmDialog from '@/components/ui/ConfirmDialog/ConfirmDialog.jsx';
import { showError, showSuccess } from '@/services/toastService';

function CompanyQuickRepliesPage() {
  const { data, loading, error } = usePageData('/minha-conta/respostas-rapidas');
  const { logout } = useLogout();
  const [replies, setReplies] = useState([]);
  const [editingId, setEditingId] = useState(null);
  const [busy, setBusy] = useState(false);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isValid },
  } = useForm({
    mode: 'onChange',
    reValidateMode: 'onChange',
    defaultValues: { title: '', text: '' },
  });

  useEffect(() => {
    setReplies(data?.quick_replies ?? []);
  }, [data]);

  const onSubmit = async (values) => {
    const payload = {
      title: String(values.title ?? '').trim(),
      text: String(values.text ?? '').trim(),
    };

    setBusy(true);
    try {
      if (editingId) {
        const response = await api.put(`/minha-conta/respostas-rapidas/${editingId}`, payload);
        setReplies((prev) =>
          prev.map((reply) => (reply.id === editingId ? response.data.quick_reply : reply))
        );
        showSuccess('Template atualizado com sucesso.');
        setEditingId(null);
      } else {
        const response = await api.post('/minha-conta/respostas-rapidas', payload);
        setReplies((prev) => [...prev, response.data.quick_reply]);
        showSuccess('Template criado com sucesso.');
      }

      reset({ title: '', text: '' });
    } catch (err) {
      showError(err.response?.data?.message || 'Falha ao salvar template.');
    } finally {
      setBusy(false);
    }
  };

  const confirmDelete = async () => {
    if (!deleteTarget?.id) return;

    setDeleteBusy(true);
    try {
      await api.delete(`/minha-conta/respostas-rapidas/${deleteTarget.id}`);
      setReplies((prev) => prev.filter((reply) => reply.id !== deleteTarget.id));
      setDeleteTarget(null);
      showSuccess('Template excluido com sucesso.');
    } catch (err) {
      showError(err.response?.data?.message || 'Falha ao apagar template.');
    } finally {
      setDeleteBusy(false);
    }
  };

  const requestDelete = (reply) => {
    if (!reply?.id || busy) return;
    setDeleteTarget(reply);
  };

  const beginEdit = (reply) => {
    setEditingId(reply.id);
    reset({
      title: String(reply.title ?? ''),
      text: String(reply.text ?? ''),
    });
  };

  const cancelEdit = () => {
    setEditingId(null);
    reset({ title: '', text: '' });
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <div className="space-y-3">
          <LoadingSkeleton className="h-6 w-44" />
          <LoadingSkeleton className="h-4 w-80 max-w-full" />
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <LoadingSkeleton className="h-56 w-full" />
            <LoadingSkeleton className="h-56 w-full" />
          </div>
        </div>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Erro ao carregar.</p>
      </Layout>
    );
  }

  return (
    <Layout role="company" onLogout={logout}>
      <h1 className="app-page-title">Respostas Rapidas</h1>
      <p className="app-page-subtitle mb-6">
        Templates para agilizar o atendimento manual no inbox.
      </p>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <section className="app-panel">
          <h2 className="font-medium mb-3">
            {editingId ? 'Editar template' : 'Novo template'}
          </h2>
          <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-3">
            <label className="block text-sm">
              Titulo (atalho)
              <input
                type="text"
                placeholder="Ex: Saudacao"
                className="app-input"
                aria-invalid={errors.title ? 'true' : 'false'}
                aria-describedby={errors.title ? 'quick-reply-title-error' : undefined}
                {...register('title', {
                  required: 'Informe o titulo.',
                  minLength: { value: 2, message: 'Use ao menos 2 caracteres.' },
                  maxLength: { value: 80, message: 'Use no maximo 80 caracteres.' },
                  validate: (value) =>
                    String(value ?? '').trim().length >= 2 || 'Use ao menos 2 caracteres validos.',
                })}
              />
            </label>
            {errors.title ? (
              <p id="quick-reply-title-error" className="text-xs text-red-600" role="alert">
                {errors.title.message}
              </p>
            ) : null}

            <label className="block text-sm">
              Texto da resposta
              <textarea
                rows={4}
                placeholder="Ex: Ola! Como posso ajudar voce hoje?"
                className="app-input"
                aria-invalid={errors.text ? 'true' : 'false'}
                aria-describedby={errors.text ? 'quick-reply-text-error' : undefined}
                {...register('text', {
                  required: 'Informe o texto do template.',
                  minLength: { value: 5, message: 'Use ao menos 5 caracteres.' },
                  maxLength: { value: 5000, message: 'Texto muito longo.' },
                  validate: (value) =>
                    String(value ?? '').trim().length >= 5 || 'Use ao menos 5 caracteres validos.',
                })}
              />
            </label>
            {errors.text ? (
              <p id="quick-reply-text-error" className="text-xs text-red-600" role="alert">
                {errors.text.message}
              </p>
            ) : null}

            <div className="flex gap-2">
              <button
                type="submit"
                disabled={busy || !isValid}
                className="app-btn-primary"
              >
                {busy ? 'Salvando...' : editingId ? 'Salvar edicao' : 'Criar template'}
              </button>
              {editingId ? (
                <button
                  type="button"
                  onClick={cancelEdit}
                  className="app-btn-secondary"
                  disabled={busy}
                >
                  Cancelar
                </button>
              ) : null}
            </div>
          </form>
        </section>

        <section className="app-panel">
          <h2 className="font-medium mb-3">Templates cadastrados</h2>
          {!replies.length ? (
            <EmptyState
              title="Nenhum template cadastrado"
              subtitle="Crie um template para responder mais rapido no inbox."
            />
          ) : null}
          <ul className="space-y-2">
            {replies.map((reply) => (
              <li key={reply.id} className="rounded-lg border border-[#d9e1ec] bg-white p-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium">{reply.title}</p>
                    <p className="text-xs text-[#706f6c] mt-1 line-clamp-2">{reply.text}</p>
                  </div>
                  <div className="flex gap-1 shrink-0">
                    <button
                      type="button"
                      onClick={() => beginEdit(reply)}
                      className="app-btn-secondary text-xs"
                      aria-label={`Editar template ${reply.title}`}
                    >
                      Editar
                    </button>
                    <button
                      type="button"
                      onClick={() => requestDelete(reply)}
                      className="app-btn-danger text-xs"
                      aria-label={`Apagar template ${reply.title}`}
                    >
                      Apagar
                    </button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </section>
      </div>

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        title="Excluir template"
        description={
          deleteTarget
            ? `Tem certeza que deseja excluir "${deleteTarget.title}"?`
            : ''
        }
        confirmLabel="Excluir"
        confirmTone="danger"
        busy={deleteBusy}
        onClose={() => {
          if (!deleteBusy) setDeleteTarget(null);
        }}
        onConfirm={() => void confirmDelete()}
      />
    </Layout>
  );
}

export default CompanyQuickRepliesPage;
