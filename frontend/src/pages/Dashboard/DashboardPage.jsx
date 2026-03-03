import './DashboardPage.css';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import Card from '@/components/ui/Card/Card.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';

function DashboardPage() {
  const { data, loading, error } = usePageData('/dashboard');
  const { logout } = useLogout();

  if (loading) {
    return (
      <Layout>
        <p className="text-sm text-[#64748b]">Carregando dashboard...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600">
          Nao foi possivel carregar o dashboard.
        </p>
      </Layout>
    );
  }

  if (data.role === 'admin') {
    const adminItems = [
      {
        href: '/admin/empresas',
        title: 'Empresas',
        description: 'Cadastre empresas, acompanhe uso e configure credenciais.',
      },
      {
        href: '/admin/usuarios',
        title: 'Usuarios',
        description: 'Gerencie acessos com perfis de superadmin, admin de empresa e agente.',
      },
      {
        href: '/admin/conversas',
        title: 'Inbox',
        description: 'Acompanhe atendimento em tempo real e assuma conversas criticas.',
      },
      {
        href: '/admin/simulador',
        title: 'Simulador',
        description: 'Teste comportamento do bot sem depender de canais externos.',
      },
    ];

    return (
      <Layout role="admin" onLogout={logout}>
        <PageHeader
          title="Dashboard do sistema"
          subtitle="Visao central de operacao para gerenciar empresas, usuarios e fluxo de atendimento."
        />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {adminItems.map((item) => (
            <a key={item.href} href={item.href} className="group">
              <Card className="h-full transition group-hover:-translate-y-0.5 group-hover:shadow-[0_18px_30px_-24px_rgba(15,23,42,0.95)]">
                <p className="text-base font-semibold text-[#0f172a]">{item.title}</p>
                <p className="mt-2 text-sm text-[#64748b]">{item.description}</p>
                <p className="mt-4 text-xs font-medium uppercase tracking-wide text-[#f53003]">Abrir modulo</p>
              </Card>
            </a>
          ))}
        </div>
      </Layout>
    );
  }

  const companyItems = [
    {
      href: '/minha-conta/bot',
      title: 'Configuracoes do bot',
      description: 'Ajuste mensagens, areas, horarios e regras de resposta.',
    },
    {
      href: '/minha-conta/simulador',
      title: 'Simulador',
      description: 'Valide fluxos antes de publicar no canal oficial.',
    },
    {
      href: '/minha-conta/conversas',
      title: 'Inbox',
      description: 'Acompanhe conversas, tags e transferencias da equipe.',
    },
  ];

  if (data.can_manage_users) {
    companyItems.push({
      href: '/minha-conta/usuarios',
      title: 'Usuarios',
      description: 'Controle acessos, perfis e areas de atuacao do time.',
    });
  }

  return (
    <Layout role="company" companyName={data.companyName} onLogout={logout}>
      <PageHeader
        title={`Dashboard - ${data.companyName ?? 'Empresa'}`}
        subtitle="Resumo de operacao para manter o bot alinhado com o atendimento humano."
      />
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {companyItems.map((item) => (
          <a key={item.href} href={item.href} className="group">
            <Card className="h-full transition group-hover:-translate-y-0.5 group-hover:shadow-[0_18px_30px_-24px_rgba(15,23,42,0.95)]">
              <p className="text-base font-semibold text-[#0f172a]">{item.title}</p>
              <p className="mt-2 text-sm text-[#64748b]">{item.description}</p>
              <p className="mt-4 text-xs font-medium uppercase tracking-wide text-[#f53003]">Abrir modulo</p>
            </Card>
          </a>
        ))}
      </div>
    </Layout>
  );
}

export default DashboardPage;



