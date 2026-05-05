export const PERM = {
  PAGE_INBOX:         'page_inbox',
  PAGE_CONTACTS:      'page_contacts',
  PAGE_CAMPAIGNS:     'page_campaigns',
  PAGE_INTERNAL_CHAT: 'page_internal_chat',
  PAGE_APPOINTMENTS:  'page_appointments',
  PAGE_QUICK_REPLIES: 'page_quick_replies',
  PAGE_TAGS:          'page_tags',
  PAGE_AUDIT:         'page_audit',
  PAGE_SIMULATOR:     'page_simulator',
  PAGE_IXC_CLIENTS:   'page_ixc_clients',
  IXC_CLIENTS_VIEW:   'ixc_clients_view',
  IXC_INVOICES_VIEW:  'ixc_invoices_view',
  IXC_INVOICES_DOWNLOAD: 'ixc_invoices_download',
  IXC_INVOICES_SEND_EMAIL: 'ixc_invoices_send_email',
  IXC_INVOICES_SEND_SMS: 'ixc_invoices_send_sms',
  IXC_INTEGRATION_MANAGE: 'ixc_integration_manage',

  ACTION_MANAGE_CONTACTS:       'action_manage_contacts',
  ACTION_SEND_CAMPAIGNS:        'action_send_campaigns',
  ACTION_MANAGE_QUICK_REPLIES:  'action_manage_quick_replies',
  ACTION_MANAGE_APPOINTMENTS:   'action_manage_appointments',
  ACTION_MANAGE_TAGS:           'action_manage_tags',
};

export const ALL_PERMISSIONS = Object.values(PERM);

export const AGENT_DEFAULT_PERMISSIONS = [
  PERM.PAGE_INBOX,
  PERM.PAGE_CONTACTS,
  PERM.PAGE_CAMPAIGNS,
  PERM.PAGE_INTERNAL_CHAT,
  PERM.PAGE_APPOINTMENTS,
  PERM.PAGE_QUICK_REPLIES,
  PERM.PAGE_TAGS,
  PERM.PAGE_AUDIT,
  PERM.PAGE_SIMULATOR,
  PERM.ACTION_MANAGE_CONTACTS,
  PERM.ACTION_SEND_CAMPAIGNS,
  PERM.ACTION_MANAGE_QUICK_REPLIES,
  PERM.ACTION_MANAGE_APPOINTMENTS,
  PERM.ACTION_MANAGE_TAGS,
];

export const PERMISSION_GROUPS = [
  {
    key: 'pages',
    label: 'Páginas visíveis',
    description: 'O usuário pode acessar estas seções no menu lateral.',
    items: [
      { key: PERM.PAGE_INBOX,         label: 'Conversas' },
      { key: PERM.PAGE_CONTACTS,      label: 'Contatos' },
      { key: PERM.PAGE_CAMPAIGNS,     label: 'Campanhas' },
      { key: PERM.PAGE_INTERNAL_CHAT, label: 'Chat interno (Equipe)' },
      { key: PERM.PAGE_APPOINTMENTS,  label: 'Agendamentos' },
      { key: PERM.PAGE_QUICK_REPLIES, label: 'Respostas rápidas' },
      { key: PERM.PAGE_TAGS,          label: 'Tags' },
      { key: PERM.PAGE_AUDIT,         label: 'Auditoria' },
      { key: PERM.PAGE_SIMULATOR,     label: 'Testar bot' },
      { key: PERM.PAGE_IXC_CLIENTS,   label: 'Clientes IXC' },
    ],
  },
  {
    key: 'ixc',
    label: 'Integracao IXC',
    description: 'Controle de acesso ao modulo financeiro e operacoes de boleto.',
    items: [
      { key: PERM.PAGE_IXC_CLIENTS, label: 'Pagina Clientes IXC' },
      { key: PERM.IXC_CLIENTS_VIEW, label: 'Ver clientes IXC', dependsOn: PERM.PAGE_IXC_CLIENTS },
      { key: PERM.IXC_INVOICES_VIEW, label: 'Ver boletos IXC', dependsOn: PERM.PAGE_IXC_CLIENTS },
      { key: PERM.IXC_INVOICES_DOWNLOAD, label: 'Baixar boleto', dependsOn: PERM.IXC_INVOICES_VIEW },
      { key: PERM.IXC_INVOICES_SEND_EMAIL, label: 'Enviar boleto por e-mail', dependsOn: PERM.IXC_INVOICES_VIEW },
      { key: PERM.IXC_INVOICES_SEND_SMS, label: 'Enviar boleto por SMS', dependsOn: PERM.IXC_INVOICES_VIEW },
      { key: PERM.IXC_INTEGRATION_MANAGE, label: 'Gerenciar integracao IXC' },
    ],
  },
  {
    key: 'actions',
    label: 'Ações permitidas',
    description: 'Permissões de criação, edição e exclusão dentro de cada seção.',
    items: [
      { key: PERM.ACTION_MANAGE_CONTACTS,       label: 'Criar / editar / excluir contatos', dependsOn: PERM.PAGE_CONTACTS },
      { key: PERM.ACTION_SEND_CAMPAIGNS,        label: 'Disparar campanhas',                dependsOn: PERM.PAGE_CAMPAIGNS },
      { key: PERM.ACTION_MANAGE_QUICK_REPLIES,  label: 'Criar / editar / excluir respostas rápidas', dependsOn: PERM.PAGE_QUICK_REPLIES },
      { key: PERM.ACTION_MANAGE_APPOINTMENTS,   label: 'Criar / editar / excluir agendamentos',       dependsOn: PERM.PAGE_APPOINTMENTS },
      { key: PERM.ACTION_MANAGE_TAGS,           label: 'Criar / editar / excluir tags',    dependsOn: PERM.PAGE_TAGS },
    ],
  },
];

/**
 * Returns true if the given permission is granted.
 * Admins always have everything; null permissions means "all defaults granted".
 *
 * @param {string[]|null} permissions  - from the user object
 * @param {string}        role         - user role string
 * @param {string}        permission   - key to check
 */
export function hasPermission(permissions, role, permission) {
  if (role === 'system_admin' || role === 'company_admin') return true;
  if (permissions === null || permissions === undefined) return true;
  return permissions.includes(permission);
}
