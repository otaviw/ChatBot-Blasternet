import { PERM, hasPermission } from '@/constants/permissions';
import { NOTIFICATION_MODULE } from '@/constants/notifications';

export const ADMIN_MAIN_LINKS = [
  { href: '/dashboard', label: 'Início', icon: 'dashboard', ariaLabel: 'Ir para o painel inicial' },
  { href: '/admin/empresas', label: 'Empresas', icon: 'empresas', ariaLabel: 'Cadastro e gestão de empresas' },
  {
    href: '/admin/minha-revenda',
    label: 'Minha revenda',
    icon: 'empresas',
    ariaLabel: 'Visualizar e editar os dados da minha revenda',
  },
  { href: '/admin/usuarios', label: 'Usuários', icon: 'usuarios', ariaLabel: 'Usuários do sistema' },
  {
    href: '/admin/conversas',
    label: 'Conversas',
    icon: 'inbox',
    ariaLabel: 'Atendimento e conversas com clientes',
    module: NOTIFICATION_MODULE.INBOX,
  },
  {
    href: '/admin/chat-interno',
    label: 'Equipe interna',
    icon: 'chatInterno',
    ariaLabel: 'Mensagens entre membros da equipe',
    module: NOTIFICATION_MODULE.INTERNAL_CHAT,
  },
  {
    href: '/admin/chat-ia',
    label: 'Assistente',
    icon: 'chatIa',
    ariaLabel: 'Chat com assistente de IA',
  },
  {
    href: '/minha-conta/ia/configuracoes',
    label: 'Configurações de IA',
    icon: 'bot',
    ariaLabel: 'Configurações de inteligência artificial',
  },
  {
    href: '/minha-conta/ia/analytics',
    label: 'Analytics IA',
    icon: 'chatIa',
    ariaLabel: 'Analytics de uso da IA',
  },
  {
    href: '/minha-conta/ia/auditoria',
    label: 'Auditoria IA',
    icon: 'chatIa',
    ariaLabel: 'Auditoria de ações da IA',
  },
  {
    href: '/admin/auditoria',
    label: 'Auditoria',
    icon: 'chatIa',
    ariaLabel: 'Auditoria geral do sistema',
  },
  {
    href: '/minha-conta/base-conhecimento',
    label: 'Base de conhecimento',
    icon: 'bot',
    ariaLabel: 'Base de conhecimento da IA',
  },
];

export const ADMIN_SUPPORT_LINKS = [
  {
    href: '/admin/suporte',
    label: 'Chamados',
    icon: 'chamados',
    ariaLabel: 'Solicitações de suporte',
    module: NOTIFICATION_MODULE.SUPPORT,
  },
  { href: '/suporte', label: 'Novo chamado', icon: 'novoChamado', ariaLabel: 'Abrir nova solicitação de suporte' },
  { href: '/manuais', label: 'Manuais', icon: 'politica', ariaLabel: 'Consultar manuais de telas e fluxos' },
];

export const COMPANY_SUPPORT_LINKS = [
  { href: '/suporte', label: 'Pedir ajuda', icon: 'suporte', ariaLabel: 'Registrar chamado de suporte' },
  {
    href: '/minha-conta/suporte/solicitacoes',
    label: 'Meus chamados',
    icon: 'chamados',
    ariaLabel: 'Acompanhar chamados de suporte',
    module: NOTIFICATION_MODULE.SUPPORT,
  },
  { href: '/manuais', label: 'Manuais', icon: 'politica', ariaLabel: 'Consultar manuais de telas e fluxos' },
];

export const POLICY_LINKS = [
  {
    href: '/politica-de-privacidade.html',
    label: 'Política de privacidade',
    icon: 'politica',
    ariaLabel: 'Abrir página de política de privacidade',
  },
];

