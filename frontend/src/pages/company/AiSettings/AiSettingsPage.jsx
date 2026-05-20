import { useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import Button from '@/components/ui/Button/Button.jsx';
import Card from '@/components/ui/Card/Card.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import {
  CheckboxField,
  Field,
  SelectInput,
  TextAreaInput,
  TextInput,
} from '@/components/ui/FormControls/FormControls.jsx';
import Notice from '@/components/ui/Notice/Notice.jsx';
import PageHeader from '@/components/ui/PageHeader/PageHeader.jsx';
import ErrorMessage from '@/components/ui/ErrorMessage/ErrorMessage.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
import usePageData from '@/hooks/usePageData';
import useAuth from '@/hooks/useAuth';
import useLogout from '@/hooks/useLogout';
import useAdminCompanySelector from '@/hooks/useAdminCompanySelector';
import useAiSettings from './hooks/useAiSettings';
import api from '@/services/api';

const SYSTEM_PROMPT_MAX = 2000;

const WIZARD_STEPS = [
  {
    id: 'activation',
    number: 1,
    title: 'Ativacao',
    intro: 'Defina onde a IA pode atuar. Nada muda no bot se a IA da empresa estiver desligada.',
  },
  {
    id: 'behavior',
    number: 2,
    title: 'Comportamento',
    intro: 'Oriente a forma de falar da IA com regras simples e exemplos da empresa.',
  },
  {
    id: 'chatbot',
    number: 3,
    title: 'IA no bot',
    intro: 'Configure quando a IA analisa mensagens do WhatsApp e quando ela pode responder.',
  },
  {
    id: 'limits',
    number: 4,
    title: 'Limites',
    intro: 'Controle consumo, modelo e limites tecnicos para evitar custo e respostas longas demais.',
  },
  {
    id: 'preview',
    number: 5,
    title: 'Preview e teste',
    intro: 'Revise o impacto antes de salvar e teste uma mensagem com a configuracao atual.',
  },
];

const CHATBOT_MODE_OPTIONS = [
  {
    value: 'disabled',
    label: 'Desativado',
    description: 'A IA nao interfere no bot.',
  },
  {
    value: 'always',
    label: 'Sempre analisar',
    description: 'Toda mensagem passa pela IA, respeitando menu e fluxos configurados.',
  },
  {
    value: 'fallback',
    label: 'Somente quando o bot travar',
    description: 'A IA atua quando o bot deterministico nao resolver sozinho.',
  },
  {
    value: 'outside_business_hours',
    label: 'Fora do expediente',
    description: 'A IA atua apenas fora dos horarios configurados no bot.',
  },
];

const TONE_OPTIONS = [
  ['formal', 'Formal'],
  ['casual', 'Proximo e simples'],
  ['tecnico', 'Tecnico'],
];

const LANGUAGE_OPTIONS = [
  ['pt-BR', 'Portugues (Brasil)'],
  ['en-US', 'Ingles (EUA)'],
  ['es-ES', 'Espanhol'],
];

const FORMALITY_OPTIONS = [
  ['baixa', 'Baixa'],
  ['media', 'Media'],
  ['alta', 'Alta'],
];

function toNumberOrNull(value) {
  if (value === '' || value === null || value === undefined) {
    return null;
  }

  const parsed = Number(value);

  return Number.isFinite(parsed) ? parsed : value;
}

function toIntegerOrNull(value) {
  const normalized = toNumberOrNull(value);

  return typeof normalized === 'number' ? Math.trunc(normalized) : normalized;
}

function testNumbersToText(value) {
  return Array.isArray(value) ? value.join('\n') : '';
}

function parseTestNumbers(value) {
  return String(value ?? '')
    .split(/[\s,;]+/)
    .map((item) => item.trim())
    .filter(Boolean)
    .filter((item, index, list) => list.indexOf(item) === index);
}

function rulesToText(value) {
  return Array.isArray(value) ? value.join('\n') : '';
}

function parseRules(value) {
  return String(value ?? '')
    .split(/\r?\n/)
    .map((item) => item.trim())
    .filter(Boolean)
    .slice(0, 50);
}

function isNumberBetween(value, min, max) {
  return typeof value === 'number' && Number.isFinite(value) && value >= min && value <= max;
}

