import { createEmptyStep } from '@/services/statefulMenuFlow';
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

function getActionSummary(action, stepOptions = []) {
  if (!action) {
    return 'Acao nao configurada';
  }

  if (action.kind === 'appointments_start') {
    return 'Iniciar agendamento automatico';
  }

  if (action.kind === 'appointments_cancel') {
    return 'Iniciar cancelamento de agendamento';
  }

  if (action.kind === 'handoff') {
    return `Transferir para: ${action.target_area_name || 'area nao definida'}`;
  }

  const targetKey = normalizeStepKey(action.flow, action.step);
  const targetStep = stepOptions.find((item) => item.value === targetKey);

  if (targetStep) {
    return `Abrir bloco: ${targetStep.label}`;
  }

  if (targetKey) {
    return `Abrir bloco: ${targetKey}`;
  }

  return 'Destino nao configurado';
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

    if (value === 'appointments_start') {
      onChange({
        ...safeAction,
        kind: 'appointments_start',
        target_area_name: safeAction.target_area_name || 'Atendimento',
      });
      return;
    }

    if (value === 'appointments_cancel') {
      onChange({
        ...safeAction,
        kind: 'appointments_cancel',
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
          <h5 className="stateful-editor-card-title">O que esta opcao vai fazer</h5>
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
            <option value="handoff">Transferir para uma area</option>
            <option value="appointments_start">Iniciar agendamento automatico</option>
            <option value="appointments_cancel">Cancelar agendamento</option>
          </select>
        </label>

        <label className="stateful-field">
          <span className="stateful-field-label">Mensagem de confirmacao (opcional)</span>
          <input
            type="text"
            value={safeAction.reply_text || ''}
            onChange={(e) => onChange({ ...safeAction, reply_text: e.target.value })}
            placeholder="Ex.: Certo, vou te encaminhar."
            className="stateful-input"
          />
        </label>
      </div>

      {safeAction.kind === 'appointments_cancel' ? (
        <div className="space-y-3">
          <p className="stateful-editor-card-subtitle">
            O cliente sera encaminhado para o fluxo de cancelamento de agendamento. O tempo minimo de antecedencia e configurado em <strong>Agendamentos</strong>.
          </p>
        </div>
      ) : safeAction.kind === 'appointments_start' ? (
        <div className="space-y-3">
          <p className="stateful-editor-card-subtitle">
            O cliente sera encaminhado para o fluxo de agendamento automatico. Configure o servico e os horarios em <strong>Agendamentos</strong>.
          </p>
          <label className="stateful-field">
            <span className="stateful-field-label">Area de fallback (se nao houver horarios disponiveis)</span>
            <select
              value={safeAction.target_area_name || 'Atendimento'}
              onChange={(e) => onChange({ ...safeAction, target_area_name: e.target.value })}
              className="stateful-input"
            >
              <option value="Atendimento">Atendimento (padrao)</option>
              {(serviceAreas ?? []).map((area) => (
                <option key={String(area)} value={String(area)}>
                  {String(area)}
                </option>
              ))}
            </select>
          </label>
        </div>
      ) : safeAction.kind === 'handoff' ? (
        <label className="stateful-field">
          <span className="stateful-field-label">Area de atendimento</span>
          <select
            value={safeAction.target_area_name || ''}
            onChange={(e) => onChange({ ...safeAction, target_area_name: e.target.value })}
            className="stateful-input"
          >
            <option value="">
              {(serviceAreas ?? []).length ? 'Selecione uma area' : 'Nenhuma area cadastrada'}
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

export default function NodeEditor({
  selectedStep,
  selectedStepIndex,
  selectedOption,
  selectedOptionId,
  stepOptions = [],
  serviceAreas = [],
  onRemoveStep,
  onUpdateStep,
  onAddOption,
  onSelectOption,
  onRemoveOption,
  onUpdateOption,
  onSelectStep,
}) {
  if (!selectedStep) {
    return null;
  }

  const selectableStepOptions = stepOptions.filter((item) => item.id !== selectedStep.id);

  return (
    <div className="space-y-4">
      <section className="stateful-editor-card">
        <div className="stateful-editor-card-header">
          <div>
            <h4 className="stateful-editor-card-title">
              {getStepDisplayName(selectedStep, selectedStepIndex)}
            </h4>
            <p className="stateful-editor-card-subtitle">
              Edite o conteudo principal deste bloco.
            </p>
          </div>

          <button
            type="button"
            onClick={() => onRemoveStep?.(selectedStep.id)}
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
                onUpdateStep?.(selectedStep.id, (current) => ({
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
                onUpdateStep?.(selectedStep.id, (current) => {
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
              <option value="numeric_menu">Menu com opcoes</option>
              <option value="free_text">Pergunta aberta</option>
            </select>
          </label>
        </div>

        {selectedStep.type === 'numeric_menu' && (
          <>
            <label className="stateful-field">
              <span className="stateful-field-label">Modo de interacao</span>
              <select
                value={selectedStep.interaction_mode || 'auto'}
                onChange={(e) =>
                  onUpdateStep?.(selectedStep.id, (current) => ({
                    ...current,
                    interaction_mode: e.target.value,
                  }))
                }
                className="stateful-input"
              >
                <option value="auto">Automatico (ate 3 opcoes: botoes | mais de 3: lista)</option>
                <option value="button">Botoes (maximo 3 opcoes)</option>
                <option value="list">Lista de opcoes (ate 10 opcoes)</option>
                <option value="text">Resposta por texto (comportamento classico)</option>
              </select>
            </label>

            {(selectedStep.interaction_mode || 'auto') !== 'text' && (
              <div className="stateful-form-grid">
                <label className="stateful-field">
                  <span className="stateful-field-label">Cabecalho da mensagem</span>
                  <input
                    type="text"
                    value={selectedStep.button_header_text || ''}
                    onChange={(e) =>
                      onUpdateStep?.(selectedStep.id, (current) => ({
                        ...current,
                        button_header_text: e.target.value,
                      }))
                    }
                    placeholder="Ex: Atendimento Blasternet"
                    className="stateful-input"
                  />
                </label>

                <label className="stateful-field">
                  <span className="stateful-field-label">Rodape da mensagem</span>
                  <input
                    type="text"
                    value={selectedStep.button_footer_text || ''}
                    onChange={(e) =>
                      onUpdateStep?.(selectedStep.id, (current) => ({
                        ...current,
                        button_footer_text: e.target.value,
                      }))
                    }
                    placeholder="Ex: Responda clicando em uma opcao"
                    className="stateful-input"
                  />
                </label>
              </div>
            )}

            {((selectedStep.interaction_mode || 'auto') === 'list' ||
              ((selectedStep.interaction_mode || 'auto') === 'auto' &&
                (selectedStep.options?.length ?? 0) > 3)) && (
              <label className="stateful-field">
                <span className="stateful-field-label">Label do botao de lista</span>
                <input
                  type="text"
                  value={selectedStep.button_action_label || ''}
                  onChange={(e) =>
                    onUpdateStep?.(selectedStep.id, (current) => ({
                      ...current,
                      button_action_label: e.target.value,
                    }))
                  }
                  placeholder="Ver opcoes"
                  className="stateful-input"
                />
              </label>
            )}
          </>
        )}

        <label className="stateful-field">
          <span className="stateful-field-label">Mensagem que o cliente vai receber</span>
          <textarea
            value={selectedStep.reply_text || ''}
            onChange={(e) =>
              onUpdateStep?.(selectedStep.id, (current) => ({
                ...current,
                reply_text: e.target.value,
              }))
            }
            rows={5}
            placeholder="Ex.: Ola! Escolha uma das opcoes abaixo."
            className="stateful-input stateful-input-textarea"
          />
        </label>

        {selectedStep.type === 'numeric_menu' ? (() => {
          const mode = selectedStep.interaction_mode || 'auto';
          const optionCount = selectedStep.options?.length ?? 0;
          const effectiveMode = mode === 'auto' ? (optionCount <= 3 ? 'button' : 'list') : mode;
          const header = (selectedStep.button_header_text || '').trim();
          const footer = (selectedStep.button_footer_text || '').trim();
          const actionLabel = (selectedStep.button_action_label || '').trim() || 'Ver opcoes';
          const body = (selectedStep.reply_text || '').trim() || 'A mensagem deste bloco aparecera aqui.';
          const options = selectedStep.options ?? [];

          return (
            <div className="stateful-preview-box stateful-preview-whatsapp">
              <p className="stateful-preview-label">Previa WhatsApp</p>
              <div className="stateful-preview-bubble">
                {header && <p className="stateful-preview-header">{header}</p>}
                <p className="stateful-preview-body">{body}</p>
                {footer && <p className="stateful-preview-footer">{footer}</p>}
                {effectiveMode !== 'text' && <hr className="stateful-preview-divider" />}
                {effectiveMode === 'text' ? (
                  options.map((opt, i) => (
                    <p key={opt.id} className="stateful-preview-text-option">
                      {(opt.key || String(i + 1)).trim()} - {(opt.label || 'Opcao').trim()}
                    </p>
                  ))
                ) : effectiveMode === 'button' ? (
                  options.slice(0, 3).map((opt) => (
                    <div key={opt.id} className="stateful-preview-button">
                      {(opt.label || 'Opcao').trim()}
                    </div>
                  ))
                ) : (
                  <div className="stateful-preview-list-btn">
                    [=] {actionLabel} --
                  </div>
                )}
              </div>
            </div>
          );
        })() : (
          <div className="stateful-preview-box">
            <p className="stateful-preview-label">Previa</p>
            <p className="stateful-preview-content">
              {selectedStep.reply_text || 'A mensagem deste bloco aparecera aqui.'}
            </p>
          </div>
        )}
      </section>

      {selectedStep.type === 'numeric_menu' ? (
        <>
          <section className="stateful-editor-card">
            <div className="stateful-editor-card-header">
              <div>
                <h4 className="stateful-editor-card-title">Opcoes do menu</h4>
                <p className="stateful-editor-card-subtitle">
                  Clique em uma opcao para configurar o que ela faz.
                </p>
              </div>

              <button
                type="button"
                onClick={() => onAddOption?.(selectedStep.id)}
                className="stateful-btn stateful-btn-secondary"
              >
                Nova opcao
              </button>
            </div>

            {(selectedStep.interaction_mode || 'auto') === 'text' && (
              <label className="stateful-field">
                <span className="stateful-field-label">
                  Resposta se o cliente digitar uma opcao que nao existe
                </span>
                <input
                  type="text"
                  value={selectedStep.invalid_option_text || ''}
                  onChange={(e) =>
                    onUpdateStep?.(selectedStep.id, (current) => ({
                      ...current,
                      invalid_option_text: e.target.value,
                    }))
                  }
                  placeholder="Se vazio, o sistema gera automaticamente"
                  className="stateful-input"
                />
              </label>
            )}

            <div className="stateful-option-list">
              {(selectedStep.options ?? []).map((option, optionIndex) => (
                <button
                  key={option.id}
                  type="button"
                  onClick={() => onSelectOption?.(option.id)}
                  className={`stateful-option-list-item ${selectedOptionId === option.id ? 'stateful-option-list-item--active' : ''}`}
                >
                  <div>
                    <p className="stateful-option-list-title">Opcao {optionIndex + 1}</p>
                    <p className="stateful-option-list-meta">
                      {(option.key || '?').trim()} - {(option.label || 'Sem texto').trim()}
                    </p>
                  </div>

                  <span className="stateful-option-list-summary">
                    {getActionSummary(option.action, stepOptions)}
                  </span>
                </button>
              ))}

              {!selectedStep.options?.length && (
                <p className="text-sm text-[#706f6c]">Nenhuma opcao criada ainda.</p>
              )}
            </div>
          </section>

          {selectedOption && (
            <section className="stateful-editor-card">
              <div className="stateful-editor-card-header">
                <div>
                  <h4 className="stateful-editor-card-title">
                    Editando opcao {(selectedStep.options ?? []).findIndex((item) => item.id === selectedOption.id) + 1}
                  </h4>
                  <p className="stateful-editor-card-subtitle">
                    Defina o texto mostrado e o destino desta opcao.
                  </p>
                </div>

                <button
                  type="button"
                  onClick={() => onRemoveOption?.(selectedStep.id, selectedOption.id)}
                  className="stateful-btn stateful-btn-danger"
                >
                  Excluir opcao
                </button>
              </div>

              <div className="stateful-form-grid">
                <label className="stateful-field">
                  <span className="stateful-field-label">Numero que o cliente vai digitar</span>
                  <input
                    type="text"
                    value={selectedOption.key || ''}
                    onChange={(e) =>
                      onUpdateOption?.(selectedStep.id, selectedOption.id, (item) => ({
                        ...item,
                        key: e.target.value,
                      }))
                    }
                    placeholder="Ex.: 1"
                    className="stateful-input"
                  />
                </label>

                <label className="stateful-field">
                  <span className="stateful-field-label">Texto que aparece no menu</span>
                  <input
                    type="text"
                    value={selectedOption.label || ''}
                    onChange={(e) =>
                      onUpdateOption?.(selectedStep.id, selectedOption.id, (item) => ({
                        ...item,
                        label: e.target.value,
                      }))
                    }
                    placeholder="Ex.: Financeiro"
                    className="stateful-input"
                  />
                </label>
              </div>

              {(selectedStep.interaction_mode || 'auto') !== 'text' && (
                <label className="stateful-field">
                  <span className="stateful-field-label">ID do botao</span>
                  <input
                    type="text"
                    value={selectedOption.button_id || ''}
                    onChange={(e) =>
                      onUpdateOption?.(selectedStep.id, selectedOption.id, (item) => ({
                        ...item,
                        button_id: e.target.value,
                      }))
                    }
                    placeholder="gerado automaticamente"
                    className="stateful-input stateful-input--small"
                  />
                  <span className="stateful-field-hint">Somente letras minusculas, numeros e hifens</span>
                </label>
              )}

              <ActionEditor
                action={selectedOption.action}
                serviceAreas={serviceAreas}
                stepOptions={selectableStepOptions}
                onGoToTarget={onSelectStep}
                onChange={(nextAction) =>
                  onUpdateOption?.(selectedStep.id, selectedOption.id, (item) => ({
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
              <h4 className="stateful-editor-card-title">O que acontece apos a resposta</h4>
              <p className="stateful-editor-card-subtitle">
                Defina o que o bot faz depois que o cliente enviar a resposta.
              </p>
            </div>
          </div>

          <label className="stateful-field">
            <span className="stateful-field-label">
              Resposta se o cliente nao digitar nada (opcional)
            </span>
            <input
              type="text"
              value={selectedStep.empty_input_reply_text || ''}
              onChange={(e) =>
                onUpdateStep?.(selectedStep.id, (current) => ({
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
            stepOptions={selectableStepOptions}
            onGoToTarget={onSelectStep}
            onChange={(nextAction) =>
              onUpdateStep?.(selectedStep.id, (current) => ({
                ...current,
                on_text: nextAction,
              }))
            }
          />
        </section>
      )}
    </div>
  );
}
