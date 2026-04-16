const PREFIXES_BY_ROLE = Object.freeze({
  admin: ['/admin', '', '/minha-conta'],
  company: ['', '/minha-conta', '/admin'],
});

const CANONICAL_ACTION_TEMPLATES = Object.freeze({
  listConversations: '/chat/conversations',
  getConversation: '/chat/conversations/:conversationId',
  createConversation: '/chat/conversations',
  deleteConversation: '/chat/conversations/:conversationId',
  sendMessage: '/chat/conversations/:conversationId/messages',
  updateMessage: '/chat/conversations/:conversationId/messages/:messageId',
  deleteMessage: '/chat/conversations/:conversationId/messages/:messageId',
  markRead: '/chat/conversations/:conversationId/read',
  toggleReaction: '/chat/conversations/:conversationId/messages/:messageId/reactions',
  updateGroupName: '/chat/conversations/:conversationId/group-name',
  addGroupParticipant: '/chat/conversations/:conversationId/participants',
  removeGroupParticipant: '/chat/conversations/:conversationId/participants/:participantId',
  updateGroupParticipantAdmin: '/chat/conversations/:conversationId/participants/:participantId/admin',
  leaveGroup: '/chat/conversations/:conversationId/leave',
  deleteGroup: '/chat/conversations/:conversationId/group',
  listRecipients: '/chat/users',
});

const LEGACY_ENDPOINT_COMPAT_ENABLED = !new Set(['0', 'false', 'off', 'no']).has(
  String(import.meta.env.VITE_INTERNAL_CHAT_ENABLE_LEGACY_ENDPOINTS ?? '0')
    .trim()
    .toLowerCase()
);

const LEGACY_ACTION_ALIASES = Object.freeze({
  listConversations: ['/chat/conversas'],

  getConversation: ['/chat/conversas/:conversationId'],

  createConversation: ['/chat/conversas'],
  deleteConversation: [],

  sendMessage: ['/chat/conversas/:conversationId/mensagens', '/chat/messages', '/chat/mensagens'],

  updateMessage: [
    '/chat/conversas/:conversationId/mensagens/:messageId',
    '/chat/messages/:messageId',
    '/chat/mensagens/:messageId',
  ],
  deleteMessage: [
    '/chat/conversas/:conversationId/mensagens/:messageId',
    '/chat/messages/:messageId',
    '/chat/mensagens/:messageId',
  ],
  markRead: [
    '/chat/conversas/:conversationId/lido',
    '/chat/conversations/:conversationId/mark-read',
    '/chat/conversas/:conversationId/marcar-lido',
  ],
  toggleReaction: [],
  updateGroupName: [],
  addGroupParticipant: [],
  removeGroupParticipant: [],
  updateGroupParticipantAdmin: [],
  leaveGroup: [],
  deleteGroup: [],
  listRecipients: ['/chat/usuarios', '/chat/recipients', '/chat/destinatarios'],
});

const LEGACY_RECIPIENT_FALLBACKS_BY_ROLE = Object.freeze({
  admin: ['/admin/users'],
  company: ['/minha-conta/users', '/admin/users'],
});

const unique = (items) => [...new Set(items)];

const withPrefix = (prefix, template) => {
  if (template.startsWith('/admin/') || template.startsWith('/minha-conta/')) {
    return template;
  }

  if (!prefix) {
    return template;
  }

  return `${prefix}${template}`;
};

const buildExpandedTemplates = (role, templates) => {
  const prefixes = PREFIXES_BY_ROLE[role] ?? PREFIXES_BY_ROLE.company;
  return prefixes.flatMap((prefix) => templates.map((template) => withPrefix(prefix, template)));
};

export const applyParams = (template, params = {}) => {
  let value = template;

  Object.entries(params).forEach(([key, rawValue]) => {
    value = value.replaceAll(`:${key}`, encodeURIComponent(String(rawValue)));
  });

  return value;
};

export const buildCanonicalActionTemplates = (role, action) => {
  const canonicalTemplate = CANONICAL_ACTION_TEMPLATES[action];
  if (!canonicalTemplate) {
    return [];
  }

  return unique(buildExpandedTemplates(role, [canonicalTemplate]));
};

export const buildActionTemplates = (role, action) => {
  const canonicalTemplates = buildCanonicalActionTemplates(role, action);
  if (canonicalTemplates.length === 0) {
    return [];
  }

  if (!LEGACY_ENDPOINT_COMPAT_ENABLED) {
    return canonicalTemplates;
  }

  const legacyAliases = LEGACY_ACTION_ALIASES[action] ?? [];
  const legacyTemplates = buildExpandedTemplates(role, legacyAliases);

  if (action === 'listRecipients') {
    return unique([
      ...canonicalTemplates,
      ...legacyTemplates,
      ...(LEGACY_RECIPIENT_FALLBACKS_BY_ROLE[role] ?? []),
    ]);
  }

  return unique([...canonicalTemplates, ...legacyTemplates]);
};