function isPositiveInteger(value) {
  return Number.isInteger(value) && value > 0;
}

function validateAiSettings(settings) {
  if (!settings) {
    return [];
  }

  const errors = [];
  const chatbotMode = settings.ai_chatbot_mode || 'disabled';
  const threshold = Number(settings.ai_chatbot_confidence_threshold ?? 0.75);
  const handoffLimit = Number(settings.ai_chatbot_handoff_repeat_limit ?? 2);
  const monthlyLimit = settings.ai_usage_limit_monthly;
  const temperature = settings.ai_temperature;
  const maxContext = settings.ai_max_context_messages;
  const maxTokens = settings.ai_max_response_tokens;
  const testNumbers = Array.isArray(settings.ai_chatbot_test_numbers)
    ? settings.ai_chatbot_test_numbers
    : [];

  if (settings.ai_chatbot_enabled && !settings.ai_enabled) {
    errors.push('Para usar IA no bot, ative primeiro a IA da empresa.');
  }

  if (settings.ai_chatbot_enabled && chatbotMode === 'disabled') {
    errors.push('Escolha um modo de IA no bot diferente de "Desativado" ou desligue a IA no bot.');
  }

  if (settings.ai_chatbot_auto_reply_enabled && (!settings.ai_chatbot_enabled || chatbotMode === 'disabled')) {
    errors.push('Resposta automatica da IA so pode ficar ligada quando a IA no bot estiver ativa.');
  }

  if (settings.ai_chatbot_sandbox_enabled && testNumbers.length === 0) {
    errors.push('Sandbox ligado precisa de pelo menos um numero de teste.');
  }

  if (!isNumberBetween(threshold, 0, 1)) {
    errors.push('Confianca minima precisa ficar entre 0 e 1.');
  }

  if (!Number.isInteger(handoffLimit) || handoffLimit < 1 || handoffLimit > 10) {
    errors.push('Limite de tentativas antes do humano precisa ficar entre 1 e 10.');
  }

  if (settings.ai_usage_enabled && monthlyLimit !== null && monthlyLimit !== undefined && !isPositiveInteger(Number(monthlyLimit))) {
    errors.push('Limite mensal precisa ser vazio ou maior que zero.');
  }

  if (maxContext !== null && maxContext !== undefined && !isNumberBetween(Number(maxContext), 10, 20)) {
    errors.push('Mensagens de contexto precisam ficar entre 10 e 20.');
  }

  if (temperature !== null && temperature !== undefined && !isNumberBetween(Number(temperature), 0, 2)) {
    errors.push('Temperatura precisa ficar entre 0 e 2.');
  }

  if (maxTokens !== null && maxTokens !== undefined && !isNumberBetween(Number(maxTokens), 64, 4096)) {
    errors.push('Limite de tokens precisa ficar entre 64 e 4096.');
  }

  if ((settings.ai_system_prompt ?? '').length > SYSTEM_PROMPT_MAX) {
    errors.push(`Prompt do sistema deve ter ate ${SYSTEM_PROMPT_MAX} caracteres.`);
  }

  return errors;
}

function modeLabel(mode) {
  return CHATBOT_MODE_OPTIONS.find((item) => item.value === mode)?.label ?? 'Desativado';
}

function formatPercent(value) {
  const numeric = Number(value ?? 0);

  return `${Math.round(numeric * 100)}%`;
}

function Stepper({ activeStepId, onSelect }) {
  return (
    <nav className="grid grid-cols-1 gap-2 md:grid-cols-5" aria-label="Passos da configuracao de IA">
      {WIZARD_STEPS.map((step) => {
        const active = step.id === activeStepId;

        return (
          <button
            key={step.id}
            type="button"
            onClick={() => onSelect(step.id)}
            className={[
              'rounded-2xl border p-3 text-left transition',
              active
                ? 'border-[var(--ui-accent)] bg-[var(--ui-accent-soft)] shadow-sm'
                : 'border-[var(--ui-border)] bg-[var(--ui-surface)] hover:border-[var(--ui-accent)]',
            ].join(' ')}
          >
            <span className="mb-2 inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--ui-text)] text-xs font-semibold text-white">
              {step.number}
            </span>
            <span className="block text-sm font-semibold text-[var(--ui-text)]">{step.title}</span>
            <span className="mt-1 block text-xs text-[var(--ui-text-muted)]">{step.intro}</span>
          </button>
        );
      })}
    </nav>
  );
}

