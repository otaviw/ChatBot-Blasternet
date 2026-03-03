import './StatefulMenuFlowEditor.css';
import { createEmptyOption, createEmptyStep } from '@/services/statefulMenuFlow';

function actionSummary(action) {
  if (!action) {
    return 'Acao nao configurada';
  }

  if (action.kind === 'handoff') {
    return `Handoff -> ${action.target_area_name || 'area nao definida'}`;
  }

  return `Ir para -> ${action.flow || 'flow'} . ${action.step || 'step'}`;
}

function ActionEditor({ action, onChange, serviceAreas = [] }) {
  const safeAction = action ?? {
    kind: 'go_to',
    reply_text: '',
    flow: '',
    step: '',
    target_area_name: '',
  };

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

  return (
    <div className="rounded border border-[#e3e3e0] dark:border-[#3E3E3A] p-3 space-y-3">
      <p className="text-xs text-[#706f6c]">{actionSummary(safeAction)}</p>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <label className="text-sm">
          Tipo da acao
          <select
            value={safeAction.kind}
            onChange={(e) => changeKind(e.target.value)}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
          >
            <option value="go_to">Ir para outro passo</option>
            <option value="handoff">Transferir para area</option>
          </select>
        </label>

        <label className="text-sm">
          Resposta da acao (opcional)
          <input
            type="text"
            value={safeAction.reply_text || ''}
            onChange={(e) => onChange({ ...safeAction, reply_text: e.target.value })}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
          />
        </label>
      </div>

      {safeAction.kind === 'handoff' ? (
        <label className="text-sm block">
          Area de handoff
          <input
            type="text"
            list="stateful-flow-areas"
            value={safeAction.target_area_name || ''}
            onChange={(e) => onChange({ ...safeAction, target_area_name: e.target.value })}
            placeholder="Ex.: Suporte"
            className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
          />
          <datalist id="stateful-flow-areas">
            {(serviceAreas ?? []).map((area) => (
              <option key={String(area)} value={String(area)} />
            ))}
          </datalist>
        </label>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="text-sm">
            Flow destino
            <input
              type="text"
              value={safeAction.flow || ''}
              onChange={(e) => onChange({ ...safeAction, flow: e.target.value })}
              placeholder="Ex.: support"
              className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
            />
          </label>
          <label className="text-sm">
            Step destino
            <input
              type="text"
              value={safeAction.step || ''}
              onChange={(e) => onChange({ ...safeAction, step: e.target.value })}
              placeholder="Ex.: issue_menu"
              className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
            />
          </label>
        </div>
      )}
    </div>
  );
}

