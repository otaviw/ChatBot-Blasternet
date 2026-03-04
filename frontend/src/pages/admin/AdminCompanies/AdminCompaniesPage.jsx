import './AdminCompaniesPage.css';
import { useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';

function AdminCompaniesPage() {
  const { data, loading, error } = usePageData('/admin/empresas');
  const { logout } = useLogout();
  const [newCompany, setNewCompany] = useState({
    name: '',
    meta_phone_number_id: '',
  });
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');
  const [createSuccess, setCreateSuccess] = useState('');

  const handleCreateCompany = async (event) => {
    event.preventDefault();
    setCreateBusy(true);
    setCreateError('');
    setCreateSuccess('');

    try {
      const payload = {
        name: newCompany.name,
        meta_phone_number_id: newCompany.meta_phone_number_id || null,
      };
      const response = await api.post('/admin/empresas', payload);
      const created = response.data?.company;
      setCreateSuccess(`Empresa criada: ${created?.name ?? payload.name}`);
      setNewCompany({ name: '', meta_phone_number_id: '' });
      setTimeout(() => window.location.reload(), 400);
    } catch (err) {
      setCreateError(err.response?.data?.message || 'Falha ao criar empresa.');
    } finally {
      setCreateBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando empresas...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar as empresas.</p>
      </Layout>
    );
  }

  const companies = data.companies ?? [];

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Empresas</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
        Lista de empresas com acesso. Clique para ver informacoes e uso.
      </p>

      <section className="mb-8 border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
        <h2 className="font-medium mb-3">Criar empresa</h2>
        <form onSubmit={handleCreateCompany} className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="block text-sm md:col-span-2">
            Nome da empresa
            <input
              type="text"
              value={newCompany.name}
              onChange={(e) => setNewCompany((p) => ({ ...p, name: e.target.value }))}
              required
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Meta Phone Number ID
            <input
              type="text"
              value={newCompany.meta_phone_number_id}
              onChange={(e) => setNewCompany((p) => ({ ...p, meta_phone_number_id: e.target.value }))}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <div className="md:col-span-2">
            <button
              type="submit"
              disabled={createBusy}
              className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
            >
              {createBusy ? 'Criando...' : 'Criar empresa'}
            </button>
          </div>
        </form>
        {createError && <p className="text-sm text-red-600 mt-2">{createError}</p>}
        {createSuccess && <p className="text-sm text-green-700 mt-2">{createSuccess}</p>}
      </section>

      {!companies.length ? (
        <p className="text-sm text-[#706f6c]">Nenhuma empresa cadastrada.</p>
      ) : (
        <ul className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A] overflow-hidden">
          {companies.map((company) => (
            <li key={company.id}>
              <a href={`/admin/empresas/${company.id}`} className="block px-4 py-3 hover:bg-[#FDFDFC] dark:hover:bg-[#161615]">
                <span className="font-medium">{company.name}</span>
                <span className="text-sm text-[#706f6c] dark:text-[#A1A09A] ml-2">
                  - {company.conversations_count ?? 0} conversa(s)
                </span>
                <span className="text-xs text-[#706f6c] dark:text-[#A1A09A] ml-2">
                  | bot: {company.bot_setting ? 'configurado' : 'padrao'}
                </span>
              </a>
            </li>
          ))}
        </ul>
      )}
    </Layout>
  );
}

export default AdminCompaniesPage;




