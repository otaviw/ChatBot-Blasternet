import NodeEditor from './NodeEditor';
import NodeInspector from './NodeInspector';

export default function FlowCanvas({
  steps = [],
  selectedStep,
  selectedStepId,
  selectedStepIndex,
  selectedOption,
  selectedOptionId,
  initialStepKey = '',
  disconnectedStepIds,
  stepOptions = [],
  serviceAreas = [],
  onSelectStep,
  onAddMenuStep,
  onAddFreeTextStep,
  onRemoveStep,
  onUpdateStep,
  onAddOption,
  onSelectOption,
  onRemoveOption,
  onUpdateOption,
}) {
  return (
    <section className="stateful-editor-main-grid">
      <NodeInspector
        steps={steps}
        selectedStepId={selectedStepId}
        initialStepKey={initialStepKey}
        disconnectedStepIds={disconnectedStepIds}
        onSelectStep={(stepId) => {
          onSelectStep?.(stepId);
          onSelectOption?.(null);
        }}
        onAddMenuStep={onAddMenuStep}
        onAddFreeTextStep={onAddFreeTextStep}
      />

      <div className="stateful-editor-content">
        {!selectedStep && (
          <div className="stateful-editor-empty">
            <p>Selecione um bloco para comecar a editar.</p>
          </div>
        )}

        {selectedStep && (
          <NodeEditor
            selectedStep={selectedStep}
            selectedStepIndex={selectedStepIndex}
            selectedOption={selectedOption}
            selectedOptionId={selectedOptionId}
            stepOptions={stepOptions}
            serviceAreas={serviceAreas}
            onRemoveStep={onRemoveStep}
            onUpdateStep={onUpdateStep}
            onAddOption={onAddOption}
            onSelectOption={onSelectOption}
            onRemoveOption={onRemoveOption}
            onUpdateOption={onUpdateOption}
            onSelectStep={onSelectStep}
          />
        )}
      </div>
    </section>
  );
}