function StatefulMenuFlowEditor({ value, onChange, serviceAreas = [] }) {
  const editor = value ?? {
    commands: ['#', 'menu'],
    initial_flow: 'main',
    initial_step: 'menu',
    steps: [],
  };

  const stepOptions = editor.steps.map((step) => {
    const flow = String(step.flow ?? '').trim();
    const stepName = String(step.step ?? '').trim();
    return {
      id: step.id,
      value: `${flow}.${stepName}`,
      label: flow && stepName ? `${flow}.${stepName}` : '(flow/step pendente)',
    };
  });

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
    setEditor({
      ...editor,
      steps: [...editor.steps, createEmptyStep(type)],
    });
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

  return (
    <div className="space-y-5">
      <section className="space-y-3">
        <h4 className="font-medium text-sm">Comandos globais para reiniciar</h4>
        <p className="text-xs text-[#706f6c]">
          Esses comandos reiniciam o fluxo para o inicio. O menu ja entra automaticamente na primeira mensagem.
        </p>
        <div className="space-y-2">
          {(editor.commands ?? []).map((command, index) => (
            <div key={`command-${index}`} className="flex gap-2">
              <input
                type="text"
                value={command}
                onChange={(e) => updateCommand(index, e.target.value)}
                placeholder="Ex.: # ou menu"
                className="flex-1 rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
              />
              <button
                type="button"
                onClick={() => removeCommand(index)}
                className="px-3 py-1 text-sm rounded border border-red-300 text-red-700"
              >
                Remover
              </button>
            </div>
          ))}
          <button
            type="button"
            onClick={addCommand}
            className="px-3 py-1.5 text-sm rounded border border-[#d5d5d2]"
          >
            Adicionar comando
          </button>
        </div>
      </section>

      <section className="space-y-3">
        <h4 className="font-medium text-sm">Passo inicial</h4>
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
          className="w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
        >
          {!stepOptions.length && <option value="">Adicione um passo para selecionar o inicial</option>}
          {stepOptions.map((stepItem) => (
            <option key={stepItem.id} value={stepItem.value}>
              {stepItem.label}
            </option>
          ))}
        </select>
      </section>

      <section className="space-y-3">
        <div className="flex items-center justify-between gap-2">
          <h4 className="font-medium text-sm">Passos do fluxo</h4>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => addStep('numeric_menu')}
              className="px-3 py-1.5 text-sm rounded border border-[#d5d5d2]"
            >
              Novo menu numerado
            </button>
            <button
              type="button"
              onClick={() => addStep('free_text')}
              className="px-3 py-1.5 text-sm rounded border border-[#d5d5d2]"
            >
              Novo passo texto livre
            </button>
          </div>
        </div>

        {!editor.steps.length && (
          <p className="text-sm text-[#706f6c]">Nenhum passo adicionado.</p>
        )}

        <div className="space-y-4">
          {editor.steps.map((step, stepIndex) => (
            <article
              key={step.id}
              className="rounded-lg border border-[#d9d9d5] dark:border-[#3E3E3A] p-4 space-y-4 bg-white dark:bg-[#141413]"
            >
              <div className="flex items-center justify-between gap-2">
                <div>
                  <p className="font-medium text-sm">Passo {stepIndex + 1}</p>
                  <p className="text-xs text-[#706f6c]">
                    {step.flow && step.step ? `${step.flow}.${step.step}` : 'Identificador pendente'}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => removeStep(step.id)}
                  className="px-3 py-1 text-sm rounded border border-red-300 text-red-700"
                >
                  Remover passo
                </button>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                <label className="text-sm">
                  Flow
                  <input
                    type="text"
                    value={step.flow || ''}
                    onChange={(e) => updateStep(step.id, (current) => ({ ...current, flow: e.target.value }))}
                    placeholder="Ex.: support"
                    className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                  />
                </label>

                <label className="text-sm">
                  Step
                  <input
                    type="text"
                    value={step.step || ''}
                    onChange={(e) => updateStep(step.id, (current) => ({ ...current, step: e.target.value }))}
                    placeholder="Ex.: issue_menu"
                    className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                  />
                </label>

                <label className="text-sm">
                  Tipo
                  <select
                    value={step.type}
                    onChange={(e) =>
                      updateStep(step.id, (current) => {
                        if (e.target.value === current.type) {
                          return current;
                        }

                        return {
                          ...createEmptyStep(e.target.value === 'free_text' ? 'free_text' : 'numeric_menu'),
                          id: current.id,
                          flow: current.flow,
                          step: current.step,
                          reply_text: current.reply_text,
                        };
                      })
                    }
                    className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                  >
                    <option value="numeric_menu">Menu numerado</option>
                    <option value="free_text">Texto livre</option>
                  </select>
                </label>
              </div>

              <label className="text-sm block">
                Texto de resposta do passo
                <textarea
                  value={step.reply_text || ''}
                  onChange={(e) => updateStep(step.id, (current) => ({ ...current, reply_text: e.target.value }))}
                  rows={3}
                  className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                />
              </label>

              {step.type === 'numeric_menu' ? (
                <div className="space-y-3">
                  <label className="text-sm block">
                    Mensagem de opcao invalida (opcional)
                    <input
                      type="text"
                      value={step.invalid_option_text || ''}
                      onChange={(e) =>
                        updateStep(step.id, (current) => ({ ...current, invalid_option_text: e.target.value }))
                      }
                      placeholder="Se vazio, o sistema gera automaticamente"
                      className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                    />
                  </label>

                  <div className="flex items-center justify-between gap-2">
                    <p className="text-sm font-medium">Opcoes do menu</p>
                    <button
                      type="button"
                      onClick={() =>
                        updateStep(step.id, (current) => ({
                          ...current,
                          options: [...(current.options ?? []), createEmptyOption((current.options?.length ?? 0) + 1)],
                        }))
                      }
                      className="px-3 py-1.5 text-sm rounded border border-[#d5d5d2]"
                    >
                      Adicionar opcao
                    </button>
                  </div>

                  <div className="space-y-3">
                    {(step.options ?? []).map((option, optionIndex) => (
                      <div
                        key={option.id}
                        className="rounded border border-[#e3e3e0] dark:border-[#3E3E3A] p-3 space-y-3"
                      >
                        <div className="flex items-center justify-between gap-2">
                          <p className="text-sm font-medium">Opcao {optionIndex + 1}</p>
                          <button
                            type="button"
                            onClick={() =>
                              updateStep(step.id, (current) => ({
                                ...current,
                                options: (current.options ?? []).filter((item) => item.id !== option.id),
                              }))
                            }
                            className="px-3 py-1 text-sm rounded border border-red-300 text-red-700"
                          >
                            Remover
                          </button>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                          <label className="text-sm">
                            Numero da opcao
                            <input
                              type="text"
                              value={option.key || ''}
                              onChange={(e) =>
                                updateStep(step.id, (current) => ({
                                  ...current,
                                  options: (current.options ?? []).map((item) =>
                                    item.id === option.id ? { ...item, key: e.target.value } : item
                                  ),
                                }))
                              }
                              placeholder="Ex.: 1"
                              className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                            />
                          </label>
                          <label className="text-sm">
                            Label da opcao
                            <input
                              type="text"
                              value={option.label || ''}
                              onChange={(e) =>
                                updateStep(step.id, (current) => ({
                                  ...current,
                                  options: (current.options ?? []).map((item) =>
                                    item.id === option.id ? { ...item, label: e.target.value } : item
                                  ),
                                }))
                              }
                              placeholder="Ex.: Financeiro"
                              className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                            />
                          </label>
                        </div>

                        <ActionEditor
                          action={option.action}
                          serviceAreas={serviceAreas}
                          onChange={(nextAction) =>
                            updateStep(step.id, (current) => ({
                              ...current,
                              options: (current.options ?? []).map((item) =>
                                item.id === option.id ? { ...item, action: nextAction } : item
                              ),
                            }))
                          }
                        />
                      </div>
                    ))}
                  </div>
                </div>
              ) : (
                <div className="space-y-3">
                  <label className="text-sm block">
                    Resposta para texto vazio (opcional)
                    <input
                      type="text"
                      value={step.empty_input_reply_text || ''}
                      onChange={(e) =>
                        updateStep(step.id, (current) => ({ ...current, empty_input_reply_text: e.target.value }))
                      }
                      placeholder="Se vazio, usa o texto principal do passo"
                      className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                    />
                  </label>

                  <div>
                    <p className="text-sm font-medium mb-2">Acao ao receber texto do cliente</p>
                    <ActionEditor
                      action={step.on_text}
                      serviceAreas={serviceAreas}
                      onChange={(nextAction) =>
                        updateStep(step.id, (current) => ({ ...current, on_text: nextAction }))
                      }
                    />
                  </div>
                </div>
              )}
            </article>
          ))}
        </div>
      </section>
    </div>
  );
}

export default StatefulMenuFlowEditor;



