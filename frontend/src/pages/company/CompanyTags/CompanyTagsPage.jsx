import { useCallback, useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';

const PRESET_COLORS = [
  { label: 'Vermelho',  value: '#ef4444' },
  { label: 'Laranja',   value: '#f97316' },
  { label: 'Amarelo',   value: '#eab308' },
  { label: 'Verde',     value: '#22c55e' },
  { label: 'Azul',      value: '#3b82f6' },
  { label: 'Roxo',      value: '#a855f7' },
  { label: 'Cinza',     value: '#6b7280' },
];

function TagBadge({ name, color }) {
  return (
    <span
      className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium text-white"
      style={{ backgroundColor: color }}
    >
      {name}
    </span>
  );
}

function ColorPicker({ value, onChange }) {
  return (
    <div className="flex flex-wrap gap-2">
      {PRESET_COLORS.map((c) => (
        <button
          key={c.value}
          type="button"
          title={c.label}
          onClick={() => onChange(c.value)}
          className="w-6 h-6 rounded-full border-2 transition-transform hover:scale-110"
          style={{
            backgroundColor: c.value,
            borderColor: value === c.value ? '#171717' : 'transparent',
          }}
          aria-label={c.label}
          aria-pressed={value === c.value}
        />
      ))}
    </div>
  );
}

function normalizeTag(rawTag) {
  const id = Number(rawTag?.id ?? 0);
  const name = String(rawTag?.name ?? '').trim();
  const color = String(rawTag?.color ?? '').trim();
  if (!id || !name) return null;
  return {
    id,
    name,
    color: /^#[0-9a-fA-F]{6}$/.test(color) ? color : '#3b82f6',
  };
}

function extractTags(payload) {
  const candidates = [
    payload?.tags,
    payload?.data?.tags,
    payload?.tags?.data,
    payload?.data,
  ];

  const firstArray = candidates.find((value) => Array.isArray(value));
  if (!firstArray) return [];

  return firstArray
    .map(normalizeTag)
    .filter(Boolean);
}

export default function CompanyTagsPage() {
  const { data, loading, error, setData } = usePageData('/minha-conta/tags');
  const { logout } = useLogout();

  const [newName, setNewName] = useState('');
  const [newColor, setNewColor] = useState('#3b82f6');
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');

  const [editingId, setEditingId] = useState(null);
  const [editName, setEditName] = useState('');
  const [editColor, setEditColor] = useState('');
  const [editBusy, setEditBusy] = useState(false);
  const [editError, setEditError] = useState('');

  const [deletingId, setDeletingId] = useState(null);

  const tags = useMemo(() => extractTags(data), [data]);

  const syncTags = useCallback(async () => {
    const response = await api.get('/minha-conta/tags');
    const payload = response?.data ?? {};
    setData(payload);
    return extractTags(payload);
  }, [setData]);

  async function handleCreate(e) {
    e.preventDefault();
    if (createBusy || !newName.trim()) return;
    setCreateBusy(true);
    setCreateError('');

    const normalizedNewName = newName.trim().toLowerCase();

    try {
      const res = await api.post('/minha-conta/tags', { name: newName.trim(), color: newColor });

      const createdTag = normalizeTag(res?.data?.tag ?? res?.data?.data?.tag ?? res?.data?.data);
      if (createdTag) {
        setData((prev) => {
          const currentTags = extractTags(prev);
          const exists = currentTags.some((tag) => tag.id === createdTag.id);
          const nextTags = exists
            ? currentTags.map((tag) => (tag.id === createdTag.id ? createdTag : tag))
            : [...currentTags, createdTag];
          return { ...(prev ?? {}), tags: nextTags };
        });
      } else {
        await syncTags();
      }

      setNewName('');
      setNewColor('#3b82f6');
    } catch (err) {
      if (Number(err?.status ?? err?.response?.status ?? 0) === 422) {
        try {
          const refreshedTags = await syncTags();
          const foundByName = refreshedTags.some((tag) => tag.name.toLowerCase() === normalizedNewName);
          if (foundByName) {
            setNewName('');
            setNewColor('#3b82f6');
            setCreateError('');
            return;
          }
        } catch {
        }
      }

      setCreateError(
        err?.response?.data?.errors?.name?.[0]
          ?? err?.response?.data?.message
          ?? err?.message
          ?? 'Erro ao criar tag.'
      );
    } finally {
      setCreateBusy(false);
    }
  }

  function startEdit(tag) {
    setEditingId(tag.id);
    setEditName(tag.name);
    setEditColor(tag.color);
    setEditError('');
  }

  function cancelEdit() {
    setEditingId(null);
    setEditError('');
  }

  async function handleUpdate(tagId) {
    if (editBusy || !editName.trim()) return;
    setEditBusy(true);
    setEditError('');

    const targetId = Number(tagId);
    const desiredName = editName.trim().toLowerCase();
    const desiredColor = editColor.toLowerCase();

    try {
      const res = await api.put(`/minha-conta/tags/${tagId}`, { name: editName.trim(), color: editColor });

      const updatedTag = normalizeTag(res?.data?.tag ?? res?.data?.data?.tag ?? res?.data?.data);
      if (updatedTag) {
        setData((prev) => ({
          ...(prev ?? {}),
          tags: extractTags(prev).map((t) => (Number(t.id) === targetId ? updatedTag : t)),
        }));
      } else {
        await syncTags();
      }

      setEditingId(null);
    } catch (err) {
      if (Number(err?.status ?? err?.response?.status ?? 0) === 422) {
        try {
          const refreshedTags = await syncTags();
          const persisted = refreshedTags.find((tag) => Number(tag.id) === targetId);
          if (
            persisted
            && persisted.name.toLowerCase() === desiredName
            && persisted.color.toLowerCase() === desiredColor
          ) {
            setEditingId(null);
            setEditError('');
            return;
          }
        } catch {
        }
      }

      setEditError(
        err?.response?.data?.errors?.name?.[0]
          ?? err?.response?.data?.message
          ?? err?.message
          ?? 'Erro ao salvar.'
      );
    } finally {
      setEditBusy(false);
    }
  }

  async function handleDelete(tagId) {
    if (deletingId) return;
    if (!window.confirm('Remover esta tag de todas as conversas?')) return;

    const targetId = Number(tagId);
    setDeletingId(tagId);

    try {
      await api.delete(`/minha-conta/tags/${tagId}`);
      setData((prev) => ({
        ...(prev ?? {}),
        tags: extractTags(prev).filter((t) => Number(t.id) !== targetId),
      }));
    } catch (err) {
      const status = Number(err?.status ?? err?.response?.status ?? 0);
      if (status === 404) {
        setData((prev) => ({
          ...(prev ?? {}),
          tags: extractTags(prev).filter((t) => Number(t.id) !== targetId),
        }));
      } else {
        try {
          await syncTags();
        } catch {
        }
      }
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <Layout role="company" companyName={data?.company?.name} onLogout={logout}>
      <div className="max-w-xl mx-auto px-4 py-8">
        <h1 className="text-xl font-semibold text-[#171717] mb-6">Tags</h1>

        {/* Criar nova tag */}
        <form onSubmit={handleCreate} className="bg-white border border-[#e5e5e5] rounded-xl p-5 mb-6">
          <h2 className="text-sm font-semibold text-[#171717] mb-3">Nova tag</h2>
          <div className="flex gap-3 items-end flex-wrap">
            <div className="flex-1 min-w-[160px]">
              <label className="text-xs text-[#525252] block mb-1">Nome</label>
              <input
                type="text"
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="ex: urgente"
                maxLength={50}
                className="app-input w-full text-sm"
              />
            </div>
            <div>
              <label className="text-xs text-[#525252] block mb-1">Cor</label>
              <ColorPicker value={newColor} onChange={setNewColor} />
            </div>
          </div>
          {newName && (
            <div className="mt-3">
              <span className="text-xs text-[#525252] mr-2">Prévia:</span>
              <TagBadge name={newName.toLowerCase().trim()} color={newColor} />
            </div>
          )}
          {createError && <p className="text-xs text-red-600 mt-2">{createError}</p>}
          <button
            type="submit"
            disabled={createBusy || !newName.trim()}
            className="app-btn-primary mt-4 text-sm"
          >
            {createBusy ? 'Criando...' : 'Criar tag'}
          </button>
        </form>

        {/* Lista de tags */}
        {loading && <p className="text-sm text-[#737373]">Carregando...</p>}
        {error && <p className="text-sm text-red-600">Erro ao carregar tags.</p>}
        {!loading && tags.length === 0 && (
          <p className="text-sm text-[#737373]">Nenhuma tag criada ainda.</p>
        )}
        <ul className="space-y-2">
          {tags.map((tag) => (
            <li key={tag.id} className="bg-white border border-[#e5e5e5] rounded-xl p-4">
              {editingId === tag.id ? (
                <div className="space-y-3">
                  <div className="flex gap-3 items-end flex-wrap">
                    <div className="flex-1 min-w-[160px]">
                      <input
                        type="text"
                        value={editName}
                        onChange={(e) => setEditName(e.target.value)}
                        maxLength={50}
                        className="app-input w-full text-sm"
                        autoFocus
                      />
                    </div>
                    <ColorPicker value={editColor} onChange={setEditColor} />
                  </div>
                  {editName && <TagBadge name={editName.toLowerCase().trim()} color={editColor} />}
                  {editError && <p className="text-xs text-red-600">{editError}</p>}
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={() => handleUpdate(tag.id)}
                      disabled={editBusy || !editName.trim()}
                      className="app-btn-primary text-xs"
                    >
                      {editBusy ? 'Salvando...' : 'Salvar'}
                    </button>
                    <button type="button" onClick={cancelEdit} className="app-btn-secondary text-xs">
                      Cancelar
                    </button>
                  </div>
                </div>
              ) : (
                <div className="flex items-center justify-between gap-3">
                  <TagBadge name={tag.name} color={tag.color} />
                  <div className="flex gap-2 shrink-0">
                    <button
                      type="button"
                      onClick={() => startEdit(tag)}
                      className="app-btn-secondary text-xs py-1"
                    >
                      Editar
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDelete(tag.id)}
                      disabled={deletingId === tag.id}
                      className="app-btn-secondary text-xs py-1 !text-red-700 hover:!bg-red-50"
                    >
                      {deletingId === tag.id ? '...' : 'Remover'}
                    </button>
                  </div>
                </div>
              )}
            </li>
          ))}
        </ul>
      </div>
    </Layout>
  );
}
