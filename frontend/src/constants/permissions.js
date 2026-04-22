// ── Permission keys ──────────────────────────────────────────────────────────
export const PERM = {
  // Pages
  PAGE_INBOX:         'page_inbox',
  PAGE_CONTACTS:      'page_contacts',
  PAGE_CAMPAIGNS:     'page_campaigns',
  PAGE_INTERNAL_CHAT: 'page_internal_chat',
  PAGE_APPOINTMENTS:  'page_appointments',
  PAGE_QUICK_REPLIES: 'page_quick_replies',
  PAGE_TAGS:          'page_tags',
  PAGE_AUDIT:         'page_audit',
  PAGE_SIMULATOR:     'page_simulator',

  // Actions
  ACTION_MANAGE_CONTACTS:       'action_manage_contacts',
  ACTION_SEND_CAMPAIGNS:        'action_send_campaigns',
  ACTION_MANAGE_QUICK_REPLIES:  'action_manage_quick_replies',
  ACTION_MANAGE_APPOINTMENTS:   'action_manage_appointments',
  ACTION_MANAGE_TAGS:           'action_manage_tags',
};

// ── All keys in order ────────────────────────────────────────────────────────
export const ALL_PERMISSIONS = Object.values(PERM);

// ── Default set for agents (all permissions granted) ─────────────────────────
export const AGENT_DEFAULT_PERMISSIONS = ALL_PERMISSIONS;

// ── Grouped for the permissions UI ──────────────────────────────────────────
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
