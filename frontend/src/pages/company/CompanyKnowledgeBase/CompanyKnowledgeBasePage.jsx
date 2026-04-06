import { useEffect, useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import Notice from '@/components/ui/Notice/Notice.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';

const MAX_CONTENT_ITEMS = 50;

const emptyForm = {
  title: '',
  content: '',
  is_active: true,
};

function formatDate(value) {
  if (!value) return '-';
  const time = new Date(value).getTime();
  if (!Number.isFinite(time)) return '-';
  return new Date(time).toLocaleString('pt-BR');
}

function CompanyKnowledgeBasePage() {
  const { data, loading, error } = usePageData('/minha-conta/base-conhecimento');
  const { logout } = useLogout();

  const [items, setItems] = useState([]);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState({ type: '', message: '' });
  const [modalOpen, setModalOpen] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [form, setForm] = useState(emptyForm);

  useEffect(() => {
    setItems(Array.isArray(data?.knowledge_items) ? data.knowledge_items : []);
  }, [data]);

  const canManageKnowledge = Boolean(data?.can_manage);
  const maxItems = Number(data?.max_items) > 0 ? Number(data.max_items) : MAX_CONTENT_ITEMS;
  const itemCount = items.length;
  const reachedLimit = itemCount >= maxItems;

  const sortedItems = useMemo(
    () =>
      [...items].sort((a, b) => {
        const aTime = new Date(a?.updated_at ?? 0).getTime();
        const bTime = new Date(b?.updated_at ?? 0).getTime();
        if (aTime !== bTime) return bTime - aTime;
        return Number(b?.id ?? 0) - Number(a?.id ?? 0);
      }),
    [items]
  );

  const openCreateModal = () => {
    setFeedback({ type: '', message: '' });
    setEditingId(null);
    setForm(emptyForm);
    setModalOpen(true);
  };

  const openEditModal = (item) => {
    setFeedback({ type: '', message: '' });
    setEditingId(item.id);
    setForm({
      title: String(item.title ?? ''),
      content: String(item.content ?? ''),
      is_active: Boolean(item.is_active),
    });
    setModalOpen(true);
  };

  const closeModal = () => {
    if (busy) return;
    setModalOpen(false);
    setEditingId(null);
    setForm(emptyForm);
  };

  const handleSave = async (event) => {
    event.preventDefault();
    if (!canManageKnowledge) return;

    const title = String(form.title ?? '').trim();
    const content = String(form.content ?? '').trim();
    if (!title || !content) {
      setFeedback({ type: 'danger', message: 'Preencha título e conteúdo.' });
      return;
    }

    setBusy(true);
    setFeedback({ type: '', message: '' });
    try {
      const payload = {
        title,
        content,
        is_active: Boolean(form.is_active),
      };

      if (editingId) {
        const response = await api.put(`/minha-conta/base-conhecimento/${editingId}`, payload);
        const updated = response.data?.knowledge_item;
        if (updated) {
          setItems((prev) => prev.map((item) => (item.id === editingId ? updated : item)));
        }
        setFeedback({ type: 'success', message: 'Conteúdo atualizado com sucesso.' });
      } else {
        if (reachedLimit) {
          setFeedback({
            type: 'danger',
            message: `Limite atingido: máximo de ${maxItems} conteúdos.`,
          });
          return;
        }
        const response = await api.post('/minha-conta/base-conhecimento', payload);
        const created = response.data?.knowledge_item;
        if (created) {
          setItems((prev) => [created, ...prev]);
        }
        setFeedback({ type: 'success', message: 'Conteúdo criado com sucesso.' });
      }

      setModalOpen(false);
      setEditingId(null);
      setForm(emptyForm);
    } catch (err) {
      setFeedback({
        type: 'danger',
        message: err.response?.data?.message ?? 'Não foi possível salvar o conteúdo.',
      });
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async (item) => {
    if (!canManageKnowledge || busy) return;
    const confirmed = window.confirm(
      `Excluir "${item.title}"? Esta ação não pode ser desfeita.`
    );
    if (!confirmed) return;

    setBusy(true);
    setFeedback({ type: '', message: '' });
    try {
      await api.delete(`/minha-conta/base-conhecimento/${item.id}`);
      setItems((prev) => prev.filter((current) => current.id !== item.id));
      setFeedback({ type: 'success', message: 'Conteúdo excluído.' });
    } catch (err) {
      setFeedback({
        type: 'danger',
        message: err.response?.data?.message ?? 'Não foi possível excluir o conteúdo.',
      });
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#64748b]">Carregando base de conhecimento...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-red-600">Não foi possível carregar a base de conhecimento.</p>
      </Layout>
    );
  }

  return (
    <Layout role="company" onLogout={logout}>
      <PageHeader
        title="Base de conhecimento"
        subtitle="Gerencie conteúdos usados pela IA para melhorar a qualidade das respostas."
        action={
          <Button
            variant="primary"
            onClick={openCreateModal}
            disabled={!canManageKnowledge || reachedLimit}
          >
            Novo
          </Button>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-3 text-sm">
        <span className="rounded-full bg-[#f8fafc] px-3 py-1 text-[#475569] border border-[#e2e8f0]">
          {itemCount}/{maxItems} conteúdos
        </span>
        {!canManageKnowledge ? (
          <span className="text-[#64748b]">Somente admin da empresa pode criar, editar e excluir.</span>
        ) : null}
      </div>

      {feedback.message ? (
        <div className="mb-4">
          <Notice tone={feedback.type === 'danger' ? 'danger' : 'success'}>{feedback.message}</Notice>
        </div>
      ) : null}

      <Card className="p-0 overflow-hidden">
        {!sortedItems.length ? (
          <div className="p-6 text-sm text-[#64748b]">Nenhum conteúdo cadastrado ainda.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="bg-[#f8fafc]">
                <tr className="text-left text-[#64748b] border-b border-[#e2e8f0]">
                  <th className="px-4 py-3 font-medium">Título</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3 font-medium">Atualizado em</th>
                  <th className="px-4 py-3 font-medium w-[1%] whitespace-nowrap">Ações</th>
                </tr>
              </thead>
              <tbody>
                {sortedItems.map((item) => (
                  <tr key={item.id} className="border-b border-[#f1f5f9]">
                    <td className="px-4 py-3 text-[#0f172a]">
                      <p className="font-medium">{item.title || '-'}</p>
                    </td>
                    <td className="px-4 py-3">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                          item.is_active
                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                            : 'bg-gray-100 text-gray-600 border border-gray-200'
                        }`}
                      >
                        {item.is_active ? 'Ativo' : 'Inativo'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-[#475569]">{formatDate(item.updated_at)}</td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <Button
                          variant="secondary"
                          className="px-3 py-1.5 text-xs"
                          onClick={() => openEditModal(item)}
                          disabled={!canManageKnowledge || busy}
                        >
                          Editar
                        </Button>
                        <Button
                          variant="danger"
                          className="px-3 py-1.5 text-xs"
                          onClick={() => void handleDelete(item)}
                          disabled={!canManageKnowledge || busy}
                        >
                          Excluir
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {modalOpen ? (
        <div
          className="fixed inset-0 z-[80] bg-black/40 p-4 flex items-center justify-center"
          role="dialog"
          aria-modal="true"
          onClick={closeModal}
        >
          <div
            className="w-full max-w-3xl rounded-xl border border-[#e5e7eb] bg-white p-5 shadow-lg"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="mb-4 flex items-start justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold text-[#0f172a]">
                  {editingId ? 'Editar conteúdo' : 'Novo conteúdo'}
                </h2>
                <p className="text-sm text-[#64748b]">
                  Esse conteúdo será usado pela IA nas respostas da empresa.
                </p>
              </div>
              <button
                type="button"
                className="rounded-md p-1.5 text-[#64748b] hover:bg-[#f1f5f9]"
                onClick={closeModal}
                aria-label="Fechar"
              >
                ×
              </button>
            </div>

            <form onSubmit={handleSave} className="space-y-4">
              <label className="block text-sm">
                <span className="mb-1 block text-[#334155]">Título</span>
                <input
                  type="text"
                  value={form.title}
                  onChange={(event) => setForm((prev) => ({ ...prev, title: event.target.value }))}
                  maxLength={190}
                  required
                  className="w-full rounded-lg border border-[#d4d4d4] px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
                  placeholder="Ex.: Política de trocas"
                />
              </label>

              <label className="block text-sm">
                <span className="mb-1 block text-[#334155]">Conteúdo</span>
                <textarea
                  value={form.content}
                  onChange={(event) => setForm((prev) => ({ ...prev, content: event.target.value }))}
                  rows={12}
                  required
                  className="w-full rounded-lg border border-[#d4d4d4] px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
                  placeholder="Escreva aqui as informações importantes para a IA..."
                />
              </label>

              <label className="inline-flex items-center gap-2 text-sm text-[#1f2937]">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-[#d4d4d4] text-[#2563eb] focus:ring-[#2563eb]/20"
                  checked={Boolean(form.is_active)}
                  onChange={(event) =>
                    setForm((prev) => ({ ...prev, is_active: event.target.checked }))
                  }
                />
                Ativo
              </label>

              <div className="flex items-center gap-2 pt-2">
                <Button type="submit" variant="primary" disabled={busy || !canManageKnowledge}>
                  {busy ? 'Salvando...' : 'Salvar'}
                </Button>
                <Button type="button" variant="secondary" onClick={closeModal} disabled={busy}>
                  Cancelar
                </Button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </Layout>
  );
}

export default CompanyKnowledgeBasePage;