function StepCard({ stepId, children }) {
  const step = WIZARD_STEPS.find((item) => item.id === stepId) ?? WIZARD_STEPS[0];

  return (
    <Card className="space-y-5">
      <header className="flex items-start gap-3">
        <span className="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--ui-accent)] text-sm font-semibold text-white">
          {step.number}
        </span>
        <div>
          <h2 className="text-lg font-semibold text-[var(--ui-text)]">{step.title}</h2>
          <p className="mt-1 text-sm text-[var(--ui-text-muted)]">{step.intro}</p>
        </div>
      </header>
      {children}
    </Card>
  );
}

function HelpBox({ title, children }) {
  return (
    <div className="rounded-xl border border-[var(--ui-border)] bg-[var(--ui-surface-elevated)] p-3">
      <p className="text-sm font-semibold text-[var(--ui-text)]">{title}</p>
      <div className="mt-1 text-sm text-[var(--ui-text-muted)]">{children}</div>
    </div>
  );
}

function ModeCard({ option, checked, onChange }) {
  return (
    <label
      className={[
        'block cursor-pointer rounded-xl border p-3 transition',
        checked
          ? 'border-[var(--ui-accent)] bg-[var(--ui-accent-soft)]'
          : 'border-[var(--ui-border)] bg-[var(--ui-surface)] hover:border-[var(--ui-accent)]',
      ].join(' ')}
    >
      <span className="flex items-start gap-3">
        <input
          type="radio"
          name="ai_chatbot_mode"
          checked={checked}
          onChange={onChange}
          className="mt-1 h-4 w-4 border-[var(--ui-border)] text-[var(--ui-accent)] focus:ring-[var(--ui-ring)]"
        />
        <span>
          <span className="block text-sm font-semibold text-[var(--ui-text)]">{option.label}</span>
          <span className="mt-1 block text-xs text-[var(--ui-text-muted)]">{option.description}</span>
        </span>
      </span>
    </label>
  );
}

function ImpactPreview({ settings, companyName, errors }) {
  const chatbotEnabled = Boolean(settings?.ai_enabled && settings?.ai_chatbot_enabled);
  const mode = settings?.ai_chatbot_mode ?? 'disabled';

  const items = [
    {
      label: 'Empresa',
      value: companyName,
    },
    {
      label: 'IA geral',
      value: settings?.ai_enabled ? 'Ativa' : 'Desligada',
    },
    {
      label: 'IA no bot',
      value: chatbotEnabled && mode !== 'disabled' ? modeLabel(mode) : 'Nao atua no WhatsApp',
    },
    {
      label: 'Resposta automatica',
      value: settings?.ai_chatbot_auto_reply_enabled ? 'Pode responder quando autorizada' : 'Apenas roteia/analisa',
    },
    {
      label: 'Confianca minima',
      value: formatPercent(settings?.ai_chatbot_confidence_threshold ?? 0.75),
    },
    {
      label: 'Handoff por travamento',
      value: `${settings?.ai_chatbot_handoff_repeat_limit ?? 2} tentativa(s)`,
    },
  ];

  return (
    <Card className="space-y-3">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--ui-text-subtle)]">Preview</p>
        <h2 className="text-base font-semibold text-[var(--ui-text)]">Impacto no atendimento</h2>
        <p className="mt-1 text-sm text-[var(--ui-text-muted)]">
          Esta previa mostra como a configuracao salva vai afetar o bot real.
        </p>
      </div>

      <dl className="space-y-2">
        {items.map((item) => (
          <div key={item.label} className="rounded-lg border border-[var(--ui-border)] p-2">
            <dt className="text-xs text-[var(--ui-text-subtle)]">{item.label}</dt>
            <dd className="text-sm font-medium text-[var(--ui-text)]">{item.value}</dd>
          </div>
        ))}
      </dl>

      {errors.length > 0 ? (
        <Notice tone="danger">
          Existem ajustes invalidos. Corrija antes de salvar.
        </Notice>
      ) : (
        <Notice tone="success">
          Configuracao pronta para salvar.
        </Notice>
      )}
    </Card>
  );
}

