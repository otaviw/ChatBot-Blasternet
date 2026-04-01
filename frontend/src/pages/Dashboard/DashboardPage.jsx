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
        <p className="text-sm text-[#737373]">Carregando painel...</p>
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

    return (
      <Layout role="admin" onLogout={logout}>
        <PageHeader
          title="Painel do sistema"
          subtitle="Visão central para gerir empresas, utilizadores e fluxo de atendimento."
        />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {adminItems.map((item) => (
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
      href: '/minha-conta/bot',
      title: 'Configurações do bot',
      description: 'Ajuste mensagens, áreas, horários e regras de resposta.',
    },
    {
      href: '/minha-conta/simulador',
      title: 'Simulador',
      description: 'Valide fluxos antes de publicar no canal oficial.',
    },
    {
      href: '/minha-conta/conversas',
      title: 'Conversas',
      description: 'Acompanhe conversas, etiquetas e transferências da equipe.',
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

  const canManageUsers = Boolean(data.can_manage_users || data.user_role === 'company_admin');

  if (canManageUsers) {
    companyItems.push({
      href: '/minha-conta/usuarios',
      title: 'Usuários',
      description: 'Controle acessos, perfis e áreas de atuação do time.',
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
