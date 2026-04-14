import { useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
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

export default function CompanyTagsPage() {
  const { data, loading, error, setData } = usePageData('/minha-conta/tags');

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

  const tags = data?.tags ?? [];

  async function handleCreate(e) {
    e.preventDefault();
    if (!newName.trim()) return;
    setCreateBusy(true);
    setCreateError('');
    try {
      const res = await api.post('/minha-conta/tags', { name: newName.trim(), color: newColor });
      setData((prev) => ({ ...prev, tags: [...(prev?.tags ?? []), res.data.tag] }));
      setNewName('');
      setNewColor('#3b82f6');
    } catch (err) {
      setCreateError(err?.response?.data?.errors?.name?.[0] ?? 'Erro ao criar tag.');
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
    if (!editName.trim()) return;
    setEditBusy(true);
    setEditError('');
    try {
      const res = await api.put(`/minha-conta/tags/${tagId}`, { name: editName.trim(), color: editColor });
      setData((prev) => ({
        ...prev,
        tags: (prev?.tags ?? []).map((t) => (t.id === tagId ? res.data.tag : t)),
      }));
      setEditingId(null);
    } catch (err) {
      setEditError(err?.response?.data?.errors?.name?.[0] ?? 'Erro ao salvar.');
    } finally {
      setEditBusy(false);
    }
  }

  async function handleDelete(tagId) {
    if (!window.confirm('Remover esta tag de todas as conversas?')) return;
    setDeletingId(tagId);
    try {
      await api.delete(`/minha-conta/tags/${tagId}`);
      setData((prev) => ({ ...prev, tags: (prev?.tags ?? []).filter((t) => t.id !== tagId) }));
    } catch {
      // ignore
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <Layout>
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
