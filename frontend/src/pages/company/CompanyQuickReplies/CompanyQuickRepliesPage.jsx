import './CompanyQuickRepliesPage.css';
import { useState, useEffect } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';

function CompanyQuickRepliesPage() {
  const { data, loading, error } = usePageData('/minha-conta/respostas-rapidas');
  const { logout } = useLogout();
  const [replies, setReplies] = useState([]);
  const [form, setForm] = useState({ title: '', text: '' });
  const [editingId, setEditingId] = useState(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState('');

  useEffect(() => {
    setReplies(data?.quick_replies ?? []);
  }, [data]);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setFormError('');
    try {
      if (editingId) {
        const response = await api.put(`/minha-conta/respostas-rapidas/${editingId}`, form);
        setReplies((prev) =>
          prev.map((r) => (r.id === editingId ? response.data.quick_reply : r))
        );
        setEditingId(null);
      } else {
        const response = await api.post('/minha-conta/respostas-rapidas', form);
        setReplies((prev) => [...prev, response.data.quick_reply]);
      }
      setForm({ title: '', text: '' });
    } catch (err) {
      setFormError(err.response?.data?.message || 'Falha ao salvar.');
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/minha-conta/respostas-rapidas/${id}`);
      setReplies((prev) => prev.filter((r) => r.id !== id));
    } catch (err) {
      setFormError('Falha ao apagar.');
    }
  };

  const beginEdit = (reply) => {
    setEditingId(reply.id);
    setForm({ title: reply.title, text: reply.text });
  };

  const cancelEdit = () => {
    setEditingId(null);
    setForm({ title: '', text: '' });
    setFormError('');
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando...</p>
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
      <h1 className="app-page-title">Respostas Rápidas</h1>
      <p className="app-page-subtitle mb-6">
        Templates para agilizar o atendimento manual no inbox.
      </p>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {/* Formulário */}
        <section className="app-panel">
          <h2 className="font-medium mb-3">
            {editingId ? 'Editar template' : 'Novo template'}
          </h2>
          <form onSubmit={handleSubmit} className="space-y-3">
            <label className="block text-sm">
              Título (atalho)
              <input
                type="text"
                value={form.title}
                onChange={(e) => setForm((p) => ({ ...p, title: e.target.value }))}
                required
                placeholder="Ex: Saudação"
                className="app-input"
              />
            </label>
            <label className="block text-sm">
              Texto da resposta
              <textarea
                value={form.text}
                onChange={(e) => setForm((p) => ({ ...p, text: e.target.value }))}
                required
                rows={4}
                placeholder="Ex: Olá! Como posso ajudar você hoje?"
                className="app-input"
              />
            </label>
            {formError && <p className="text-sm text-red-600">{formError}</p>}
            <div className="flex gap-2">
              <button
                type="submit"
                disabled={busy}
                className="app-btn-primary"
              >
                {busy ? 'Salvando...' : editingId ? 'Salvar edição' : 'Criar template'}
              </button>
              {editingId && (
                <button
                  type="button"
                  onClick={cancelEdit}
                  className="app-btn-secondary"
                >
                  Cancelar
                </button>
              )}
            </div>
          </form>
        </section>

        {/* Lista */}
        <section className="app-panel">
          <h2 className="font-medium mb-3">Templates cadastrados</h2>
          {!replies.length && (
            <p className="text-sm text-[#706f6c]">Nenhum template cadastrado ainda.</p>
          )}
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
                    >
                      Editar
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDelete(reply.id)}
                      className="app-btn-danger text-xs"
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
    </Layout>
  );
}

export default CompanyQuickRepliesPage;



