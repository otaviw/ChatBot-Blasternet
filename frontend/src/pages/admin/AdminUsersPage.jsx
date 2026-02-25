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
    areas: [],
  });
  const [editForm, setEditForm] = useState(null);

  const users = data?.users ?? [];
  const companies = companiesData?.companies ?? [];

  const getCompanyAreas = (companyId) => {
    if (!companyId) return [];
    const company = companies.find((item) => String(item.id) === String(companyId));
    const areas = company?.bot_setting?.service_areas;
    return Array.isArray(areas) ? areas : [];
  };
  const createCompanyAreas = getCompanyAreas(createForm.company_id);
  const editCompanyAreas = getCompanyAreas(editForm?.company_id);

  const toggleCreateArea = (area) => {
    setCreateForm((prev) => {
      const exists = (prev.areas ?? []).includes(area);
      const nextAreas = exists
        ? (prev.areas ?? []).filter((value) => value !== area)
        : [...(prev.areas ?? []), area];
      return { ...prev, areas: nextAreas };
    });
  };

  const toggleEditArea = (area) => {
    setEditForm((prev) => {
      if (!prev) return prev;
      const exists = (prev.areas ?? []).includes(area);
      const nextAreas = exists
        ? (prev.areas ?? []).filter((value) => value !== area)
        : [...(prev.areas ?? []), area];
      return { ...prev, areas: nextAreas };
    });
  };

  const handleCreate = async (event) => {
    event.preventDefault();
    setCreateBusy(true);
    setCreateError('');
    try {
      const payload = {
        ...createForm,
        company_id: createForm.role === 'company' && createForm.company_id ? Number(createForm.company_id) : null,
        areas: createForm.role === 'company' ? (createForm.areas ?? []) : [],
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
      areas: Array.isArray(user.areas) ? user.areas : [],
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
        company_id: editForm.role === 'company' && editForm.company_id ? Number(editForm.company_id) : null,
        areas: editForm.role === 'company' ? (editForm.areas ?? []) : [],
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
              onChange={(e) => setCreateForm((p) => ({ ...p, role: e.target.value, company_id: '', areas: [] }))}
              className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            >
              <option value="company">company</option>
              <option value="admin">admin</option>
            </select>
            {createForm.role === 'company' && (
              <select
                value={createForm.company_id}
                onChange={(e) =>
                  setCreateForm((p) => {
                    const companyId = e.target.value;
                    const allowedAreas = getCompanyAreas(companyId);
                    return {
                      ...p,
                      company_id: companyId,
                      areas: (p.areas ?? []).filter((area) => allowedAreas.includes(area)),
                    };
                  })
                }
                required
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              >
                <option value="">Selecione empresa</option>
                {companies.map((company) => (
                  <option key={company.id} value={company.id}>{company.name}</option>
                ))}
              </select>
            )}
            {createForm.role === 'company' && (
              <div className="rounded border border-[#d5d5d2] p-3 space-y-2">
                <p className="text-sm font-medium">Areas de atuacao</p>
                {!createForm.company_id && (
                  <p className="text-xs text-[#706f6c]">Selecione a empresa para escolher as areas.</p>
                )}
                {!!createForm.company_id && !createCompanyAreas.length && (
                  <p className="text-xs text-[#706f6c]">
                    Empresa sem areas cadastradas. Configure em Config. do bot da empresa.
                  </p>
                )}
                {!!createForm.company_id && createCompanyAreas.length > 0 && (
                  <div className="flex flex-wrap gap-3">
                    {createCompanyAreas.map((area) => (
                      <label key={area} className="inline-flex items-center gap-2 text-sm">
                        <input
                          type="checkbox"
                          checked={(createForm.areas ?? []).includes(area)}
                          onChange={() => toggleCreateArea(area)}
                        />
                        {area}
                      </label>
                    ))}
                  </div>
                )}
              </div>
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
                    <div className="text-xs text-[#706f6c]">
                      Areas: {Array.isArray(user.areas) && user.areas.length ? user.areas.join(', ') : '-'}
                    </div>
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
                onChange={(e) => setEditForm((p) => ({ ...p, role: e.target.value, company_id: '', areas: [] }))}
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              >
                <option value="company">company</option>
                <option value="admin">admin</option>
              </select>
              {editForm.role === 'company' && (
                <select
                  value={editForm.company_id}
                  onChange={(e) =>
                    setEditForm((p) => {
                      const companyId = e.target.value;
                      const allowedAreas = getCompanyAreas(companyId);
                      return {
                        ...p,
                        company_id: companyId,
                        areas: (p.areas ?? []).filter((area) => allowedAreas.includes(area)),
                      };
                    })
                  }
                  required
                  className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
                >
                  <option value="">Selecione empresa</option>
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>{company.name}</option>
                  ))}
                </select>
              )}
              {editForm.role === 'company' && (
                <div className="rounded border border-[#d5d5d2] p-3 space-y-2">
                  <p className="text-sm font-medium">Areas de atuacao</p>
                  {!editForm.company_id && (
                    <p className="text-xs text-[#706f6c]">Selecione a empresa para escolher as areas.</p>
                  )}
                  {!!editForm.company_id && !editCompanyAreas.length && (
                    <p className="text-xs text-[#706f6c]">
                      Empresa sem areas cadastradas. Configure em Config. do bot da empresa.
                    </p>
                  )}
                  {!!editForm.company_id && editCompanyAreas.length > 0 && (
                    <div className="flex flex-wrap gap-3">
                      {editCompanyAreas.map((area) => (
                        <label key={area} className="inline-flex items-center gap-2 text-sm">
                          <input
                            type="checkbox"
                            checked={(editForm.areas ?? []).includes(area)}
                            onChange={() => toggleEditArea(area)}
                          />
                          {area}
                        </label>
                      ))}
                    </div>
                  )}
                </div>
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
