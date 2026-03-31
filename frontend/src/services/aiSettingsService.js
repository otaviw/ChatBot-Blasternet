import api from '@/services/api';

const DEFAULT_BUSINESS_HOURS = {
  monday: { enabled: true, start: '08:00', end: '18:00' },
  tuesday: { enabled: true, start: '08:00', end: '18:00' },
  wednesday: { enabled: true, start: '08:00', end: '18:00' },
  thursday: { enabled: true, start: '08:00', end: '18:00' },
  friday: { enabled: true, start: '08:00', end: '18:00' },
  saturday: { enabled: false, start: null, end: null },
  sunday: { enabled: false, start: null, end: null },
};

const DEFAULT_SETTINGS = {
  is_active: true,
  timezone: 'America/Sao_Paulo',
  welcome_message: '',
  fallback_message: '',
  out_of_hours_message: '',
  business_hours: DEFAULT_BUSINESS_HOURS,
  keyword_replies: [],
  service_areas: [],
  stateful_menu_flow: null,
  inactivity_close_hours: 24,
  ai_enabled: false,
  ai_internal_chat_enabled: false,
  ai_usage_enabled: true,
  ai_usage_limit_monthly: null,
  ai_persona: '',
  ai_tone: 'casual',
  ai_language: 'pt-BR',
  ai_formality: 'media',
  ai_system_prompt: '',
};

const toPositiveInt = (value) => {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
};

const normalizeBusinessHours = (value) => {
  const source = value && typeof value === 'object' ? value : {};

  return Object.keys(DEFAULT_BUSINESS_HOURS).reduce((result, dayKey) => {
    const daySource = source[dayKey] && typeof source[dayKey] === 'object' ? source[dayKey] : {};
    const dayDefault = DEFAULT_BUSINESS_HOURS[dayKey];

    result[dayKey] = {
      enabled: Boolean(daySource.enabled ?? dayDefault.enabled),
      start: daySource.start ?? dayDefault.start,
      end: daySource.end ?? dayDefault.end,
    };

    return result;
  }, {});
};

export const normalizeAiSettings = (raw) => {
  const source = raw && typeof raw === 'object' ? raw : {};

  return {
    ...DEFAULT_SETTINGS,
    ...source,
    is_active: Boolean(source.is_active ?? DEFAULT_SETTINGS.is_active),
    timezone: String(source.timezone ?? DEFAULT_SETTINGS.timezone),
    welcome_message: source.welcome_message ?? DEFAULT_SETTINGS.welcome_message,
    fallback_message: source.fallback_message ?? DEFAULT_SETTINGS.fallback_message,
    out_of_hours_message: source.out_of_hours_message ?? DEFAULT_SETTINGS.out_of_hours_message,
    business_hours: normalizeBusinessHours(source.business_hours),
    keyword_replies: Array.isArray(source.keyword_replies) ? source.keyword_replies : [],
    service_areas: Array.isArray(source.service_areas) ? source.service_areas : [],
    inactivity_close_hours:
      Number.isFinite(Number(source.inactivity_close_hours))
        ? Number(source.inactivity_close_hours)
        : DEFAULT_SETTINGS.inactivity_close_hours,
    ai_enabled: Boolean(source.ai_enabled ?? DEFAULT_SETTINGS.ai_enabled),
    ai_internal_chat_enabled: Boolean(
      source.ai_internal_chat_enabled ?? DEFAULT_SETTINGS.ai_internal_chat_enabled
    ),
    ai_usage_enabled: Boolean(source.ai_usage_enabled ?? DEFAULT_SETTINGS.ai_usage_enabled),
    ai_usage_limit_monthly:
      source.ai_usage_limit_monthly === null || source.ai_usage_limit_monthly === ''
        ? null
        : toPositiveInt(source.ai_usage_limit_monthly),
    ai_persona: String(source.ai_persona ?? DEFAULT_SETTINGS.ai_persona),
    ai_tone: String(source.ai_tone ?? DEFAULT_SETTINGS.ai_tone),
    ai_language: String(source.ai_language ?? DEFAULT_SETTINGS.ai_language),
    ai_formality: String(source.ai_formality ?? DEFAULT_SETTINGS.ai_formality),
    ai_system_prompt: String(source.ai_system_prompt ?? DEFAULT_SETTINGS.ai_system_prompt),
  };
};

const normalizeUser = (raw) => {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const id = toPositiveInt(raw.id);
  if (!id) {
    return null;
  }

  return {
    id,
    name: String(raw.name ?? ''),
    email: String(raw.email ?? ''),
    role: String(raw.role ?? 'agent'),
    is_active: Boolean(raw.is_active),
    can_use_ai: Boolean(raw.can_use_ai),
    areas: Array.isArray(raw.areas) ? raw.areas : [],
  };
};

export async function getSettings() {
  const response = await api.get('/minha-conta/bot');
  const payload = response.data ?? {};

  return {
    company: payload.company ?? null,
    settings: normalizeAiSettings(payload.settings),
  };
}

export async function updateSettings(data) {
  const payload = normalizeAiSettings(data);
  const response = await api.put('/minha-conta/bot', payload);

  return {
    settings: normalizeAiSettings(response.data?.settings ?? payload),
  };
}

export async function getUsers() {
  const response = await api.get('/minha-conta/users');
  const users = Array.isArray(response.data?.users) ? response.data.users : [];

  return {
    users: users.map(normalizeUser).filter(Boolean),
  };
}

export async function updateUserPermission(userId, canUseAi) {
  const normalizedUserId = toPositiveInt(userId);
  if (!normalizedUserId) {
    throw new Error('Usuario invalido.');
  }

  const usersResponse = await getUsers();
  const currentUser = (usersResponse.users ?? []).find((item) => item.id === normalizedUserId);

  if (!currentUser) {
    throw new Error('Usuario nao encontrado.');
  }

  const payload = {
    name: currentUser.name,
    email: currentUser.email,
    role: currentUser.role,
    is_active: Boolean(currentUser.is_active),
    can_use_ai: Boolean(canUseAi),
    areas: Array.isArray(currentUser.areas) ? currentUser.areas : [],
  };

  const response = await api.put(`/minha-conta/users/${normalizedUserId}`, payload);

  return {
    user: normalizeUser(response.data?.user ?? { ...currentUser, can_use_ai: canUseAi }),
  };
}
