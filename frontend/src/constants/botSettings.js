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
  ai_chatbot_enabled: false,
  timezone: 'America/Sao_Paulo',
  welcome_message: 'Oi. Como posso ajudar?',
  fallback_message: 'Não entendi sua mensagem. Pode reformular?',
  out_of_hours_message: 'Estamos fora do horário de atendimento no momento.',
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
  inactivity_close_hours: 24,
  message_retention_days: 180,
  service_areas: [],
  stateful_menu_flow: null,
};

function buildDefaultStatefulMenuFlow(welcomeMessage = 'Ola! O que você precisa?') {
  const intro = String(welcomeMessage || '').trim() || 'Ola! O que você precisa?';

  return {
    commands: ['#', 'menu'],
    initial: { flow: 'main', step: 'menu' },
    steps: {
      'main.menu': {
        type: 'numeric_menu',
        reply_text: `${intro}\n1 - Suporte técnico\n2 - Vendas\n3 - Falar com atendente`,
        options: {
          '1': {
            label: 'Suporte técnico',
            action: { kind: 'go_to', flow: 'support', step: 'issue_menu' },
          },
          '2': {
            label: 'Vendas',
            action: {
              kind: 'handoff',
              target_area_name: 'Vendas',
              reply_text: 'Perfeito. Vou te encaminhar para Vendas.',
            },
          },
          '3': {
            label: 'Falar com atendente',
            action: {
              kind: 'handoff',
              target_area_name: 'Atendimento',
              reply_text: 'Certo. Vou te encaminhar para um atendente.',
            },
          },
        },
      },
      'support.issue_menu': {
        type: 'numeric_menu',
        reply_text: 'Suporte técnico. Qual o problema?\n1 - Internet lenta\n2 - Sem conexão\n3 - Outro',
        options: {
          '1': {
            label: 'Internet lenta',
            action: {
              kind: 'handoff',
              target_area_name: 'Suporte',
              reply_text: 'Entendi: internet lenta. Vou te encaminhar para o Suporte.',
            },
          },
          '2': {
            label: 'Sem conexão',
            action: {
              kind: 'handoff',
              target_area_name: 'Suporte',
              reply_text: 'Entendi: sem conexão. Vou te encaminhar para o Suporte.',
            },
          },
          '3': {
            label: 'Outro',
            action: { kind: 'go_to', flow: 'support', step: 'free_text_issue' },
          },
        },
      },
      'support.free_text_issue': {
        type: 'free_text',
        reply_text: 'Beleza. Me descreve o problema em uma frase.',
        empty_input_reply_text: 'Beleza. Me descreve o problema em uma frase.',
        on_text: {
          kind: 'handoff',
          target_area_name: 'Suporte',
          reply_text: 'Perfeito, vou encaminhar sua descrição para o Suporte.',
        },
      },
    },
  };
}

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
    ai_chatbot_enabled: Boolean(merged.ai_chatbot_enabled),
    keyword_replies: Array.isArray(merged.keyword_replies) ? merged.keyword_replies : [],
    inactivity_close_hours:
      Number.isFinite(Number(merged.inactivity_close_hours)) &&
      Number(merged.inactivity_close_hours) >= 1 &&
      Number(merged.inactivity_close_hours) <= 720
        ? Number(merged.inactivity_close_hours)
        : 24,
    message_retention_days: (() => {
      const val = Number(merged.message_retention_days);
      return Number.isFinite(val) && val >= 1 && val <= 180 ? val : 180;
    })(),
    service_areas: Array.isArray(merged.service_areas) ? merged.service_areas : [],
    stateful_menu_flow:
      merged.stateful_menu_flow && typeof merged.stateful_menu_flow === 'object'
        ? merged.stateful_menu_flow
        : null,
  };
}

export { DAY_KEYS, DAY_LABELS, DEFAULT_SETTINGS, buildDefaultStatefulMenuFlow, normalizeSettings };