export function buildCompanyMainLinks({
  userRole,
  userPerms,
  canManageUsers,
  canManageAi = false,
  canUseInternalAi = false,
  hasIxcIntegration,
}) {
  const perm = (key) => hasPermission(userPerms, userRole, key);
  const links = [
    { href: '/dashboard', label: 'Inicio', icon: 'dashboard', ariaLabel: 'Ir para o painel inicial' },
  ];
  const atendimento = [];
  const relacionamento = [];
  const botIa = [];
  const integracoes = [];
  const empresa = [];

  if (perm(PERM.PAGE_INBOX)) {
    atendimento.push({
      href: '/minha-conta/conversas',
      label: 'Conversas',
      icon: 'inbox',
      ariaLabel: 'Atendimento e conversas com clientes',
      module: NOTIFICATION_MODULE.INBOX,
    });
  }

  if (perm(PERM.PAGE_INTERNAL_CHAT)) {
    atendimento.push({
      href: '/minha-conta/chat-interno',
      label: 'Equipe interna',
      icon: 'chatInterno',
      ariaLabel: 'Mensagens entre membros da equipe',
      module: NOTIFICATION_MODULE.INTERNAL_CHAT,
    });
  }

  if (perm(PERM.PAGE_APPOINTMENTS)) {
    atendimento.push({
      href: '/minha-conta/agendamentos',
      label: 'Agendamentos',
      icon: 'agendamentos',
      ariaLabel: 'Gestao de agenda e horarios dos atendentes',
    });
  }

  if (perm(PERM.PAGE_CONTACTS)) {
    relacionamento.push({
      href: '/minha-conta/contatos',
      label: 'Contatos',
      icon: 'contatos',
      ariaLabel: 'Gestao de contatos da empresa',
    });
  }

  if (perm(PERM.PAGE_QUICK_REPLIES)) {
    relacionamento.push({
      href: '/minha-conta/respostas-rapidas',
      label: 'Respostas rapidas',
      icon: 'respostas',
      ariaLabel: 'Mensagens prontas para resposta rapida',
    });
  }

  if (perm(PERM.PAGE_TAGS)) {
    relacionamento.push({
      href: '/minha-conta/tags',
      label: 'Tags',
      icon: 'tags',
      ariaLabel: 'Gerenciar tags de conversas',
    });
  }

  if (perm(PERM.PAGE_CAMPAIGNS)) {
    relacionamento.push({
      href: '/minha-conta/campanhas',
      label: 'Campanhas',
      icon: 'campanhas',
      ariaLabel: 'Gestao de campanhas de envio',
    });
  }

  if (userRole !== 'agent') {
    botIa.push({
      href: '/minha-conta/bot',
      label: 'Bot',
      icon: 'bot',
      ariaLabel: 'Configuracoes do bot e atendimento',
    });
  }

  if (canUseInternalAi) {
    botIa.push({
      href: '/minha-conta/chat-ia',
      label: 'Assistente',
      icon: 'chatIa',
      ariaLabel: 'Chat com assistente de IA',
    });
  }

  if (canManageAi) {
    botIa.push(
      {
        href: '/minha-conta/ia/configuracoes',
        label: 'Configuracoes de IA',
        icon: 'bot',
        ariaLabel: 'Configuracoes de inteligencia artificial',
      },
      {
        href: '/minha-conta/ia/analytics',
        label: 'Analytics IA',
        icon: 'chatIa',
        ariaLabel: 'Analytics de uso da IA',
      },
      {
        href: '/minha-conta/ia/auditoria',
        label: 'Auditoria IA',
        icon: 'chatIa',
        ariaLabel: 'Auditoria de acoes da IA',
      },
      {
        href: '/minha-conta/base-conhecimento',
        label: 'Base de conhecimento',
        icon: 'bot',
        ariaLabel: 'Base de conhecimento da IA',
      },
    );
  }

  if (perm(PERM.PAGE_IXC_CLIENTS) && hasIxcIntegration) {
    integracoes.push({
      href: '/minha-conta/ixc/clientes',
      label: 'Clientes IXC',
      icon: 'contatos',
      ariaLabel: 'Consultar clientes da integracao IXC',
    });
  }

  if (canManageUsers) {
    empresa.push({
      href: '/minha-conta/usuarios',
      label: 'Usuarios',
      icon: 'usuarios',
      ariaLabel: 'Usuarios da empresa',
    });
  }

  if (userRole === 'company_admin') {
    empresa.push({
      href: '/minha-conta/empresa',
      label: 'Minha empresa',
      icon: 'empresas',
      ariaLabel: 'Configurar dados da minha empresa e integracoes',
    });
  }

  if (perm(PERM.PAGE_AUDIT)) {
    empresa.push({
      href: '/minha-conta/auditoria',
      label: 'Auditoria',
      icon: 'chatIa',
      ariaLabel: 'Auditoria geral do sistema',
    });
  }

  const pushGroup = (id, label, icon, ariaLabel, children) => {
    if (children.length > 0) {
      links.push({ id, label, icon, ariaLabel, children });
    }
  };

  pushGroup('company-service', 'Atendimento', 'inbox', 'Atendimento e operacao das conversas', atendimento);
  pushGroup('company-relationship', 'Relacionamento', 'contatos', 'Contatos, campanhas e organizacao do atendimento', relacionamento);
  pushGroup('company-bot-ai', 'Bot e IA', 'bot', 'Automacao, bot e inteligencia artificial', botIa);
  pushGroup('company-integrations', 'Integracoes', 'empresas', 'Integracoes e sistemas externos', integracoes);
  pushGroup('company-admin', 'Empresa', 'empresas', 'Configuracoes da empresa, usuarios e auditoria', empresa);

  return links;
}
