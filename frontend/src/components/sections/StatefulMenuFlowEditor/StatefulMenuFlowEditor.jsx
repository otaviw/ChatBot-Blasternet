import { useEffect, useMemo, useState } from 'react';
import './StatefulMenuFlowEditor.css';
import { createEmptyOption, createEmptyStep } from '@/services/statefulMenuFlow';

function normalizeStepKey(flow, step) {
  const safeFlow = String(flow ?? '').trim();
  const safeStep = String(step ?? '').trim();

  if (!safeFlow || !safeStep) {
    return '';
  }

  return `${safeFlow}.${safeStep}`;
}

function getStepDisplayName(step, fallbackIndex = null) {
  const customName = String(step?.display_name ?? '').trim();
  if (customName) {
    return customName;
  }

  const flow = String(step?.flow ?? '').trim();
  const stepName = String(step?.step ?? '').trim();

  if (flow && stepName) {
    return `${flow}.${stepName}`;
  }

  if (flow) {
    return flow;
  }

  if (stepName) {
    return stepName;
  }

  if (fallbackIndex !== null) {
    return `Bloco ${fallbackIndex + 1}`;
  }

  return 'Bloco sem nome';
}

function getActionSummary(action, stepOptions = []) {
  if (!action) {
    return 'Ação não configurada';
  }

  if (action.kind === 'handoff') {
    return `Transferir para: ${action.target_area_name || 'área não definida'}`;
  }

  const targetKey = normalizeStepKey(action.flow, action.step);
  const targetStep = stepOptions.find((item) => item.value === targetKey);

  if (targetStep) {
    return `Abrir bloco: ${targetStep.label}`;
  }

  if (targetKey) {
    return `Abrir bloco: ${targetKey}`;
  }

  return 'Destino não configurado';
}

function ActionEditor({
  action,
  onChange,
  serviceAreas = [],
  stepOptions = [],
  onGoToTarget,
}) {
  const safeAction = action ?? {
    kind: 'go_to',
    reply_text: '',
    flow: '',
    step: '',
    target_area_name: '',
  };

  const currentTargetValue = normalizeStepKey(safeAction.flow, safeAction.step);
  const currentTargetStep = stepOptions.find((item) => item.value === currentTargetValue);

  const changeKind = (value) => {
    if (value === 'handoff') {
      onChange({
        ...safeAction,
        kind: 'handoff',
        target_area_name: safeAction.target_area_name || '',
      });
      return;
    }

    onChange({
      ...safeAction,
      kind: 'go_to',
      flow: safeAction.flow || '',
      step: safeAction.step || '',
    });
  };

  const changeTargetStep = (value) => {
    const [flow, ...rest] = String(value ?? '').split('.');
    onChange({
      ...safeAction,
      flow: flow ?? '',
      step: rest.join('.'),
    });
  };

  return (
    <div className="stateful-editor-card stateful-editor-card--soft">
      <div className="stateful-editor-card-header">
        <div>
          <h5 className="stateful-editor-card-title">Ação da seleção</h5>
          <p className="stateful-editor-card-subtitle">
            {getActionSummary(safeAction, stepOptions)}
          </p>
        </div>
      </div>

      <div className="stateful-form-grid">
        <label className="stateful-field">
          <span className="stateful-field-label">O que deve acontecer</span>
          <select
            value={safeAction.kind}
            onChange={(e) => changeKind(e.target.value)}
            className="stateful-input"
          >
            <option value="go_to">Abrir outro bloco</option>
            <option value="handoff">Transferir para uma área</option>
          </select>
        </label>

        <label className="stateful-field">
          <span className="stateful-field-label">Mensagem enviada após essa ação</span>
          <input
            type="text"
            value={safeAction.reply_text || ''}
            onChange={(e) => onChange({ ...safeAction, reply_text: e.target.value })}
            placeholder="Ex.: Certo, vou te encaminhar."
            className="stateful-input"
          />
        </label>
      </div>

      {safeAction.kind === 'handoff' ? (
        <label className="stateful-field">
          <span className="stateful-field-label">Área de atendimento</span>
          <select
            value={safeAction.target_area_name || ''}
            onChange={(e) => onChange({ ...safeAction, target_area_name: e.target.value })}
            className="stateful-input"
          >
            <option value="">
              {(serviceAreas ?? []).length ? 'Selecione uma área' : 'Nenhuma área cadastrada'}
            </option>
            {(serviceAreas ?? []).map((area) => (
              <option key={String(area)} value={String(area)}>
                {String(area)}
              </option>
            ))}
          </select>
        </label>
      ) : (
        <div className="space-y-3">
          <label className="stateful-field">
            <span className="stateful-field-label">Bloco de destino</span>
            <select
              value={currentTargetValue}
              onChange={(e) => changeTargetStep(e.target.value)}
              className="stateful-input"
            >
              <option value="">Selecione um bloco</option>
              {stepOptions.map((stepItem) => (
                <option key={stepItem.id} value={stepItem.value}>
                  {stepItem.label}
                </option>
              ))}
            </select>
          </label>

          {currentTargetStep && (
            <button
              type="button"
              onClick={() => onGoToTarget?.(currentTargetStep.id)}
              className="stateful-btn stateful-btn-secondary"
            >
              Abrir bloco de destino
            </button>
          )}
        </div>
      )}
    </div>
  );
}

