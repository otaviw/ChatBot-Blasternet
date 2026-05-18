import { useEffect, useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import useLogout from '@/hooks/useLogout';
import usePageData from '@/hooks/usePageData';
import {
  createManual,
  deleteManual,
  listManuals,
  updateManual,
} from '@/services/manualsService';
import './ManualsPage.css';

const EMPTY_FORM = {
  title: '',
  category: 'screen',
  target_key: '',
  summary: '',
  content: '',
  image_urls: '',
  required_roles: '',
  required_permissions: '',
  is_published: true,
};

function normalizeFormToPayload(form) {
  return {
    title: form.title.trim(),
    category: form.category,
    target_key: form.target_key.trim() || null,
    summary: form.summary.trim() || null,
    content: form.content.trim(),
    image_urls: form.image_urls.split('\n').map((item) => item.trim()).filter(Boolean),
    required_roles: form.required_roles.split(',').map((item) => item.trim()).filter(Boolean),
    required_permissions: form.required_permissions.split(',').map((item) => item.trim()).filter(Boolean),
    is_published: Boolean(form.is_published),
  };
}

function mapManualToForm(manual) {
  return {
    title: manual?.title ?? '',
    category: manual?.category ?? 'screen',
    target_key: manual?.target_key ?? '',
    summary: manual?.summary ?? '',
    content: manual?.content ?? '',
    image_urls: (manual?.image_urls ?? []).join('\n'),
    required_roles: (manual?.required_roles ?? []).join(', '),
    required_permissions: (manual?.required_permissions ?? []).join(', '),
    is_published: Boolean(manual?.is_published ?? true),
  };
}

export default function ManualsPage() {
  const { data: meData, loading: meLoading, error: meError } = usePageData('/me');
  const { logout } = useLogout();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [manuals, setManuals] = useState([]);
  const [canManage, setCanManage] = useState(false);
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('all');
  const [selectedId, setSelectedId] = useState(null);
  const [editorOpen, setEditorOpen] = useState(false);
  const [editingManualId, setEditingManualId] = useState(null);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const payload = await listManuals();
      const list = Array.isArray(payload?.manuals) ? payload.manuals : [];
      setManuals(list);
      setCanManage(Boolean(payload?.can_manage));
      setSelectedId((prev) => prev ?? list[0]?.id ?? null);
    } catch (err) {
      setError(err?.message || 'Nao foi possivel carregar os manuais.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (meLoading || !meData?.authenticated) return;
    load();
  }, [meLoading, meData?.authenticated]);

  const filtered = useMemo(() => {
    const normalizedSearch = search.trim().toLowerCase();

    return manuals.filter((manual) => {
      if (category !== 'all' && manual.category !== category) return false;
      if (!normalizedSearch) return true;

      const haystack = [
        manual.title,
        manual.summary,
        manual.content,
      ].join(' ').toLowerCase();

      return haystack.includes(normalizedSearch);
    });
  }, [manuals, search, category]);

  const groupedManuals = useMemo(() => {
    const flows = filtered.filter((manual) => manual.category === 'flow');
    const screens = filtered.filter((manual) => manual.category === 'screen');
    return { flows, screens };
  }, [filtered]);

  const selectedManual = useMemo(
    () => filtered.find((manual) => manual.id === selectedId) ?? filtered[0] ?? null,
    [filtered, selectedId]
  );

  const openCreate = () => {
    setEditingManualId(null);
    setForm(EMPTY_FORM);
    setEditorOpen(true);
  };

  const openEdit = () => {
    if (!selectedManual) return;
    setEditingManualId(selectedManual.id);
    setForm(mapManualToForm(selectedManual));
    setEditorOpen(true);
  };

  const onSave = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      const payload = normalizeFormToPayload(form);
      if (editingManualId) {
        await updateManual(editingManualId, payload);
      } else {
        await createManual(payload);
      }
      setEditorOpen(false);
      await load();
    } catch (err) {
      setError(err?.message || 'Nao foi possivel salvar o manual.');
    } finally {
      setSaving(false);
    }
  };

  const onDelete = async () => {
    if (!selectedManual) return;
    const confirmed = window.confirm('Deseja excluir este manual?');
    if (!confirmed) return;
    setError('');
    try {
      await deleteManual(selectedManual.id);
      await load();
    } catch (err) {
      setError(err?.message || 'Nao foi possivel excluir o manual.');
    }
  };

  if (meLoading) {
    return (
      <Layout role="company" onLogout={logout}>
        <PageLoading rows={2} cards={2} />
      </Layout>
    );
  }

  if (meError || !meData?.authenticated) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-red-600">Nao foi possivel carregar os manuais.</p>
      </Layout>
    );
  }

  const role = meData?.user?.role === 'system_admin' || meData?.user?.role === 'reseller_admin'
    ? 'admin'
    : 'company';

  return (
    <Layout
      role={role}
      companyName={role === 'company' ? (meData?.user?.company_name ?? 'Empresa') : undefined}
      onLogout={logout}
    >
      <section className="manuals-page">
      <header className="manuals-page__header">
        <div>
          <h1 className="app-page-title">Manuais</h1>
          <p className="app-page-subtitle">Guias simples por tela e por fluxo, conforme seu perfil de acesso.</p>
        </div>
        {canManage && (
          <button type="button" className="app-btn-primary" onClick={openCreate}>
            Novo manual
          </button>
        )}
      </header>

      <div className="manuals-page__filters">
        <input
          type="search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Buscar manual"
          className="app-input"
        />
        <select value={category} onChange={(event) => setCategory(event.target.value)} className="app-input">
          <option value="all">Todas as categorias</option>
          <option value="screen">Telas</option>
          <option value="flow">Fluxos</option>
        </select>
      </div>

      {error && <p className="manuals-page__error">{error}</p>}

      {loading ? (
        <p>Carregando manuais...</p>
      ) : (
        <div className="manuals-page__content">
          <aside className="manuals-page__list app-panel">
            {filtered.length === 0 && <p>Nenhum manual disponivel para este perfil.</p>}
            {groupedManuals.flows.length > 0 && (
              <div className="manuals-page__group">
                <p className="manuals-page__group-title">Fluxos</p>
                {groupedManuals.flows.map((manual) => (
                  <button
                    key={manual.id}
                    type="button"
                    className={`manuals-page__item ${selectedManual?.id === manual.id ? 'active' : ''}`}
                    onClick={() => setSelectedId(manual.id)}
                  >
                    <strong>{manual.title}</strong>
                    <span>Fluxo</span>
                  </button>
                ))}
              </div>
            )}
            {groupedManuals.screens.length > 0 && (
              <div className="manuals-page__group">
                <p className="manuals-page__group-title">Telas</p>
                {groupedManuals.screens.map((manual) => (
                  <button
                    key={manual.id}
                    type="button"
                    className={`manuals-page__item ${selectedManual?.id === manual.id ? 'active' : ''}`}
                    onClick={() => setSelectedId(manual.id)}
                  >
                    <strong>{manual.title}</strong>
                    <span>Tela</span>
                  </button>
                ))}
              </div>
            )}
          </aside>

          <article className="manuals-page__detail app-panel">
            {!selectedManual && <p>Selecione um manual para começar.</p>}
            {selectedManual && (
              <>
                <h2>{selectedManual.title}</h2>
                {selectedManual.summary && <p className="manuals-page__summary">{selectedManual.summary}</p>}
                <div className="manuals-page__text">
                  {selectedManual.content.split('\n').map((line, index) => (
                    <p key={`${selectedManual.id}-${index}`}>{line}</p>
                  ))}
                </div>
                {(selectedManual.image_urls ?? []).length > 0 && (
                  <div className="manuals-page__images">
                    {selectedManual.image_urls.map((url) => (
                      <img key={url} src={url} alt={`Imagem do manual ${selectedManual.title}`} />
                    ))}
                  </div>
                )}
                {canManage && (
                  <div className="manuals-page__actions">
                    <button type="button" className="app-btn-secondary" onClick={openEdit}>Editar</button>
                    <button type="button" className="app-btn-danger" onClick={onDelete}>Excluir</button>
                  </div>
                )}
              </>
            )}
          </article>
        </div>
      )}

      {canManage && editorOpen && (
        <div className="manuals-page__modal" role="dialog" aria-modal="true" aria-label="Editor de manual">
          <form className="manuals-page__form app-panel" onSubmit={onSave}>
            <h3>{editingManualId ? 'Editar manual' : 'Novo manual'}</h3>
            <label>Titulo<input className="app-input" value={form.title} onChange={(e) => setForm((prev) => ({ ...prev, title: e.target.value }))} required /></label>
            <label>Categoria
              <select className="app-input" value={form.category} onChange={(e) => setForm((prev) => ({ ...prev, category: e.target.value }))}>
                <option value="screen">Tela</option>
                <option value="flow">Fluxo</option>
              </select>
            </label>
            <label>Chave da tela ou fluxo<input className="app-input" value={form.target_key} onChange={(e) => setForm((prev) => ({ ...prev, target_key: e.target.value }))} /></label>
            <label>Resumo<textarea className="app-input" value={form.summary} onChange={(e) => setForm((prev) => ({ ...prev, summary: e.target.value }))} rows={2} /></label>
            <label>Texto do manual<textarea className="app-input" value={form.content} onChange={(e) => setForm((prev) => ({ ...prev, content: e.target.value }))} rows={8} required /></label>
            <label>Imagens (uma URL por linha)<textarea className="app-input" value={form.image_urls} onChange={(e) => setForm((prev) => ({ ...prev, image_urls: e.target.value }))} rows={3} /></label>
            <label>Perfis permitidos (separados por virgula)<input className="app-input" value={form.required_roles} onChange={(e) => setForm((prev) => ({ ...prev, required_roles: e.target.value }))} placeholder="agent, company_admin" /></label>
            <label>Permissoes necessarias (separadas por virgula)<input className="app-input" value={form.required_permissions} onChange={(e) => setForm((prev) => ({ ...prev, required_permissions: e.target.value }))} placeholder="page_inbox, page_contacts" /></label>
            <label className="manuals-page__checkbox">
              <input type="checkbox" checked={form.is_published} onChange={(e) => setForm((prev) => ({ ...prev, is_published: e.target.checked }))} />
              Publicado
            </label>
            <div className="manuals-page__actions">
              <button type="button" className="app-btn-secondary" onClick={() => setEditorOpen(false)}>Cancelar</button>
              <button type="submit" className="app-btn-primary" disabled={saving}>{saving ? 'Salvando...' : 'Salvar'}</button>
            </div>
          </form>
        </div>
      )}
      </section>
    </Layout>
  );
}
