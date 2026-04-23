import { PERM, hasPermission } from '@/constants/permissions';
import { NOTIFICATION_MODULE } from '@/constants/notifications';

export const ADMIN_MAIN_LINKS = [
  { href: '/dashboard', label: 'Início', icon: 'dashboard', ariaLabel: 'Ir para o painel inicial' },
  { href: '/admin/empresas', label: 'Empresas', icon: 'empresas', ariaLabel: 'Cadastro e gestão de empresas' },
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
  {
    href: '/admin/simulador',
    label: 'Testar bot',
    icon: 'simulador',
    ariaLabel: 'Simular mensagens do bot sem enviar ao WhatsApp',
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
];

export const POLICY_LINKS = [
  {
    href: '/politica-de-privacidade.html',
    label: 'Política de privacidade',
    icon: 'politica',
    ariaLabel: 'Abrir página de política de privacidade',
  },
];

export function buildCompanyMainLinks({ userRole, userPerms, canManageUsers }) {
  const perm = (key) => hasPermission(userPerms, userRole, key);
  const links = [
    { href: '/dashboard', label: 'Início', icon: 'dashboard', ariaLabel: 'Ir para o painel inicial' },
  ];

  if (userRole !== 'agent') {
    links.push({
      href: '/minha-conta/bot',
      label: 'Bot',
      icon: 'bot',
      ariaLabel: 'Configurações do bot e atendimento',
    });
  }

  if (perm(PERM.PAGE_INBOX)) {
    links.push({
      href: '/minha-conta/conversas',
      label: 'Conversas',
      icon: 'inbox',
      ariaLabel: 'Atendimento e conversas com clientes',
      module: NOTIFICATION_MODULE.INBOX,
    });
  }

  if (perm(PERM.PAGE_CONTACTS)) {
    links.push({
      href: '/minha-conta/contatos',
      label: 'Contatos',
      icon: 'contatos',
      ariaLabel: 'Gestao de contatos da empresa',
    });
  }

  if (perm(PERM.PAGE_CAMPAIGNS)) {
    links.push({
      href: '/minha-conta/campanhas',
      label: 'Campanhas',
      icon: 'campanhas',
      ariaLabel: 'Gestao de campanhas de envio',
    });
  }

  if (perm(PERM.PAGE_INTERNAL_CHAT)) {
    links.push({
      href: '/minha-conta/chat-interno',
      label: 'Equipe interna',
      icon: 'chatInterno',
      ariaLabel: 'Mensagens entre membros da equipe',
      module: NOTIFICATION_MODULE.INTERNAL_CHAT,
    });
  }

  if (perm(PERM.PAGE_APPOINTMENTS)) {
    links.push({
      href: '/minha-conta/agendamentos',
      label: 'Agendamentos',
      icon: 'agendamentos',
      ariaLabel: 'Gestao de agenda e horários dos atendentes',
    });
  }

  if (perm(PERM.PAGE_QUICK_REPLIES)) {
    links.push({
      href: '/minha-conta/respostas-rapidas',
      label: 'Respostas rápidas',
      icon: 'respostas',
      ariaLabel: 'Mensagens prontas para resposta rápida',
    });
  }

  if (perm(PERM.PAGE_TAGS)) {
    links.push({
      href: '/minha-conta/tags',
      label: 'Tags',
      icon: 'tags',
      ariaLabel: 'Gerenciar tags de conversas',
    });
  }

  if (perm(PERM.PAGE_AUDIT)) {
    links.push({
      href: '/minha-conta/auditoria',
      label: 'Auditoria',
      icon: 'chatIa',
      ariaLabel: 'Auditoria geral do sistema',
    });
  }

  if (perm(PERM.PAGE_SIMULATOR)) {
    links.push({
      href: '/minha-conta/simulador',
      label: 'Testar bot',
      icon: 'simulador',
      ariaLabel: 'Simular mensagens do bot',
    });
  }

  if (userRole === 'system_admin') {
    links.push(
      {
        href: '/minha-conta/chat-ia',
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
        href: '/minha-conta/base-conhecimento',
        label: 'Base de conhecimento',
        icon: 'bot',
        ariaLabel: 'Base de conhecimento da IA',
      },
    );
  }

  if (canManageUsers) {
    links.push({
      href: '/minha-conta/usuarios',
      label: 'Usuários',
      icon: 'usuarios',
      ariaLabel: 'Usuários da empresa',
    });
  }

  return links;
}
