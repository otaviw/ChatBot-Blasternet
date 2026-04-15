import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import PageState from '@/components/ui/PageState/PageState.jsx';
import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
import usePageData from '@/hooks/usePageData';
import useAuth from '@/hooks/useAuth';
import useLogout from '@/hooks/useLogout';
import useAdminCompanySelector from '@/hooks/useAdminCompanySelector';
import api from '@/services/api';
import ConfirmDialog from '@/components/ui/ConfirmDialog/ConfirmDialog.jsx';
import { showError, showSuccess } from '@/services/toastService';

const MAX_CONTENT_ITEMS = 50;

const EMPTY_FORM = {
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

function IndexingBadge({ status }) {
  if (status === 'pending') {
    return (
      <span className="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
        <span className="animate-spin inline-block w-3 h-3 border border-amber-500 border-t-transparent rounded-full" aria-hidden />
        Indexando...
      </span>
    );
  }
  if (status === 'failed') {
    return (
      <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-red-50 text-red-700 border border-red-200" title="Falha na indexação. Edite o documento para tentar novamente.">
        Falha na indexação
      </span>
    );
  }
  return (
    <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
      Indexado
    </span>
  );
}

function CompanyKnowledgeBasePage() {
  const { user } = useAuth();
  const { logout } = useLogout();
  const isAdmin = user?.role === 'system_admin';

  const { companies, selectedCompanyId, setSelectedCompanyId } = useAdminCompanySelector({ isAdmin });

  const baseUrl = isAdmin && selectedCompanyId
    ? `/minha-conta/base-conhecimento?company_id=${selectedCompanyId}`
    : '/minha-conta/base-conhecimento';

  const { data, loading, error, refetch } = usePageData(baseUrl);
  const [items, setItems] = useState([]);
  const [busy, setBusy] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isValid },
  } = useForm({
    mode: 'onChange',
    reValidateMode: 'onChange',
    defaultValues: EMPTY_FORM,
  });

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
    setEditingId(null);
    reset(EMPTY_FORM);
    setModalOpen(true);
  };

  const openEditModal = (item) => {
    setEditingId(item.id);
    reset({
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
    reset(EMPTY_FORM);
  };

  const handleSave = async (values) => {
    if (!canManageKnowledge) return;

    const payload = {
      title: String(values.title ?? '').trim(),
      content: String(values.content ?? '').trim(),
      is_active: Boolean(values.is_active),
      ...(isAdmin && selectedCompanyId ? { company_id: Number(selectedCompanyId) } : {}),
    };

    setBusy(true);
    try {
      if (editingId) {
        const response = await api.put(`/minha-conta/base-conhecimento/${editingId}`, payload);
        const updated = response.data?.knowledge_item;
        if (updated) {
          setItems((prev) => prev.map((item) => (item.id === editingId ? updated : item)));
        }
        showSuccess('Conteúdo atualizado com sucesso.');
      } else {
        if (reachedLimit) {
          showError(`Limite atingido: máximo de ${maxItems} conteúdos.`);
          return;
        }

        const response = await api.post('/minha-conta/base-conhecimento', payload);
        const created = response.data?.knowledge_item;
        if (created) {
          setItems((prev) => [created, ...prev]);
        }
        showSuccess('Conteúdo criado com sucesso.');
      }

      setModalOpen(false);
      setEditingId(null);
      reset(EMPTY_FORM);
    } catch (err) {
      showError(err.response?.data?.message ?? 'Não foi possível salvar o conteúdo.');
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async () => {
    if (!canManageKnowledge || busy || !deleteTarget?.id) return;

    setBusy(true);
    try {
      await api.delete(`/minha-conta/base-conhecimento/${deleteTarget.id}`);
      setItems((prev) => prev.filter((current) => current.id !== deleteTarget.id));
      setDeleteTarget(null);
      showSuccess('Conteúdo excluido com sucesso.');
    } catch (err) {
      showError(err.response?.data?.message ?? 'Não foi possível excluir o conteúdo.');
    } finally {
      setBusy(false);
    }
  };

  const layoutRole = isAdmin ? 'admin' : 'company';

  // Slot de loading customizado: imita o layout real da página (header + linhas)
  // para evitar o salto de layout quando o conteúdo carrega.
  const knowledgeLoadingSlot = (
    <div className="space-y-3">
      <LoadingSkeleton className="h-6 w-56" />
      <LoadingSkeleton className="h-4 w-96 max-w-full" />
      <LoadingSkeleton className="h-14 w-full" />
      <LoadingSkeleton className="h-14 w-full" />
      <LoadingSkeleton className="h-14 w-full" />
    </div>
  );

  return (
    <Layout role={layoutRole} onLogout={logout}>
      <PageState
        loading={loading}
        loadingSlot={knowledgeLoadingSlot}
        error={error || !data?.authenticated}
        errorMessage="Não foi possível carregar a base de conhecimento."
        onRetry={refetch}
      >
      <PageHeader
        title="Base de conhecimento"
        subtitle="Gerencie conteúdos usados pela IA para melhorar a qualidade das respostas."
        action={(
          <div className="flex items-center gap-2 flex-wrap">
            {isAdmin && companies.length > 0 ? (
              <select
                value={selectedCompanyId}
                onChange={(event) => setSelectedCompanyId(event.target.value)}
                className="rounded-lg border border-[#d4d4d4] bg-white px-3 py-2 text-sm text-[#1f2937] outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
              >
                {companies.map((company) => (
                  <option key={company.id} value={String(company.id)}>{company.name}</option>
                ))}
              </select>
            ) : null}
            <Button
              variant="primary"
              onClick={openCreateModal}
              disabled={!canManageKnowledge || reachedLimit || (isAdmin && !selectedCompanyId)}
            >
              Novo
            </Button>
          </div>
        )}
      />

      <div className="mb-4 flex flex-wrap items-center gap-3 text-sm">
        <span className="rounded-full bg-[#f8fafc] px-3 py-1 text-[#475569] border border-[#e2e8f0]">
          {itemCount}/{maxItems} conteúdos
        </span>
        {!canManageKnowledge ? (
          <span className="text-[#64748b]">Somente admin da empresa pode criar, editar e excluir.</span>
        ) : null}
      </div>

      <Card className="p-0 overflow-hidden">
        {!sortedItems.length ? (
          <div className="p-4">
            <EmptyState
              title="Nenhum conteúdo cadastrado"
              subtitle="Cadastre o primeiro conteúdo para orientar as respostas da IA."
              actionLabel={canManageKnowledge ? 'Criar conteúdo' : ''}
              onAction={canManageKnowledge ? openCreateModal : undefined}
            />
          </div>
        ) : (
          <div className="overflow-x-auto app-responsive-table-wrap">
            <table className="min-w-full text-sm app-responsive-table">
              <thead className="bg-[#f8fafc]">
                <tr className="text-left text-[#64748b] border-b border-[#e2e8f0]">
                  <th className="px-4 py-3 font-medium">Titulo</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3 font-medium">Indexação</th>
                  <th className="px-4 py-3 font-medium">Atualizado em</th>
                  <th className="px-4 py-3 font-medium w-[1%] whitespace-nowrap">Ações</th>
                </tr>
              </thead>
              <tbody>
                {sortedItems.map((item) => (
                  <tr key={item.id} className="border-b border-[#f1f5f9]">
                    <td data-label="Titulo" className="px-4 py-3 text-[#0f172a]">
                      <p className="font-medium">{item.title || '-'}</p>
                    </td>
                    <td data-label="Status" className="px-4 py-3">
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
                    <td data-label="Indexação" className="px-4 py-3">
                      <IndexingBadge status={item.indexing_status ?? 'indexed'} />
                    </td>
                    <td data-label="Atualizado em" className="px-4 py-3 text-[#475569]">
                      {formatDate(item.updated_at)}
                    </td>
                    <td data-label="Ações" className="px-4 py-3">
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
                          onClick={() => setDeleteTarget(item)}
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

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        title="Excluir conteúdo"
        description={
          deleteTarget
            ? `Tem certeza que deseja excluir "${deleteTarget.title}"? Esta ação não pode ser desfeita.`
            : ''
        }
        confirmLabel="Excluir"
        confirmTone="danger"
        busy={busy}
        onClose={() => {
          if (!busy) setDeleteTarget(null);
        }}
        onConfirm={() => void handleDelete()}
      />

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
                  Esse conteúdo sera usado pela IA nas respostas da empresa.
                </p>
              </div>
              <button
                type="button"
                className="rounded-md p-1.5 text-[#64748b] hover:bg-[#f1f5f9]"
                onClick={closeModal}
                aria-label="Fechar modal"
              >
                x
              </button>
            </div>

            <form onSubmit={handleSubmit(handleSave)} noValidate className="space-y-4">
              <label className="block text-sm">
                <span className="mb-1 block text-[#334155]">Titulo</span>
                <input
                  type="text"
                  maxLength={190}
                  className="w-full rounded-lg border border-[#d4d4d4] px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
                  placeholder="Ex.: Politica de trocas"
                  aria-invalid={errors.title ? 'true' : 'false'}
                  aria-describedby={errors.title ? 'knowledge-title-error' : undefined}
                  {...register('title', {
                    required: 'Informe o titulo.',
                    minLength: { value: 3, message: 'Use ao menos 3 caracteres.' },
                    maxLength: { value: 190, message: 'Use no máximo 190 caracteres.' },
                    validate: (value) =>
                      String(value ?? '').trim().length >= 3 || 'Use ao menos 3 caracteres validos.',
                  })}
                />
              </label>
              {errors.title ? (
                <p id="knowledge-title-error" className="text-xs text-red-600" role="alert">
                  {errors.title.message}
                </p>
              ) : null}

              <label className="block text-sm">
                <span className="mb-1 block text-[#334155]">Conteúdo</span>
                <textarea
                  rows={12}
                  className="w-full rounded-lg border border-[#d4d4d4] px-3 py-2 text-sm outline-none focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20"
                  placeholder="Escreva aqui as informacoes importantes para a IA..."
                  aria-invalid={errors.content ? 'true' : 'false'}
                  aria-describedby={errors.content ? 'knowledge-content-error' : undefined}
                  {...register('content', {
                    required: 'Informe o conteúdo.',
                    minLength: { value: 20, message: 'Use ao menos 20 caracteres.' },
                    validate: (value) =>
                      String(value ?? '').trim().length >= 20 || 'Use ao menos 20 caracteres validos.',
                  })}
                />
              </label>
              {errors.content ? (
                <p id="knowledge-content-error" className="text-xs text-red-600" role="alert">
                  {errors.content.message}
                </p>
              ) : null}

              <label className="inline-flex items-center gap-2 text-sm text-[#1f2937]">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-[#d4d4d4] text-[#2563eb] focus:ring-[#2563eb]/20"
                  {...register('is_active')}
                />
                Ativo
              </label>

              <div className="flex items-center gap-2 pt-2">
                <Button type="submit" variant="primary" disabled={busy || !canManageKnowledge || !isValid}>
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
      </PageState>
    </Layout>
  );
}

export default CompanyKnowledgeBasePage;
