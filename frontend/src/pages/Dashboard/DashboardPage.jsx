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
        <p className="text-sm text-red-600">Nao foi possivel carregar o dashboard.</p>
      </Layout>
    );
  }

  if (data.role === 'admin') {
    const adminItems = [
      {
        href: '/admin/empresas',
        title: 'Empresas',
        description: 'Acompanhe metricas e status tecnico sem acesso a conteudo sensivel.',
      },
      {
        href: '/admin/usuarios',
        title: 'Usuarios',
        description: 'Gerencie usuarios, perfis e vinculacao com empresas.',
      },
      {
        href: '/admin/suporte',
        title: 'Solicitacoes de suporte',
        description: 'Visualize chamados abertos/fechados e atualize o status de resolucao.',
      },
      {
        href: '/admin/simulador',
        title: 'Simulador',
        description: 'Teste comportamento do bot sem depender de canais externos.',
      },
      {
        href: '/suporte',
        title: 'Abrir chamado',
        description: 'Registre um novo chamado interno para o time de suporte.',
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
                <p className="mt-4 text-xs font-medium uppercase tracking-wide text-[#2563eb]">Abrir modulo</p>
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
    {
      href: '/suporte',
      title: 'Suporte',
      description: 'Abra solicitacoes para o time da plataforma quando precisar de ajuda.',
    },
    {
      href: '/minha-conta/suporte/solicitacoes',
      title: 'Minhas solicitacoes',
      description: 'Acompanhe apenas os chamados que voce abriu.',
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
              <p className="mt-4 text-xs font-medium uppercase tracking-wide text-[#2563eb]">Abrir modulo</p>
            </Card>
          </a>
        ))}
      </div>
    </Layout>
  );
}

export default DashboardPage;
