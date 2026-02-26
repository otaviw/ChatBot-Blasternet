import React from 'react';
import Layout from '../components/Layout';
import usePageData from '../hooks/usePageData';
import useLogout from '../hooks/useLogout';

function DashboardPage() {
  const { data, loading, error } = usePageData('/dashboard');
  const { logout } = useLogout();

  if (loading) {
    return (
      <Layout>
        <p className="text-sm text-[#706f6c]">Carregando dashboard...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">
          Nao foi possivel carregar o dashboard.
        </p>
      </Layout>
    );
  }

  if (data.role === 'admin') {
    return (
      <Layout role="admin" onLogout={logout}>
        <h1 className="text-xl font-medium mb-2">Dashboard - Sistema</h1>
        <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
          Voce esta como superadmin. Aqui voce gerencia empresas, usuarios e informacoes globais.
        </p>

        <ul className="space-y-2 text-sm">
          <li>
            <a href="/admin/empresas" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
              Empresas
            </a>{' '}
            - listar, ver informacoes e uso de cada uma
          </li>
          <li>
            <a href="/admin/simulador" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
              Simulador
            </a>{' '}
            - testar resposta do bot sem Meta
          </li>
          <li>
            <a href="/admin/conversas" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
              Inbox
            </a>{' '}
            - ver historico de mensagens
          </li>
          <li>
            <a href="/admin/usuarios" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
              Usuarios
            </a>{' '}
            - criar e gerenciar acessos
          </li>
        </ul>
      </Layout>
    );
  }

  return (
    <Layout role="company" companyName={data.companyName} onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Dashboard - {data.companyName ?? 'Empresa'}</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
        Aqui voce gerencia como o bot funciona: respostas, horarios e demais configuracoes.
      </p>

      <ul className="space-y-2 text-sm">
        <li>
          <a href="/minha-conta/bot" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
            Configuracoes do bot
          </a>{' '}
          - respostas, horarios, etc.
        </li>
        <li>
          <a href="/minha-conta/simulador" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
            Simulador
          </a>{' '}
          - testar conversa localmente
        </li>
        <li>
          <a href="/minha-conta/conversas" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
            Inbox
          </a>{' '}
          - acompanhar conversas recebidas
        </li>
        {data.can_manage_users && (
          <li>
            <a href="/minha-conta/usuarios" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
              Usuarios
            </a>{' '}
            - criar, editar e desativar usuarios da sua empresa
          </li>
        )}
      </ul>
    </Layout>
  );
}

export default DashboardPage;
