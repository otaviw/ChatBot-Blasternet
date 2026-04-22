import { normalizeStepKey } from './flowValidator';

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
      ? `${step.options?.length ?? 0} opçao(ões)`
      : 'Texto livre';

  return (
    <button
      type="button"
      onClick={onSelect}
      className={`stateful-step-list-item ${isActive ? 'stateful-step-list-item--active' : ''}`}
    >
      <div className="stateful-step-list-item-top">
        <div>
          <p className="stateful-step-list-item-title">
            <span className="stateful-step-list-item-index">{index + 1}.</span> {name}
          </p>
          <p className="stateful-step-list-item-meta">
            {typeLabel} - {meta}
          </p>
        </div>

        <div className="stateful-step-list-badges">
          {isInitial && <span className="stateful-badge stateful-badge--success">Inicial</span>}
          {isDisconnected && <span className="stateful-badge">Desconectado</span>}
        </div>
      </div>
    </button>
  );
}

export default function NodeInspector({
  steps = [],
  selectedStepId,
  initialStepKey = '',
  disconnectedStepIds,
  onSelectStep,
  onAddMenuStep,
  onAddFreeTextStep,
}) {
  return (
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
            onClick={onAddMenuStep}
            className="stateful-btn stateful-btn-secondary"
          >
            + Menu
          </button>
          <button
            type="button"
            onClick={onAddFreeTextStep}
            className="stateful-btn stateful-btn-secondary"
          >
            + Pergunta
          </button>
        </div>
      </div>

      {!steps.length && (
        <p className="text-sm text-[#706f6c]">Nenhum bloco adicionado ainda.</p>
      )}

      <div className="stateful-step-list">
        {steps.map((step, index) => {
          const stepKey = normalizeStepKey(step.flow, step.step);
          const isInitial = stepKey && stepKey === initialStepKey;

          return (
            <StepListItem
              key={step.id}
              step={step}
              index={index}
              isActive={selectedStepId === step.id}
              isInitial={Boolean(isInitial)}
              isDisconnected={disconnectedStepIds?.has(step.id) && !isInitial}
              onSelect={() => onSelectStep?.(step.id)}
            />
          );
        })}
      </div>
    </aside>
  );
}
