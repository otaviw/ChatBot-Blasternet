import { buildDefaultStatefulMenuFlow } from '../constants/botSettings';

function makeId(prefix = 'id') {
  return `${prefix}_${Math.random().toString(36).slice(2, 10)}`;
}

function toActionEditor(action) {
  const rawKind = action?.kind;
  const kind = rawKind === 'handoff' || rawKind === 'appointments_start' || rawKind === 'appointments_cancel' ? rawKind : 'go_to';

  return {
    id: makeId('action'),
    kind,
    reply_text: String(action?.reply_text ?? ''),
    flow: String(action?.flow ?? ''),
    step: String(action?.step ?? ''),
    target_area_name: String(action?.target_area_name ?? ''),
  };
}

function createEmptyOption(index = 1) {
  return {
    id: makeId('opt'),
    key: String(index),
    label: '',
    action: toActionEditor({ kind: 'go_to' }),
  };
}

function createEmptyStep(type = 'numeric_menu') {
  if (type === 'free_text') {
    return {
      id: makeId('step'),
      flow: makeId('flow'),
      step: makeId('step'),
      type: 'free_text',
      reply_text: '',
      empty_input_reply_text: '',
      on_text: toActionEditor({ kind: 'handoff' }),
      options: [],
      invalid_option_text: '',
    };
  }

  return {
    id: makeId('step'),
    flow: makeId('flow'),
    step: makeId('step'),
    type: 'numeric_menu',
    reply_text: '',
    empty_input_reply_text: '',
    on_text: toActionEditor({ kind: 'handoff' }),
    options: [createEmptyOption(1)],
    invalid_option_text: '',
  };
}

function parseStateKey(stateKey) {
  const raw = String(stateKey ?? '').trim();
  if (!raw.includes('.')) {
    return { flow: raw, step: '' };
  }

  const [flow, ...rest] = raw.split('.');

  return {
    flow: String(flow ?? '').trim(),
    step: rest.join('.').trim(),
  };
}

function toStepEditor(stateKey, stepDefinition) {
  const parsed = parseStateKey(stateKey);
  const type = stepDefinition?.type === 'free_text' ? 'free_text' : 'numeric_menu';

  if (type === 'free_text') {
    return {
      id: makeId('step'),
      flow: parsed.flow,
      step: parsed.step,
      type: 'free_text',
      reply_text: String(stepDefinition?.reply_text ?? ''),
      empty_input_reply_text: String(stepDefinition?.empty_input_reply_text ?? ''),
      on_text: toActionEditor(stepDefinition?.on_text ?? {}),
      options: [],
      invalid_option_text: '',
    };
  }

  const options = Object.entries(stepDefinition?.options ?? {}).map(([optionKey, optionDefinition]) => ({
    id: makeId('opt'),
    key: String(optionKey ?? ''),
    label: String(optionDefinition?.label ?? ''),
    action: toActionEditor(optionDefinition?.action ?? {}),
  }));

  return {
    id: makeId('step'),
    flow: parsed.flow,
    step: parsed.step,
    type: 'numeric_menu',
    reply_text: String(stepDefinition?.reply_text ?? ''),
    empty_input_reply_text: '',
    on_text: toActionEditor({ kind: 'handoff' }),
    options: options.length ? options : [createEmptyOption(1)],
    invalid_option_text: String(stepDefinition?.invalid_option_text ?? ''),
  };
}

function normalizeCommands(commands) {
  const list = Array.isArray(commands) ? commands : ['#', 'menu'];

  const seen = new Set();
  const normalized = [];
  for (const command of list) {
    const value = String(command ?? '').trim();
    if (!value) continue;
    const token = value.toLowerCase();
    if (seen.has(token)) continue;
    seen.add(token);
    normalized.push(value);
  }

  return normalized.length ? normalized : ['#', 'menu'];
}

function firstStep(steps) {
  if (!Array.isArray(steps) || !steps.length) {
    return { flow: 'main', step: 'menu' };
  }

  return {
    flow: String(steps[0].flow ?? 'main'),
    step: String(steps[0].step ?? 'menu'),
  };
}

