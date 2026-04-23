import './DashboardPage.css';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import Card from '@/components/ui/Card/Card.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';

function DashboardPage() {
  const { data, loading, error } = usePageData('/dashboard');
  const { logout } = useLogout();

  if (loading) {
    return (
      <Layout>
        <div className="space-y-4">
          <LoadingSkeleton className="h-6 w-52" />
          <LoadingSkeleton className="h-4 w-96 max-w-full" />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {Array.from({ length: 6 }).map((_, index) => (
              <Card key={`dashboard-skeleton-${index}`} className="h-full">
                <LoadingSkeleton className="h-5 w-40" />
                <LoadingSkeleton className="mt-3 h-3 w-full" />
                <LoadingSkeleton className="mt-2 h-3 w-11/12" />
                <LoadingSkeleton className="mt-2 h-3 w-9/12" />
                <LoadingSkeleton className="mt-5 h-3 w-24" />
              </Card>
            ))}
          </div>
        </div>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600">Não foi possível carregar o dashboard.</p>
      </Layout>
    );
  }

  if (data.role === 'admin') {
    const isSystemAdmin = data.user_role === 'system_admin';
    const adminItems = [
      {
        href: '/admin/empresas',
        title: 'Empresas',
        description: 'Acompanhe métricas e estado técnico sem acesso a conteúdo sensível.',
      },
      {
        href: '/admin/usuarios',
        title: 'Usuários',
        description: 'Gerencie utilizadores, perfis e vínculo com empresas.',
      },
      {
        href: '/admin/suporte',
        title: 'Solicitações de suporte',
        description: 'Veja chamados abertos ou fechados e atualize o estado de resolução.',
      },
      {
        href: '/admin/chat-interno',
        title: 'Chat interno',
        description: 'Converse em tempo real com utilizadores da plataforma sem sair do sistema.',
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

    const visibleAdminItems = isSystemAdmin
      ? adminItems
      : [
          ...adminItems.filter((item) =>
            ['/admin/empresas', '/admin/usuarios', '/admin/chat-interno', '/admin/conversas'].includes(item.href)
          ),
          {
            href: '/admin/auditoria',
            title: 'Auditoria',
            description: 'Consulte eventos de auditoria apenas das empresas ligadas ao seu reseller.',
          },
          {
            href: '/suporte',
            title: 'Abrir chamado',
            description: 'Registre um chamado interno para suporte da plataforma.',
          },
        ];

    return (
      <Layout role="admin" onLogout={logout}>
        <PageHeader
          title="Painel do sistema"
          subtitle="Visão central para gerir empresas, utilizadores e fluxo de atendimento."
        />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {visibleAdminItems.map((item) => (
            <a key={item.href} href={item.href} className="group block">
              <Card className="h-full transition group-hover:-translate-y-0.5 group-hover:shadow-[0_4px_12px_rgba(0,0,0,0.06)] group-hover:border-[#e5e5e5]">
                <p className="text-base font-semibold text-[#171717]">{item.title}</p>
                <p className="mt-2 text-sm text-[#737373] leading-relaxed">{item.description}</p>
                <p className="mt-4 text-xs font-medium text-[#2563eb]">Abrir módulo →</p>
              </Card>
            </a>
          ))}
        </div>
      </Layout>
    );
  }

  const companyItems = [
    {
      href: '/minha-conta/conversas',
      title: 'Conversas',
      description: 'Acompanhe conversas, etiquetas e transferências da equipe.',
    },
    {
      href: '/minha-conta/agendamentos',
      title: 'Agendamentos',
      description: 'Veja horarios livres, registre bloqueios e crie agendamentos manuais.',
    },
    {
      href: '/minha-conta/chat-interno',
      title: 'Chat interno',
      description: 'Canal rápido para falar com administradores e colegas em tempo real.',
    },
    {
      href: '/suporte',
      title: 'Suporte',
      description: 'Abra solicitações para o time da plataforma quando precisar de ajuda.',
    },
    {
      href: '/minha-conta/suporte/solicitacoes',
      title: 'Minhas solicitações',
      description: 'Acompanhe apenas os chamados que você abriu.',
    },
  ];

  const isAdmin = Boolean(data.can_manage_users || data.user_role === 'company_admin');

  if (isAdmin) {
    companyItems.push({
      href: '/minha-conta/usuarios',
      title: 'Usuários',
      description: 'Controle acessos, perfis e áreas de atuação do time.',
    });
    companyItems.push({
      href: '/minha-conta/bot',
      title: 'Configurações do bot',
      description: 'Ajuste mensagens, áreas, horários e regras de resposta.',
    });
  }

  return (
    <Layout role="company" companyName={data.companyName} onLogout={logout}>
      <PageHeader
        title={`Painel — ${data.companyName ?? 'Empresa'}`}
        subtitle="Resumo de operação para manter o bot alinhado com o atendimento humano."
      />
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {companyItems.map((item) => (
          <a key={item.href} href={item.href} className="group block">
            <Card className="h-full transition group-hover:-translate-y-0.5 group-hover:shadow-[0_4px_12px_rgba(0,0,0,0.06)] group-hover:border-[#e5e5e5]">
              <p className="text-base font-semibold text-[#171717]">{item.title}</p>
              <p className="mt-2 text-sm text-[#737373] leading-relaxed">{item.description}</p>
              <p className="mt-4 text-xs font-medium text-[#2563eb]">Abrir módulo →</p>
            </Card>
          </a>
        ))}
      </div>
    </Layout>
  );
}

export default DashboardPage;