function StepListItem({
  step,
  index,
  isActive,
  isInitial,
  isDisconnected,
  onSelect,
}) {
  const name = getStepDisplayName(step, index);
  const typeLabel = step.type === 'numeric_menu' ? 'Menu' : 'Pergunta aberta';
  const meta =
    step.type === 'numeric_menu'
      ? `${step.options?.length ?? 0} opção(ões)`
      : 'Texto livre';

  return (
    <button
      type="button"
      onClick={onSelect}
      className={`stateful-step-list-item ${isActive ? 'stateful-step-list-item--active' : ''}`}
    >
      <div className="stateful-step-list-item-top">
        <div>
          <p className="stateful-step-list-item-title">{name}</p>
          <p className="stateful-step-list-item-meta">
            {typeLabel} • {meta}
          </p>
        </div>

        <div className="stateful-step-list-badges">
          {isInitial && <span className="stateful-badge stateful-badge--success">Inicial</span>}
          {isDisconnected && <span className="stateful-badge">Solto</span>}
        </div>
      </div>
    </button>
  );
}

function StatefulMenuFlowEditor({ value, onChange, serviceAreas = [] }) {
  const editor = value ?? {
    commands: ['#', 'menu'],
    initial_flow: 'main',
    initial_step: 'menu',
    steps: [],
  };

  const stepOptions = useMemo(
    () =>
      editor.steps.map((step, index) => {
        const flow = String(step.flow ?? '').trim();
        const stepName = String(step.step ?? '').trim();

        return {
          id: step.id,
          value: `${flow}.${stepName}`,
          label: getStepDisplayName(step, index),
        };
      }),
    [editor.steps]
  );

  const stepIndexById = useMemo(
    () => new Map(editor.steps.map((step, index) => [step.id, index])),
    [editor.steps]
  );

  const stepByKey = useMemo(
    () =>
      new Map(
        editor.steps
          .map((step) => [normalizeStepKey(step.flow, step.step), step])
          .filter(([key]) => Boolean(key))
      ),
    [editor.steps]
  );

  const initialStepKey = normalizeStepKey(editor.initial_flow, editor.initial_step);

  const setEditor = (next) => {
    onChange(next);
  };

  const updateCommands = (nextCommands) => {
    setEditor({ ...editor, commands: nextCommands });
  };

  const updateStep = (stepId, updater) => {
    setEditor({
      ...editor,
      steps: editor.steps.map((step) => (step.id === stepId ? updater(step) : step)),
    });
  };

  const removeStep = (stepId) => {
    const nextSteps = editor.steps.filter((step) => step.id !== stepId);

    const hasInitial = nextSteps.some(
      (step) =>
        String(step.flow ?? '').trim() === String(editor.initial_flow ?? '').trim()
        && String(step.step ?? '').trim() === String(editor.initial_step ?? '').trim()
    );

    setEditor({
      ...editor,
      steps: nextSteps,
      initial_flow: hasInitial ? editor.initial_flow : String(nextSteps[0]?.flow ?? ''),
      initial_step: hasInitial ? editor.initial_step : String(nextSteps[0]?.step ?? ''),
    });
  };

  const addStep = (type) => {
    const createdStep = createEmptyStep(type);

    const nextStep = {
      ...createdStep,
      display_name: type === 'free_text' ? 'Nova pergunta aberta' : 'Novo menu',
    };

    setEditor({
      ...editor,
      steps: [...editor.steps, nextStep],
    });

    setSelectedStepId(nextStep.id);
    setSelectedOptionId(null);
  };

  const addCommand = () => {
    updateCommands([...(editor.commands ?? []), '']);
  };

  const removeCommand = (index) => {
    updateCommands((editor.commands ?? []).filter((_, i) => i !== index));
  };

  const updateCommand = (index, value) => {
    updateCommands((editor.commands ?? []).map((item, i) => (i === index ? value : item)));
  };

  const addOption = (stepId) => {
    const currentStep = editor.steps.find((step) => step.id === stepId);
    const nextOption = createEmptyOption((currentStep?.options?.length ?? 0) + 1);

    updateStep(stepId, (current) => ({
      ...current,
      options: [...(current.options ?? []), nextOption],
    }));

    setSelectedOptionId(nextOption.id);
  };

  const updateOption = (stepId, optionId, updater) => {
    updateStep(stepId, (current) => ({
      ...current,
      options: (current.options ?? []).map((item) => (item.id === optionId ? updater(item) : item)),
    }));
  };

  const removeOption = (stepId, optionId) => {
    updateStep(stepId, (current) => ({
      ...current,
      options: (current.options ?? []).filter((item) => item.id !== optionId),
    }));

    setSelectedOptionId((currentSelected) => (currentSelected === optionId ? null : currentSelected));
  };

  const collectReachableKeys = () => {
    const visited = new Set();

    const visit = (stepKey, path = new Set()) => {
      if (!stepKey || visited.has(stepKey) || path.has(stepKey)) {
        return;
      }

      visited.add(stepKey);
      const step = stepByKey.get(stepKey);

      if (!step) {
        return;
      }

      const nextPath = new Set(path);
      nextPath.add(stepKey);

      if (step.type === 'numeric_menu') {
        for (const option of step.options ?? []) {
          if (option?.action?.kind !== 'go_to') {
            continue;
          }

          visit(normalizeStepKey(option?.action?.flow, option?.action?.step), nextPath);
        }

        return;
      }

      if (step?.on_text?.kind === 'go_to') {
        visit(normalizeStepKey(step?.on_text?.flow, step?.on_text?.step), nextPath);
      }
    };

    visit(initialStepKey);
    return visited;
  };

  const reachableKeys = collectReachableKeys();

  const disconnectedStepIds = useMemo(() => {
    return new Set(
      editor.steps
        .filter((step) => {
          const stepKey = normalizeStepKey(step.flow, step.step);
          if (!stepKey) {
            return true;
          }
          return !reachableKeys.has(stepKey);
        })
        .map((step) => step.id)
    );
  }, [editor.steps, reachableKeys]);

  const [selectedStepId, setSelectedStepId] = useState(editor.steps[0]?.id ?? null);
  const [selectedOptionId, setSelectedOptionId] = useState(null);

  useEffect(() => {
    if (!editor.steps.length) {
      setSelectedStepId(null);
      setSelectedOptionId(null);
      return;
    }

    const exists = editor.steps.some((step) => step.id === selectedStepId);
    if (!exists) {
      setSelectedStepId(editor.steps[0].id);
      setSelectedOptionId(null);
    }
  }, [editor.steps, selectedStepId]);

  const selectedStep = editor.steps.find((step) => step.id === selectedStepId) ?? null;
  const selectedStepIndex = selectedStep ? stepIndexById.get(selectedStep.id) ?? null : null;
  const selectedOption =
    selectedStep?.type === 'numeric_menu'
      ? (selectedStep.options ?? []).find((option) => option.id === selectedOptionId) ?? null
      : null;

  return (
    <div className="stateful-editor-layout">
      <section className="stateful-editor-top-grid">
        <div className="stateful-editor-card">
          <div className="stateful-editor-card-header">
            <div>
              <h4 className="stateful-editor-card-title">Configuração geral</h4>
              <p className="stateful-editor-card-subtitle">
                Comandos que fazem o atendimento voltar para o início.
              </p>
            </div>
          </div>

          <div className="space-y-2">
            {(editor.commands ?? []).map((command, index) => (
              <div key={`command-${index}`} className="stateful-inline-row">
                <input
                  type="text"
                  value={command}
                  onChange={(e) => updateCommand(index, e.target.value)}
                  placeholder="Ex.: # ou menu"
                  className="stateful-input"
                />
                <button
                  type="button"
                  onClick={() => removeCommand(index)}
                  className="stateful-btn stateful-btn-danger"
                >
                  Remover
                </button>
              </div>
            ))}

            <button
              type="button"
              onClick={addCommand}
              className="stateful-btn stateful-btn-secondary"
            >
              Adicionar comando
            </button>
          </div>
        </div>

        <div className="stateful-editor-card">
          <div className="stateful-editor-card-header">
            <div>
              <h4 className="stateful-editor-card-title">Bloco inicial</h4>
              <p className="stateful-editor-card-subtitle">
                Escolha qual bloco será enviado primeiro para o cliente.
              </p>
            </div>
          </div>

          <select
            value={`${editor.initial_flow || ''}.${editor.initial_step || ''}`}
            onChange={(e) => {
              const [flow, ...rest] = e.target.value.split('.');
              setEditor({
                ...editor,
                initial_flow: flow ?? '',
                initial_step: rest.join('.'),
              });
            }}
            className="stateful-input"
          >
            {!stepOptions.length && <option value="">Adicione um bloco para selecionar o inicial</option>}
            {stepOptions.map((stepItem) => (
              <option key={stepItem.id} value={stepItem.value}>
                {stepItem.label}
              </option>
            ))}
          </select>
        </div>
      </section>

      <section className="stateful-editor-main-grid">
        <aside className="stateful-editor-sidebar">
          <div className="stateful-editor-sidebar-header">
            <div>
              <h4 className="stateful-editor-card-title">Blocos do atendimento</h4>
              <p className="stateful-editor-card-subtitle">
                Selecione um bloco para editar.
              </p>
            </div>

            <div className="stateful-sidebar-actions">
              <button
                type="button"
                onClick={() => addStep('numeric_menu')}
                className="stateful-btn stateful-btn-secondary"
              >
                + Menu
              </button>
              <button
                type="button"
                onClick={() => addStep('free_text')}
                className="stateful-btn stateful-btn-secondary"
              >
                + Pergunta
              </button>
            </div>
          </div>

          {!editor.steps.length && (
            <p className="text-sm text-[#706f6c]">Nenhum bloco adicionado ainda.</p>
          )}

          <div className="stateful-step-list">
            {editor.steps.map((step, index) => {
              const stepKey = normalizeStepKey(step.flow, step.step);
              const isInitial = stepKey && stepKey === initialStepKey;

              return (
                <StepListItem
                  key={step.id}
                  step={step}
                  index={index}
                  isActive={selectedStepId === step.id}
                  isInitial={Boolean(isInitial)}
                  isDisconnected={disconnectedStepIds.has(step.id) && !isInitial}
                  onSelect={() => {
                    setSelectedStepId(step.id);
                    setSelectedOptionId(null);
                  }}
                />
              );
            })}
          </div>
        </aside>

        <div className="stateful-editor-content">
          {!selectedStep && (
            <div className="stateful-editor-empty">
              <p>Selecione um bloco para começar a editar.</p>
            </div>
          )}

          {selectedStep && (
            <div className="space-y-4">
              <section className="stateful-editor-card">
                <div className="stateful-editor-card-header">
                  <div>
                    <h4 className="stateful-editor-card-title">
                      {getStepDisplayName(selectedStep, selectedStepIndex)}
                    </h4>
                    <p className="stateful-editor-card-subtitle">
                      Edite o conteúdo principal deste bloco.
                    </p>
                  </div>

                  <button
                    type="button"
                    onClick={() => removeStep(selectedStep.id)}
                    className="stateful-btn stateful-btn-danger"
                  >
                    Excluir bloco
                  </button>
                </div>

                <div className="stateful-form-grid">
                  <label className="stateful-field">
                    <span className="stateful-field-label">Nome do bloco</span>
                    <input
                      type="text"
                      value={selectedStep.display_name || ''}
                      onChange={(e) =>
                        updateStep(selectedStep.id, (current) => ({
                          ...current,
                          display_name: e.target.value,
                        }))
                      }
                      placeholder="Ex.: Menu principal"
                      className="stateful-input"
                    />
                  </label>

                  <label className="stateful-field">
                    <span className="stateful-field-label">Tipo do bloco</span>
                    <select
                      value={selectedStep.type}
                      onChange={(e) =>
                        updateStep(selectedStep.id, (current) => {
                          if (e.target.value === current.type) {
                            return current;
                          }

                          return {
                            ...createEmptyStep(e.target.value === 'free_text' ? 'free_text' : 'numeric_menu'),
                            id: current.id,
                            display_name: current.display_name,
                            flow: current.flow,
                            step: current.step,
                            reply_text: current.reply_text,
                          };
                        })
                      }
                      className="stateful-input"
                    >
                      <option value="numeric_menu">Menu com opções</option>
                      <option value="free_text">Pergunta aberta</option>
                    </select>
                  </label>
                </div>

                <label className="stateful-field">
                  <span className="stateful-field-label">Mensagem que o cliente vai receber</span>
                  <textarea
                    value={selectedStep.reply_text || ''}
                    onChange={(e) =>
                      updateStep(selectedStep.id, (current) => ({
                        ...current,
                        reply_text: e.target.value,
                      }))
                    }
                    rows={5}
                    placeholder="Ex.: Olá! Escolha uma das opções abaixo."
                    className="stateful-input stateful-input-textarea"
                  />
                </label>

                <div className="stateful-preview-box">
                  <p className="stateful-preview-label">Prévia</p>
                  <p className="stateful-preview-content">
                    {selectedStep.reply_text || 'A mensagem deste bloco aparecerá aqui.'}
                  </p>
                </div>
              </section>

              {selectedStep.type === 'numeric_menu' ? (
                <>
                  <section className="stateful-editor-card">
                    <div className="stateful-editor-card-header">
                      <div>
                        <h4 className="stateful-editor-card-title">Opções do menu</h4>
                        <p className="stateful-editor-card-subtitle">
                          Clique em uma opção para editar a ação sem abrir vários níveis na tela.
                        </p>
                      </div>

                      <button
                        type="button"
                        onClick={() => addOption(selectedStep.id)}
                        className="stateful-btn stateful-btn-secondary"
                      >
                        Nova opção
                      </button>
                    </div>

                    <label className="stateful-field">
                      <span className="stateful-field-label">
                        Mensagem quando o cliente escolher uma opção inválida
                      </span>
                      <input
                        type="text"
                        value={selectedStep.invalid_option_text || ''}
                        onChange={(e) =>
                          updateStep(selectedStep.id, (current) => ({
                            ...current,
                            invalid_option_text: e.target.value,
                          }))
                        }
                        placeholder="Se vazio, o sistema gera automaticamente"
                        className="stateful-input"
                      />
                    </label>

                    <div className="stateful-option-list">
                      {(selectedStep.options ?? []).map((option, optionIndex) => (
                        <button
                          key={option.id}
                          type="button"
                          onClick={() => setSelectedOptionId(option.id)}
                          className={`stateful-option-list-item ${selectedOptionId === option.id ? 'stateful-option-list-item--active' : ''
                            }`}
                        >
                          <div>
                            <p className="stateful-option-list-title">Opção {optionIndex + 1}</p>
                            <p className="stateful-option-list-meta">
                              {(option.key || '?').trim()} • {(option.label || 'Sem texto').trim()}
                            </p>
                          </div>

                          <span className="stateful-option-list-summary">
                            {getActionSummary(option.action, stepOptions)}
                          </span>
                        </button>
                      ))}

                      {!selectedStep.options?.length && (
                        <p className="text-sm text-[#706f6c]">Nenhuma opção criada ainda.</p>
                      )}
                    </div>
                  </section>

                  {selectedOption && (
                    <section className="stateful-editor-card">
                      <div className="stateful-editor-card-header">
                        <div>
                          <h4 className="stateful-editor-card-title">
                            Editando opção {(selectedStep.options ?? []).findIndex((item) => item.id === selectedOption.id) + 1}
                          </h4>
                          <p className="stateful-editor-card-subtitle">
                            Defina o texto mostrado e o destino desta opção.
                          </p>
                        </div>

                        <button
                          type="button"
                          onClick={() => removeOption(selectedStep.id, selectedOption.id)}
                          className="stateful-btn stateful-btn-danger"
                        >
                          Excluir opção
                        </button>
                      </div>

                      <div className="stateful-form-grid">
                        <label className="stateful-field">
                          <span className="stateful-field-label">Número da opção</span>
                          <input
                            type="text"
                            value={selectedOption.key || ''}
                            onChange={(e) =>
                              updateOption(selectedStep.id, selectedOption.id, (item) => ({
                                ...item,
                                key: e.target.value,
                              }))
                            }
                            placeholder="Ex.: 1"
                            className="stateful-input"
                          />
                        </label>

                        <label className="stateful-field">
                          <span className="stateful-field-label">Texto da opção</span>
                          <input
                            type="text"
                            value={selectedOption.label || ''}
                            onChange={(e) =>
                              updateOption(selectedStep.id, selectedOption.id, (item) => ({
                                ...item,
                                label: e.target.value,
                              }))
                            }
                            placeholder="Ex.: Financeiro"
                            className="stateful-input"
                          />
                        </label>
                      </div>

                      <ActionEditor
                        action={selectedOption.action}
                        serviceAreas={serviceAreas}
                        stepOptions={stepOptions.filter((item) => item.id !== selectedStep.id)}
                        onGoToTarget={setSelectedStepId}
                        onChange={(nextAction) =>
                          updateOption(selectedStep.id, selectedOption.id, (item) => ({
                            ...item,
                            action: nextAction,
                          }))
                        }
                      />
                    </section>
                  )}
                </>
              ) : (
                <section className="stateful-editor-card">
                  <div className="stateful-editor-card-header">
                    <div>
                      <h4 className="stateful-editor-card-title">Resposta em texto livre</h4>
                      <p className="stateful-editor-card-subtitle">
                        Defina o que acontece depois que o cliente responder.
                      </p>
                    </div>
                  </div>

                  <label className="stateful-field">
                    <span className="stateful-field-label">
                      Mensagem quando o cliente não responder corretamente
                    </span>
                    <input
                      type="text"
                      value={selectedStep.empty_input_reply_text || ''}
                      onChange={(e) =>
                        updateStep(selectedStep.id, (current) => ({
                          ...current,
                          empty_input_reply_text: e.target.value,
                        }))
                      }
                      placeholder="Se vazio, usa a mensagem principal do bloco"
                      className="stateful-input"
                    />
                  </label>

                  <ActionEditor
                    action={selectedStep.on_text}
                    serviceAreas={serviceAreas}
                    stepOptions={stepOptions.filter((item) => item.id !== selectedStep.id)}
                    onGoToTarget={setSelectedStepId}
                    onChange={(nextAction) =>
                      updateStep(selectedStep.id, (current) => ({
                        ...current,
                        on_text: nextAction,
                      }))
                    }
                  />
                </section>
              )}

              <section className="stateful-editor-card">
                <div className="stateful-editor-card-header">
                  <div>
                    <h4 className="stateful-editor-card-title">Configurações avançadas</h4>
                    <p className="stateful-editor-card-subtitle">
                      Campos internos. Só altere se realmente precisar.
                    </p>
                  </div>
                </div>

                <div className="stateful-form-grid">
                  <label className="stateful-field">
                    <span className="stateful-field-label">Identificador interno do grupo</span>
                    <input
                      type="text"
                      value={selectedStep.flow || ''}
                      onChange={(e) =>
                        updateStep(selectedStep.id, (current) => ({
                          ...current,
                          flow: e.target.value,
                        }))
                      }
                      placeholder="Ex.: support"
                      className="stateful-input"
                    />
                  </label>

                  <label className="stateful-field">
                    <span className="stateful-field-label">Identificador interno do bloco</span>
                    <input
                      type="text"
                      value={selectedStep.step || ''}
                      onChange={(e) =>
                        updateStep(selectedStep.id, (current) => ({
                          ...current,
                          step: e.target.value,
                        }))
                      }
                      placeholder="Ex.: issue_menu"
                      className="stateful-input"
                    />
                  </label>
                </div>
              </section>
            </div>
          )}
        </div>
      </section>
    </div>
  );
}

export default StatefulMenuFlowEditor;