function statefulMenuFlowToEditor(flow, welcomeMessage = 'Ola! O que voce precisa?') {
  const source =
    flow && typeof flow === 'object' && !Array.isArray(flow)
      ? flow
      : buildDefaultStatefulMenuFlow(welcomeMessage);

  const rawSteps = Object.entries(source?.steps ?? {});
  const steps = rawSteps.map(([stateKey, stepDefinition]) => toStepEditor(stateKey, stepDefinition));

  if (!steps.length) {
    const fallback = buildDefaultStatefulMenuFlow(welcomeMessage);
    return statefulMenuFlowToEditor(fallback, welcomeMessage);
  }

  const initialFlow = String(source?.initial?.flow ?? '').trim();
  const initialStep = String(source?.initial?.step ?? '').trim();
  const initial = initialFlow && initialStep ? { flow: initialFlow, step: initialStep } : firstStep(steps);

  return {
    commands: normalizeCommands(source?.commands),
    initial_flow: initial.flow,
    initial_step: initial.step,
    steps,
  };
}

function fromActionEditor(action) {
  const rawKind = action?.kind;
  const kind = rawKind === 'handoff' || rawKind === 'appointments_start' || rawKind === 'appointments_cancel' ? rawKind : 'go_to';
  const payload = { kind };

  const reply = String(action?.reply_text ?? '').trim();
  if (reply) {
    payload.reply_text = reply;
  }

  if (kind === 'handoff' || kind === 'appointments_start') {
    payload.target_area_name = String(action?.target_area_name ?? '').trim();
  } else if (kind !== 'appointments_cancel') {
    payload.flow = String(action?.flow ?? '').trim();
    payload.step = String(action?.step ?? '').trim();
  }

  return payload;
}

function editorToStatefulMenuFlow(editor) {
  const steps = {};

  for (const rawStep of editor?.steps ?? []) {
    const flow = String(rawStep?.flow ?? '').trim();
    const step = String(rawStep?.step ?? '').trim();
    if (!flow || !step) continue;

    const key = `${flow}.${step}`;
    if (rawStep?.type === 'free_text') {
      const stepPayload = {
        type: 'free_text',
        reply_text: String(rawStep?.reply_text ?? '').trim(),
        on_text: fromActionEditor(rawStep?.on_text),
      };
      const emptyReply = String(rawStep?.empty_input_reply_text ?? '').trim();
      if (emptyReply) {
        stepPayload.empty_input_reply_text = emptyReply;
      }
      steps[key] = stepPayload;
      continue;
    }

    const options = {};
    for (const rawOption of rawStep?.options ?? []) {
      const optionKey = String(rawOption?.key ?? '').trim();
      if (!optionKey) continue;

      options[optionKey] = {
        label: String(rawOption?.label ?? '').trim(),
        action: fromActionEditor(rawOption?.action),
      };
    }

    const stepPayload = {
      type: 'numeric_menu',
      reply_text: String(rawStep?.reply_text ?? '').trim(),
      options,
    };
    const invalidText = String(rawStep?.invalid_option_text ?? '').trim();
    if (invalidText) {
      stepPayload.invalid_option_text = invalidText;
    }
    steps[key] = stepPayload;
  }

  const initialFlow = String(editor?.initial_flow ?? '').trim();
  const initialStep = String(editor?.initial_step ?? '').trim();
  const fallbackInitial = firstStep(editor?.steps ?? []);

  return {
    commands: normalizeCommands(editor?.commands),
    initial: {
      flow: initialFlow || fallbackInitial.flow,
      step: initialStep || fallbackInitial.step,
    },
    steps,
  };
}

function validateAction(action, stepLabel) {
  const errors = [];
  const rawKind = action?.kind;
  const kind = rawKind === 'handoff' || rawKind === 'appointments_start' || rawKind === 'appointments_cancel' ? rawKind : 'go_to';

  if (kind === 'appointments_start' || kind === 'appointments_cancel') {
    // Não requer campos extras
    return errors;
  }

  if (kind === 'handoff') {
    const target = String(action?.target_area_name ?? '').trim();
    if (!target) {
      errors.push(`${stepLabel}: informe a área de handoff.`);
    }

    return errors;
  }

  const flow = String(action?.flow ?? '').trim();
  const step = String(action?.step ?? '').trim();
  if (!flow || !step) {
    errors.push(`${stepLabel}: informe flow e step do destino.`);
  }

  return errors;
}

