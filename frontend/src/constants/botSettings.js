const DAY_KEYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

const DAY_LABELS = {
  monday: 'Segunda',
  tuesday: 'Terca',
  wednesday: 'Quarta',
  thursday: 'Quinta',
  friday: 'Sexta',
  saturday: 'Sabado',
  sunday: 'Domingo',
};

const DEFAULT_SETTINGS = {
  is_active: true,
  timezone: 'America/Sao_Paulo',
  welcome_message: 'Oi. Como posso ajudar?',
  fallback_message: 'Nao entendi sua mensagem. Pode reformular?',
  out_of_hours_message: 'Estamos fora do horario de atendimento no momento.',
  business_hours: {
    monday: { enabled: true, start: '08:00', end: '18:00' },
    tuesday: { enabled: true, start: '08:00', end: '18:00' },
    wednesday: { enabled: true, start: '08:00', end: '18:00' },
    thursday: { enabled: true, start: '08:00', end: '18:00' },
    friday: { enabled: true, start: '08:00', end: '18:00' },
    saturday: { enabled: false, start: '', end: '' },
    sunday: { enabled: false, start: '', end: '' },
  },
  keyword_replies: [],
  service_areas: [],
};

function normalizeSettings(input) {
  const merged = {
    ...DEFAULT_SETTINGS,
    ...(input ?? {}),
    business_hours: {
      ...DEFAULT_SETTINGS.business_hours,
      ...((input ?? {}).business_hours ?? {}),
    },
  };

  return {
    ...merged,
    keyword_replies: Array.isArray(merged.keyword_replies) ? merged.keyword_replies : [],
    service_areas: Array.isArray(merged.service_areas) ? merged.service_areas : [],
  };
}

export { DAY_KEYS, DAY_LABELS, DEFAULT_SETTINGS, normalizeSettings };
