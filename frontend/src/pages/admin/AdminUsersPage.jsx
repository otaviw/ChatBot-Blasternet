import React, { useState } from 'react';
import Layout from '../../components/Layout';
import usePageData from '../../hooks/usePageData';
import useLogout from '../../hooks/useLogout';
import api from '../../lib/api';

function AdminUsersPage() {
  const { data, loading, error } = usePageData('/admin/users');
  const { data: companiesData } = usePageData('/admin/empresas');
  const { logout } = useLogout();
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');
  const [editBusy, setEditBusy] = useState(false);
  const [editError, setEditError] = useState('');
  const [selectedUserId, setSelectedUserId] = useState(null);
  const [createForm, setCreateForm] = useState({
    name: '',
    email: '',
    password: '',
    role: 'company',
    company_id: '',
    is_active: true,
  });
  const [editForm, setEditForm] = useState(null);

  const users = data?.users ?? [];
  const companies = companiesData?.companies ?? [];

  const handleCreate = async (event) => {
    event.preventDefault();
    setCreateBusy(true);
    setCreateError('');
    try {
      const payload = {
        ...createForm,
        company_id: createForm.role === 'company' ? Number(createForm.company_id) : null,
      };
      await api.post('/admin/users', payload);
      window.location.reload();
    } catch (err) {
      setCreateError(err.response?.data?.message || 'Falha ao criar usuario.');
    } finally {
      setCreateBusy(false);
    }
  };

  const beginEdit = (user) => {
    setSelectedUserId(user.id);
    setEditForm({
      id: user.id,
      name: user.name,
      email: user.email,
      password: '',
      role: user.role,
      company_id: user.company_id ? String(user.company_id) : '',
      is_active: Boolean(user.is_active),
    });
  };

  const handleEdit = async (event) => {
    event.preventDefault();
    if (!editForm?.id) return;

    setEditBusy(true);
    setEditError('');
    try {
      const payload = {
        ...editForm,
        company_id: editForm.role === 'company' ? Number(editForm.company_id) : null,
      };
      if (!payload.password) {
        delete payload.password;
      }
      await api.put(`/admin/users/${editForm.id}`, payload);
      window.location.reload();
    } catch (err) {
      setEditError(err.response?.data?.message || 'Falha ao atualizar usuario.');
    } finally {
      setEditBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando usuarios...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar usuarios.</p>
      </Layout>
    );
  }

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="text-xl font-medium mb-4">Usuarios</h1>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Criar usuario</h2>
          <form onSubmit={handleCreate} className="space-y-3">
            <input
              type="text"
              placeholder="Nome"
              value={createForm.name}
              onChange={(e) => setCreateForm((p) => ({ ...p, name: e.target.value }))}
              required
              className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
            <input
              type="email"
              placeholder="Email"
              value={createForm.email}
              onChange={(e) => setCreateForm((p) => ({ ...p, email: e.target.value }))}
              required
              className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
            <input
              type="password"
              placeholder="Senha (min 8)"
              value={createForm.password}
              onChange={(e) => setCreateForm((p) => ({ ...p, password: e.target.value }))}
              required
              className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
            <select
              value={createForm.role}
              onChange={(e) => setCreateForm((p) => ({ ...p, role: e.target.value, company_id: '' }))}
              className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            >
              <option value="company">company</option>
              <option value="admin">admin</option>
            </select>
            {createForm.role === 'company' && (
              <select
                value={createForm.company_id}
                onChange={(e) => setCreateForm((p) => ({ ...p, company_id: e.target.value }))}
                required
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              >
                <option value="">Selecione empresa</option>
                {companies.map((company) => (
                  <option key={company.id} value={company.id}>{company.name}</option>
                ))}
              </select>
            )}
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={createForm.is_active}
                onChange={(e) => setCreateForm((p) => ({ ...p, is_active: e.target.checked }))}
              />
              Usuario ativo
            </label>
            <button
              type="submit"
              disabled={createBusy}
              className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
            >
              {createBusy ? 'Criando...' : 'Criar usuario'}
            </button>
            {createError && <p className="text-sm text-red-600">{createError}</p>}
          </form>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Usuarios cadastrados</h2>
          {!users.length && <p className="text-sm text-[#706f6c]">Nenhum usuario.</p>}
          <ul className="space-y-2 text-sm mb-4 max-h-64 overflow-y-auto pr-1">
            {users.map((user) => (
              <li key={user.id} className="border border-[#e3e3e0] rounded p-2">
                <div className="flex items-center justify-between gap-2">
                  <div>
                    <strong>{user.name}</strong> ({user.role}){user.is_active ? '' : ' [inativo]'}
                    <div className="text-xs text-[#706f6c]">{user.email} {user.company?.name ? `- ${user.company.name}` : ''}</div>
                  </div>
                  <button
                    type="button"
                    onClick={() => beginEdit(user)}
                    className="px-2 py-1 rounded border border-[#d5d5d2]"
                  >
                    Editar
                  </button>
                </div>
              </li>
            ))}
          </ul>

          {editForm && (
            <form onSubmit={handleEdit} className="space-y-2 border-t pt-3">
              <h3 className="font-medium text-sm">Editar usuario #{selectedUserId}</h3>
              <input
                type="text"
                value={editForm.name}
                onChange={(e) => setEditForm((p) => ({ ...p, name: e.target.value }))}
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
              <input
                type="email"
                value={editForm.email}
                onChange={(e) => setEditForm((p) => ({ ...p, email: e.target.value }))}
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
              <input
                type="password"
                placeholder="Nova senha (opcional)"
                value={editForm.password}
                onChange={(e) => setEditForm((p) => ({ ...p, password: e.target.value }))}
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
              <select
                value={editForm.role}
                onChange={(e) => setEditForm((p) => ({ ...p, role: e.target.value, company_id: '' }))}
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              >
                <option value="company">company</option>
                <option value="admin">admin</option>
              </select>
              {editForm.role === 'company' && (
                <select
                  value={editForm.company_id}
                  onChange={(e) => setEditForm((p) => ({ ...p, company_id: e.target.value }))}
                  required
                  className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
                >
                  <option value="">Selecione empresa</option>
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>{company.name}</option>
                  ))}
                </select>
              )}
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={editForm.is_active}
                  onChange={(e) => setEditForm((p) => ({ ...p, is_active: e.target.checked }))}
                />
                Usuario ativo
              </label>
              <button
                type="submit"
                disabled={editBusy}
                className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
              >
                {editBusy ? 'Salvando...' : 'Salvar usuario'}
              </button>
              {editError && <p className="text-sm text-red-600">{editError}</p>}
            </form>
          )}
        </section>
      </div>
    </Layout>
  );
}

export default AdminUsersPage;