function validateStatefulMenuEditor(editor) {
  const errors = [];
  const steps = Array.isArray(editor?.steps) ? editor.steps : [];
  if (!steps.length) {
    errors.push('Adicione pelo menos um passo no menu stateful.');
    return errors;
  }

  const keys = new Set();
  for (let index = 0; index < steps.length; index += 1) {
    const step = steps[index];
    const label = `Passo ${index + 1}`;
    const flow = String(step?.flow ?? '').trim();
    const stepName = String(step?.step ?? '').trim();
    const reply = String(step?.reply_text ?? '').trim();

    if (!flow || !stepName) {
      errors.push(`${label}: flow e step são obrigatórios.`);
      continue;
    }

    const key = `${flow}.${stepName}`;
    if (keys.has(key)) {
      errors.push(`${label}: o identificador ${key} está duplicado.`);
      continue;
    }
    keys.add(key);

    if (!reply) {
      errors.push(`${label}: reply_text é obrigatório.`);
    }

    if (step?.type === 'free_text') {
      errors.push(...validateAction(step?.on_text, `${label} (on_text)`));
      continue;
    }

    const options = Array.isArray(step?.options) ? step.options : [];
    if (!options.length) {
      errors.push(`${label}: adicione pelo menos uma opção.`);
      continue;
    }

    const optionKeys = new Set();
    for (let optionIndex = 0; optionIndex < options.length; optionIndex += 1) {
      const option = options[optionIndex];
      const optionLabel = `${label} / opção ${optionIndex + 1}`;
      const optionKey = String(option?.key ?? '').trim();
      if (!optionKey || !/^\d+$/.test(optionKey)) {
        errors.push(`${optionLabel}: chave deve ser numerica (ex.: 1, 2, 3).`);
      } else if (optionKeys.has(optionKey)) {
        errors.push(`${optionLabel}: chave ${optionKey} duplicada no mesmo passo.`);
      } else {
        optionKeys.add(optionKey);
      }

      if (!String(option?.label ?? '').trim()) {
        errors.push(`${optionLabel}: label obrigatória.`);
      }

      errors.push(...validateAction(option?.action, `${optionLabel} (ação)`));
    }
  }

  const initialFlow = String(editor?.initial_flow ?? '').trim();
  const initialStep = String(editor?.initial_step ?? '').trim();
  if (!initialFlow || !initialStep) {
    errors.push('Defina o passo inicial do menu.');
  } else if (!keys.has(`${initialFlow}.${initialStep}`)) {
    errors.push('Passo inicial precisa existir na lista de passos.');
  }

  for (let index = 0; index < steps.length; index += 1) {
    const step = steps[index];
    const label = `Passo ${index + 1}`;

    if (step?.type === 'free_text') {
      if (step?.on_text?.kind === 'go_to') {
        const targetFlow = String(step?.on_text?.flow ?? '').trim();
        const targetStep = String(step?.on_text?.step ?? '').trim();
        if (!targetFlow || !targetStep) {
          continue;
        }
        const target = `${targetFlow}.${targetStep}`;
        if (!keys.has(target)) {
          errors.push(`${label}: destino ${target} não existe.`);
        }
      }
      continue;
    }

    for (let optionIndex = 0; optionIndex < (step?.options ?? []).length; optionIndex += 1) {
      const option = step.options[optionIndex];
      if (option?.action?.kind !== 'go_to') {
        continue;
      }

      const targetFlow = String(option?.action?.flow ?? '').trim();
      const targetStep = String(option?.action?.step ?? '').trim();
      if (!targetFlow || !targetStep) {
        continue;
      }
      const target = `${targetFlow}.${targetStep}`;
      if (!keys.has(target)) {
        errors.push(`${label} / opção ${optionIndex + 1}: destino ${target} não existe.`);
      }
    }
  }

  return errors;
}

export {
  createEmptyOption,
  createEmptyStep,
  editorToStatefulMenuFlow,
  statefulMenuFlowToEditor,
  validateStatefulMenuEditor,
};
