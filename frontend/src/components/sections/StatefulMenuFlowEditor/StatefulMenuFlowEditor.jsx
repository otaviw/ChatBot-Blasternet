import { useEffect, useMemo, useState } from 'react';
import './StatefulMenuFlowEditor.css';
import { createEmptyOption, createEmptyStep } from '@/services/statefulMenuFlow';
import FlowCanvas from './FlowCanvas';
import { getDisconnectedStepIds, normalizeStepKey } from './flowValidator';

function getStepDisplayName(step, fallbackIndex = null) {
  const customName = String(step?.display_name ?? '').trim();
  if (customName) {
    return customName;
  }

  if (fallbackIndex !== null) {
    return `Bloco ${fallbackIndex + 1}`;
  }

  return 'Bloco sem nome';
}

function StatefulMenuFlowEditor({ value, onChange, serviceAreas = [] }) {
  const editor = value ?? {
    commands: ['#', 'menu'],
    initial_flow: 'main',
    initial_step: 'menu',
    steps: [],
  };

  const [selectedStepId, setSelectedStepId] = useState(editor.steps[0]?.id ?? null);
  const [selectedOptionId, setSelectedOptionId] = useState(null);

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

  const initialStepKey = normalizeStepKey(editor.initial_flow, editor.initial_step);

  const disconnectedStepIds = useMemo(
    () =>
      getDisconnectedStepIds({
        steps: editor.steps,
        initialFlow: editor.initial_flow,
        initialStep: editor.initial_step,
      }),
    [editor.initial_flow, editor.initial_step, editor.steps]
  );

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
        String(step.flow ?? '').trim() === String(editor.initial_flow ?? '').trim() &&
        String(step.step ?? '').trim() === String(editor.initial_step ?? '').trim()
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

  const updateCommand = (index, commandValue) => {
    updateCommands((editor.commands ?? []).map((item, i) => (i === index ? commandValue : item)));
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
              <h4 className="stateful-editor-card-title">Palavras-chave para reiniciar</h4>
              <p className="stateful-editor-card-subtitle">
                O cliente envia uma dessas palavras para voltar ao inicio do atendimento.
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
              Adicionar palavra-chave
            </button>
          </div>
        </div>

        <div className="stateful-editor-card">
          <div className="stateful-editor-card-header">
            <div>
              <h4 className="stateful-editor-card-title">Bloco inicial</h4>
              <p className="stateful-editor-card-subtitle">
                Escolha qual bloco sera enviado primeiro para o cliente.
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

      <FlowCanvas
        steps={editor.steps}
        selectedStep={selectedStep}
        selectedStepId={selectedStepId}
        selectedStepIndex={selectedStepIndex}
        selectedOption={selectedOption}
        selectedOptionId={selectedOptionId}
        initialStepKey={initialStepKey}
        disconnectedStepIds={disconnectedStepIds}
        stepOptions={stepOptions}
        serviceAreas={serviceAreas}
        onSelectStep={setSelectedStepId}
        onAddMenuStep={() => addStep('numeric_menu')}
        onAddFreeTextStep={() => addStep('free_text')}
        onRemoveStep={removeStep}
        onUpdateStep={updateStep}
        onAddOption={addOption}
        onSelectOption={setSelectedOptionId}
        onRemoveOption={removeOption}
        onUpdateOption={updateOption}
      />
    </div>
  );
}

export default StatefulMenuFlowEditor;