function ValidationPanel({ errors, visible }) {
  if (!visible || errors.length === 0) {
    return null;
  }

  return (
    <Notice tone="danger" className="mb-4">
      Corrija antes de salvar: {errors.join(' ')}
    </Notice>
  );
}

function AiSettingsPage() {
  const { data, loading: meLoading, error: meError } = usePageData('/me');
  const { user: authUser } = useAuth();
  const { logout } = useLogout();
  const isAdmin = authUser?.role === 'system_admin';

  const { companies: selectorCompanies, selectedCompanyId, setSelectedCompanyId } = useAdminCompanySelector({ isAdmin });

  const canManageUsers = Boolean(
    data?.user?.can_manage_users ||
    (data?.user?.role === 'company_admin' && data?.user?.company_id) ||
    data?.user?.role === 'system_admin'
  );

  const resolvedCompanyId = isAdmin ? selectedCompanyId : '';
  const [activeStepId, setActiveStepId] = useState(WIZARD_STEPS[0].id);
  const [saveAttempted, setSaveAttempted] = useState(false);

  const [sandboxMessage, setSandboxMessage] = useState('');
  const [sandboxIncludeRag, setSandboxIncludeRag] = useState(false);
  const [sandboxBusy, setSandboxBusy] = useState(false);
  const [sandboxResult, setSandboxResult] = useState(null);
  const [sandboxError, setSandboxError] = useState('');

  const handleSandboxTest = async () => {
    if (!sandboxMessage.trim()) return;
    setSandboxBusy(true);
    setSandboxResult(null);
    setSandboxError('');
    try {
      const params = isAdmin && resolvedCompanyId ? { company_id: resolvedCompanyId } : {};
      const response = await api.post('/minha-conta/ia/sandbox', {
        message: sandboxMessage.trim(),
        include_rag: sandboxIncludeRag,
        ...params,
      });
      setSandboxResult(response.data);
    } catch (err) {
      setSandboxError(err.response?.data?.message ?? 'Falha ao testar a IA.');
    } finally {
      setSandboxBusy(false);
    }
  };

  const {
    company,
    settings,
    users,
    loading,
    error,
    saving,
    canSave,
    permissionBusyById,
    updateField,
    saveSettingsChanges,
    toggleUserPermission,
  } = useAiSettings({ enabled: canManageUsers, companyId: resolvedCompanyId });

  const companyName = data?.user?.company_name ?? company?.name ?? 'Empresa';
  const layoutRole = isAdmin ? 'admin' : 'company';
  const usersRows = useMemo(() => users ?? [], [users]);
  const validationErrors = useMemo(() => validateAiSettings(settings), [settings]);
  const activeIndex = WIZARD_STEPS.findIndex((step) => step.id === activeStepId);
  const safeActiveIndex = activeIndex >= 0 ? activeIndex : 0;

  const goToPrevious = () => {
    setActiveStepId(WIZARD_STEPS[Math.max(0, safeActiveIndex - 1)].id);
  };

  const goToNext = () => {
    setActiveStepId(WIZARD_STEPS[Math.min(WIZARD_STEPS.length - 1, safeActiveIndex + 1)].id);
  };

  const handleSave = async (event) => {
    event.preventDefault();
    setSaveAttempted(true);

    if (validationErrors.length > 0) {
      return;
    }

    const saved = await saveSettingsChanges();
    if (saved) {
      setSaveAttempted(false);
    }
  };

  const updateNumberField = (key, value, integer = false) => {
    updateField(key, integer ? toIntegerOrNull(value) : toNumberOrNull(value));
  };

  if (meLoading) {
    return (
      <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
        <PageLoading rows={2} cards={1} />
      </Layout>
    );
  }

  if (meError || !data?.authenticated) {
    return (
      <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
        <ErrorMessage message="Nao foi possivel carregar as configuracoes de IA." />
      </Layout>
    );
  }

  if (!canManageUsers) {
    return (
      <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
        <p className="text-sm text-[var(--ui-text-muted)]">Acesso restrito ao administrador da empresa.</p>
      </Layout>
    );
  }

  if (loading || !settings) {
    return (
      <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
        <PageLoading rows={2} cards={1} />
      </Layout>
    );
  }

  if (error) {
    return (
      <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
        <ErrorMessage message={error} />
      </Layout>
    );
  }

  return (
    <Layout role={layoutRole} companyName={companyName} onLogout={logout}>
      <PageHeader
        title="Wizard de configuracao IA"
        subtitle="Configure a IA por empresa em passos simples, com validacao e preview do comportamento real."
        action={isAdmin && selectorCompanies.length > 0 ? (
          <select
            value={selectedCompanyId}
            onChange={(e) => setSelectedCompanyId(e.target.value)}
            aria-label="Selecionar empresa para configurar IA"
            className="rounded-lg border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2 text-sm text-[var(--ui-text)] outline-none focus:border-[var(--ui-accent)] focus:ring-2 focus:ring-[var(--ui-ring)]"
          >
            {selectorCompanies.map((c) => (
              <option key={c.id} value={String(c.id)}>{c.name}</option>
            ))}
          </select>
        ) : undefined}
      />

      <div className="space-y-4">
        <Stepper activeStepId={activeStepId} onSelect={setActiveStepId} />

        <ValidationPanel errors={validationErrors} visible={saveAttempted} />

        <form onSubmit={handleSave} className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
          <div className="space-y-4">
            {activeStepId === 'activation' && (
              <StepCard stepId="activation">
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <CheckboxField
                    checked={Boolean(settings.ai_enabled)}
                    onChange={(event) => updateField('ai_enabled', event.target.checked)}
                  >
                    IA ativa para esta empresa
                  </CheckboxField>

                  <CheckboxField
                    checked={Boolean(settings.ai_internal_chat_enabled)}
                    onChange={(event) => updateField('ai_internal_chat_enabled', event.target.checked)}
                  >
                    Chat interno com IA
                  </CheckboxField>

                  <CheckboxField
                    checked={Boolean(settings.ai_chatbot_enabled)}
                    onChange={(event) => updateField('ai_chatbot_enabled', event.target.checked)}
                  >
                    IA no bot do WhatsApp
                  </CheckboxField>

                  <CheckboxField
                    checked={Boolean(settings.ai_chatbot_auto_reply_enabled)}
                    onChange={(event) => updateField('ai_chatbot_auto_reply_enabled', event.target.checked)}
                  >
                    Permitir resposta automatica da IA
                  </CheckboxField>
                </div>

                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <HelpBox title="O que isso muda?">
                    IA ativa libera recursos de IA da empresa. IA no bot faz o motor analisar mensagens do WhatsApp sem inventar servicos fora do menu.
                  </HelpBox>
                  <HelpBox title="Recomendacao">
                    Comece com IA ativa, IA no bot ligada e resposta automatica desligada. Depois ative respostas automaticas quando o fluxo estiver validado.
                  </HelpBox>
                </div>
              </StepCard>
            )}

            {activeStepId === 'behavior' && (
              <StepCard stepId="behavior">
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <Field label="Persona" hint="Papel da IA no atendimento.">
                    <TextInput
                      type="text"
                      value={settings.ai_persona ?? ''}
                      onChange={(event) => updateField('ai_persona', event.target.value)}
                      placeholder="Ex.: Assistente de atendimento da clinica"
                    />
                  </Field>

                  <Field label="Tom de voz" hint="Como a IA deve soar para o cliente.">
                    <SelectInput
                      value={settings.ai_tone ?? 'casual'}
                      onChange={(event) => updateField('ai_tone', event.target.value)}
                    >
                      {TONE_OPTIONS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                      ))}
                    </SelectInput>
                  </Field>

                  <Field label="Idioma">
                    <SelectInput
                      value={settings.ai_language ?? 'pt-BR'}
                      onChange={(event) => updateField('ai_language', event.target.value)}
                    >
                      {LANGUAGE_OPTIONS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                      ))}
                    </SelectInput>
                  </Field>

                  <Field label="Formalidade">
                    <SelectInput
                      value={settings.ai_formality ?? 'media'}
                      onChange={(event) => updateField('ai_formality', event.target.value)}
                    >
                      {FORMALITY_OPTIONS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                      ))}
                    </SelectInput>
                  </Field>
                </div>

                <Field label="Prompt do sistema" hint="Regra principal da IA. Seja objetivo e diga o que ela nao pode fazer.">
                  <TextAreaInput
                    rows={6}
                    maxLength={SYSTEM_PROMPT_MAX}
                    value={settings.ai_system_prompt ?? ''}
                    onChange={(event) => updateField('ai_system_prompt', event.target.value)}
                    placeholder="Voce e um assistente da empresa. Responda de forma educada, curta e somente sobre servicos configurados."
                  />
                  <p className={`mt-1 text-right text-xs ${(settings.ai_system_prompt ?? '').length > SYSTEM_PROMPT_MAX * 0.9 ? 'text-amber-600' : 'text-[var(--ui-text-subtle)]'}`}>
                    {(settings.ai_system_prompt ?? '').length}/{SYSTEM_PROMPT_MAX}
                  </p>
                </Field>

                <Field label="Regras do chatbot IA" hint="Uma regra por linha. Ex.: Nao prometer desconto sem estar no menu.">
                  <TextAreaInput
                    rows={5}
                    value={rulesToText(settings.ai_chatbot_rules)}
                    onChange={(event) => updateField('ai_chatbot_rules', parseRules(event.target.value))}
                    placeholder={'Nao inventar servicos fora do menu\nSe nao entender, transferir para humano\nUsar linguagem simples'}
                  />
                </Field>
              </StepCard>
            )}

            {activeStepId === 'chatbot' && (
              <StepCard stepId="chatbot">
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  {CHATBOT_MODE_OPTIONS.map((option) => (
                    <ModeCard
                      key={option.value}
                      option={option}
                      checked={(settings.ai_chatbot_mode ?? 'disabled') === option.value}
                      onChange={() => updateField('ai_chatbot_mode', option.value)}
                    />
                  ))}
                </div>

                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <Field label="Confianca minima" hint="0.75 significa 75%. Abaixo disso o bot nao assume que entendeu.">
                    <TextInput
                      type="number"
                      min="0"
                      max="1"
                      step="0.05"
                      value={settings.ai_chatbot_confidence_threshold ?? 0.75}
                      onChange={(event) => updateNumberField('ai_chatbot_confidence_threshold', event.target.value)}
                    />
                  </Field>

                  <Field label="Tentativas antes do humano" hint="Quando o cliente insiste fora do fluxo, transfere depois desse limite.">
                    <TextInput
                      type="number"
                      min="1"
                      max="10"
                      value={settings.ai_chatbot_handoff_repeat_limit ?? 2}
                      onChange={(event) => updateNumberField('ai_chatbot_handoff_repeat_limit', event.target.value, true)}
                    />
                  </Field>
                </div>

                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <CheckboxField
                    checked={Boolean(settings.ai_chatbot_shadow_mode)}
                    onChange={(event) => updateField('ai_chatbot_shadow_mode', event.target.checked)}
                  >
                    Modo sombra: registrar decisao sem mudar resposta
                  </CheckboxField>

                  <CheckboxField
                    checked={Boolean(settings.ai_chatbot_sandbox_enabled)}
                    onChange={(event) => updateField('ai_chatbot_sandbox_enabled', event.target.checked)}
                  >
                    Sandbox: aplicar IA apenas em numeros de teste
                  </CheckboxField>
                </div>

                <Field label="Numeros de teste do sandbox" hint="Um numero por linha, com DDI/DDD quando possivel.">
                  <TextAreaInput
                    rows={4}
                    value={testNumbersToText(settings.ai_chatbot_test_numbers)}
                    onChange={(event) => updateField('ai_chatbot_test_numbers', parseTestNumbers(event.target.value))}
                    placeholder={'5511999999999\n5511888888888'}
                  />
                </Field>
              </StepCard>
            )}

            {activeStepId === 'limits' && (
              <StepCard stepId="limits">
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <CheckboxField
                    checked={Boolean(settings.ai_usage_enabled)}
                    onChange={(event) => updateField('ai_usage_enabled', event.target.checked)}
                  >
                    Controle de uso ativo
                  </CheckboxField>

                  <Field label="Limite mensal de uso" hint="Vazio = sem limite mensal definido.">
                    <TextInput
                      type="number"
                      min="1"
                      inputMode="numeric"
                      value={settings.ai_usage_limit_monthly ?? ''}
                      onChange={(event) => updateNumberField('ai_usage_limit_monthly', event.target.value, true)}
                      placeholder="Ex.: 1000"
                    />
                  </Field>

                  <Field label="Provider" hint="Opcional. Vazio usa o provider padrao do sistema.">
                    <TextInput
                      type="text"
                      value={settings.ai_provider ?? ''}
                      onChange={(event) => updateField('ai_provider', event.target.value)}
                      placeholder="Ex.: openai"
                    />
                  </Field>

                  <Field label="Modelo" hint="Opcional. Vazio usa o modelo padrao.">
                    <TextInput
                      type="text"
                      value={settings.ai_model ?? ''}
                      onChange={(event) => updateField('ai_model', event.target.value)}
                      placeholder="Ex.: gpt-4.1-mini"
                    />
                  </Field>

                  <Field label="Temperatura" hint="0 = mais conservador. 2 = mais criativo.">
                    <TextInput
                      type="number"
                      min="0"
                      max="2"
                      step="0.1"
                      value={settings.ai_temperature ?? ''}
                      onChange={(event) => updateNumberField('ai_temperature', event.target.value)}
                      placeholder="Ex.: 0.4"
                    />
                  </Field>

                  <Field label="Mensagens de contexto" hint="Entre 10 e 20.">
                    <TextInput
                      type="number"
                      min="10"
                      max="20"
                      value={settings.ai_max_context_messages ?? ''}
                      onChange={(event) => updateNumberField('ai_max_context_messages', event.target.value, true)}
                      placeholder="Ex.: 12"
                    />
                  </Field>

                  <Field label="Maximo de tokens da resposta" hint="Entre 64 e 4096.">
                    <TextInput
                      type="number"
                      min="64"
                      max="4096"
                      value={settings.ai_max_response_tokens ?? ''}
                      onChange={(event) => updateNumberField('ai_max_response_tokens', event.target.value, true)}
                      placeholder="Ex.: 1024"
                    />
                  </Field>
                </div>

                <Notice tone="info">
                  Se o limite mensal for atingido, a IA deixa de consumir ate o ciclo ser reiniciado.
                </Notice>
              </StepCard>
            )}

            {activeStepId === 'preview' && (
              <StepCard stepId="preview">
                <ImpactPreview settings={settings} companyName={companyName} errors={validationErrors} />

                <Card className="space-y-4">
                  <div>
                    <h3 className="text-base font-semibold text-[var(--ui-text)]">Testar IA</h3>
                    <p className="mt-1 text-sm text-[var(--ui-text-muted)]">
                      Envie uma mensagem de teste para verificar a configuracao antes de usar no atendimento real.
                    </p>
                  </div>

                  <Field label="Mensagem de teste">
                    <TextAreaInput
                      rows={3}
                      value={sandboxMessage}
                      onChange={(event) => setSandboxMessage(event.target.value)}
                      placeholder="Ex.: Quero marcar um horario amanha as 9h"
                      maxLength={2000}
                    />
                  </Field>

                  <CheckboxField
                    checked={sandboxIncludeRag}
                    onChange={(event) => setSandboxIncludeRag(event.target.checked)}
                  >
                    Usar base de conhecimento no teste
                  </CheckboxField>

                  <div>
                    <Button
                      type="button"
                      variant="secondary"
                      disabled={sandboxBusy || !sandboxMessage.trim()}
                      onClick={handleSandboxTest}
                    >
                      {sandboxBusy ? 'Testando...' : 'Testar IA'}
                    </Button>
                    {sandboxBusy ? (
                      <span className="ml-3 text-xs text-[var(--ui-text-muted)]" role="status" aria-live="polite">
                        Processando teste da IA...
                      </span>
                    ) : null}
                  </div>

                  {sandboxError && <ErrorMessage message={sandboxError} />}

                  {sandboxResult && (
                    <div className="space-y-3 border-t border-[var(--ui-border)] pt-4">
                      <div>
                        <p className="mb-1 text-xs font-semibold uppercase text-[var(--ui-text-subtle)]">Resposta da IA</p>
                        <p className="whitespace-pre-wrap rounded-lg border border-[var(--ui-border)] bg-[var(--ui-surface-elevated)] p-3 text-sm text-[var(--ui-text)]">
                          {sandboxResult.response}
                        </p>
                      </div>

                      <div className="flex flex-wrap gap-3 text-xs text-[var(--ui-text-muted)]">
                        <span><strong>Provider:</strong> {sandboxResult.provider ?? '-'}</span>
                        <span><strong>Modelo:</strong> {sandboxResult.model ?? '-'}</span>
                        {sandboxResult.tokens_used != null && <span><strong>Tokens:</strong> {sandboxResult.tokens_used}</span>}
                        {sandboxResult.confidence_score != null && (
                          <span><strong>Confianca:</strong> {formatPercent(sandboxResult.confidence_score)}</span>
                        )}
                      </div>
                    </div>
                  )}
                </Card>
              </StepCard>
            )}

            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[var(--ui-border)] bg-[var(--ui-surface)] p-3">
              <div className="flex gap-2">
                <Button type="button" variant="secondary" disabled={safeActiveIndex === 0} onClick={goToPrevious}>
                  Voltar
                </Button>
                <Button type="button" variant="secondary" disabled={safeActiveIndex === WIZARD_STEPS.length - 1} onClick={goToNext}>
                  Proximo passo
                </Button>
              </div>

              <div className="flex items-center gap-3">
                {saving ? (
                  <span className="text-xs text-[var(--ui-text-muted)]" role="status" aria-live="polite">
                    Salvando configuracoes...
                  </span>
                ) : null}
                <Button type="submit" variant="primary" disabled={!canSave || saving}>
                  {saving ? 'Salvando...' : 'Salvar configuracao'}
                </Button>
              </div>
            </div>
          </div>

          <aside className="xl:sticky xl:top-4 xl:self-start">
            <ImpactPreview settings={settings} companyName={companyName} errors={validationErrors} />
          </aside>
        </form>

        <Card>
          <h2 className="mb-3 text-base font-semibold text-[var(--ui-text)]">Permissoes dos usuarios</h2>

          {!usersRows.length ? (
            <EmptyState
              title="Nenhum usuario encontrado"
              subtitle="Assim que houver usuarios vinculados a empresa, as permissoes de IA aparecem aqui."
            />
          ) : (
            <div className="overflow-x-auto app-responsive-table-wrap">
              <table className="min-w-full text-sm app-responsive-table">
                <thead>
                  <tr className="border-b border-[var(--ui-border)] text-left text-[var(--ui-text-muted)]">
                    <th className="py-2 pr-3 font-medium">Nome</th>
                    <th className="py-2 pr-3 font-medium">E-mail</th>
                    <th className="py-2 pr-3 font-medium">Pode usar IA</th>
                  </tr>
                </thead>
                <tbody>
                  {usersRows.map((user) => {
                    const isBusy = Boolean(permissionBusyById[user.id]);
                    const isAgent = String(user.role ?? '').trim() === 'agent';
                    const checked = isAgent ? Boolean(user.can_use_ai) : true;

                    return (
                      <tr key={user.id} className="border-b border-[var(--ui-border)]">
                        <td data-label="Nome" className="py-2 pr-3 text-[var(--ui-text)]">{user.name || '-'}</td>
                        <td data-label="E-mail" className="break-all py-2 pr-3 text-[var(--ui-text-muted)]">{user.email || '-'}</td>
                        <td data-label="Pode usar IA" className="py-2 pr-3">
                          <label className="inline-flex flex-wrap items-center gap-2 text-[var(--ui-text)]">
                            <input
                              type="checkbox"
                              className="h-4 w-4 rounded border-[var(--ui-border)] text-[var(--ui-accent)] focus:ring-[var(--ui-ring)]"
                              checked={checked}
                              aria-label={`Permissao de IA para ${user.name || user.email || 'usuario'}`}
                              disabled={isBusy || !isAgent}
                              onChange={(event) => void toggleUserPermission(user.id, event.target.checked)}
                            />
                            <span className="break-words text-xs text-[var(--ui-text-muted)]">
                              {isBusy ? 'Salvando...' : isAgent ? 'Ativo' : 'Sempre ativo para administradores'}
                            </span>
                          </label>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </div>
    </Layout>
  );
}

export default AiSettingsPage;
