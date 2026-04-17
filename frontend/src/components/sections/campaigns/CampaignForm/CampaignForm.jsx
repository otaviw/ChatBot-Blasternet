import './CampaignForm.css';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ContactSelector from '@/components/sections/contacts/ContactSelector/ContactSelector.jsx';
import api from '@/services/api';

const DEFAULT_VALUES = {
  name: '',
  type: '',
  contactIds: [],
  templateTemplateId: '',
  templateVariables: ['', ''],
  openTemplateId: '',
  openPostResponseMessage: '',
  freeMessage: '',
};

function CampaignForm({
  templates = [],
  contacts = [],
  busy = false,
  submitLabel = 'Salvar campanha',
  initialValues = DEFAULT_VALUES,
  importBusy = false,
  importError = '',
  onImportCsv,
  onSubmit,
  onCancel,
}) {
  const [name, setName] = useState(String(initialValues?.name ?? ''));
  const [type, setType] = useState(String(initialValues?.type ?? ''));
  const [selectionMode, setSelectionMode] = useState('all');
  const [selectedContactIds, setSelectedContactIds] = useState(() => {
    const ids = Array.isArray(initialValues?.contactIds) ? initialValues.contactIds : [];
    return ids.map((id) => Number(id)).filter((id) => Number.isInteger(id) && id > 0);
  });
  const [templateTemplateId, setTemplateTemplateId] = useState(
    String(initialValues?.templateTemplateId ?? '')
  );
  const [templateVariables, setTemplateVariables] = useState(() => {
    const values = Array.isArray(initialValues?.templateVariables)
      ? initialValues.templateVariables
      : DEFAULT_VALUES.templateVariables;
    return values.length > 0 ? values.map((value) => String(value ?? '')) : [''];
  });
  const [openTemplateId, setOpenTemplateId] = useState(String(initialValues?.openTemplateId ?? ''));
  const [openPostResponseMessage, setOpenPostResponseMessage] = useState(
    String(initialValues?.openPostResponseMessage ?? '')
  );
  const [freeMessage, setFreeMessage] = useState(String(initialValues?.freeMessage ?? ''));
  const [errors, setErrors] = useState({});
  const [validationLoading, setValidationLoading] = useState(false);
  const [validationError, setValidationError] = useState('');
  const [validationSummary, setValidationSummary] = useState({
    eligible: 0,
    outsideWindow: 0,
    invalid: 0,
  });
  const validationRequestIdRef = useRef(0);

  const templateOptions = useMemo(() => {
    return templates.map((template) => {
      const nameValue = String(template?.name ?? '').trim();
      const label = template?.language ? `${nameValue} (${template.language})` : nameValue;
      return { value: nameValue, label: label || 'Template sem nome' };
    });
  }, [templates]);

  const handleTemplateVariableChange = (index, value) => {
    setTemplateVariables((previous) =>
      previous.map((item, currentIndex) => (currentIndex === index ? value : item))
    );
  };

  const addTemplateVariable = () => {
    setTemplateVariables((previous) => [...previous, '']);
  };

  const removeTemplateVariable = (index) => {
    setTemplateVariables((previous) => previous.filter((_, currentIndex) => currentIndex !== index));
  };

  const validateSelectedContacts = useCallback(async () => {
    if (!type || selectedContactIds.length === 0) {
      setValidationError('');
      setValidationLoading(false);
      setValidationSummary({ eligible: 0, outsideWindow: 0, invalid: 0 });
      return;
    }

    const currentRequestId = validationRequestIdRef.current + 1;
    validationRequestIdRef.current = currentRequestId;
    setValidationLoading(true);
    setValidationError('');

    try {
      const response = await api.post('/minha-conta/campanhas/validar-contatos', {
        type,
        contact_ids: selectedContactIds,
      });

      if (validationRequestIdRef.current !== currentRequestId) return;

      setValidationSummary({
        eligible: Number(response?.data?.eligible_count ?? 0),
        outsideWindow: Number(response?.data?.outside_window_count ?? 0),
        invalid: Number(response?.data?.invalid_count ?? 0),
      });
    } catch (err) {
      if (validationRequestIdRef.current !== currentRequestId) return;
      setValidationError(err?.response?.data?.message ?? 'Nao foi possivel validar os contatos.');
      setValidationSummary({ eligible: 0, outsideWindow: 0, invalid: 0 });
    } finally {
      if (validationRequestIdRef.current === currentRequestId) {
        setValidationLoading(false);
      }
    }
  }, [selectedContactIds, type]);

  useEffect(() => {
    void validateSelectedContacts();
  }, [validateSelectedContacts]);

  const handleSubmit = (event) => {
    event.preventDefault();
    const nextErrors = {};

    if (!String(name ?? '').trim()) nextErrors.name = 'Nome obrigatorio.';
    if (!String(type ?? '').trim()) nextErrors.type = 'Tipo obrigatorio.';

    setErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return;

    onSubmit?.({
      name: String(name ?? '').trim(),
      type: String(type ?? '').trim(),
      contactIds: selectedContactIds,
      templateTemplateId: String(templateTemplateId ?? '').trim(),
      templateVariables: templateVariables
        .map((value) => String(value ?? '').trim())
        .filter((value) => value !== ''),
      openTemplateId: String(openTemplateId ?? '').trim(),
      openPostResponseMessage: String(openPostResponseMessage ?? '').trim(),
      freeMessage: String(freeMessage ?? '').trim(),
    });
  };

  return (
    <form onSubmit={handleSubmit} className="campaign-form">
      <div>
        <label htmlFor="campaign-form-name" className="campaign-form__label">
          Nome
        </label>
        <input
          id="campaign-form-name"
          className="app-input"
          type="text"
          value={name}
          onChange={(event) => setName(event.target.value)}
          disabled={busy}
          required
        />
        {errors.name ? <p className="campaign-form__error">{errors.name}</p> : null}
      </div>

      <ContactSelector
        contacts={contacts}
        disabled={busy}
        importBusy={importBusy}
        importError={importError}
        onImportCsv={onImportCsv}
        onModeChange={setSelectionMode}
        onSelectedIdsChange={setSelectedContactIds}
      />

      {selectionMode === 'csv' ? (
        <p className="campaign-form__hint">
          Apos importar, troque para "todos contatos" ou "selecionar manualmente".
        </p>
      ) : null}

      {selectedContactIds.length > 0 ? (
        <div className="campaign-form__validation">
          {validationLoading ? <p className="campaign-form__hint">Validando contatos...</p> : null}
          {validationError ? <p className="campaign-form__error">{validationError}</p> : null}
          {!validationLoading && !validationError ? (
            <div className="campaign-form__validation-list">
              <p>✔ {validationSummary.eligible} elegíveis</p>
              <p>⚠ {validationSummary.outsideWindow} fora da janela</p>
              <p>❌ {validationSummary.invalid} inválidos</p>
            </div>
          ) : null}
        </div>
      ) : null}

      <div>
        <label htmlFor="campaign-form-type" className="campaign-form__label">
          Tipo
        </label>
        <select
          id="campaign-form-type"
          className="app-input"
          value={type}
          onChange={(event) => setType(event.target.value)}
          disabled={busy}
          required
        >
          <option value="">Selecione o tipo</option>
          <option value="template">template</option>
          <option value="open">open (abrir conversa)</option>
          <option value="free">free (24h)</option>
        </select>
        {errors.type ? <p className="campaign-form__error">{errors.type}</p> : null}
      </div>

      <div className="campaign-form__templates-info" role="note" aria-live="polite">
        <p className="campaign-form__templates-info-text">
          Para enviar mensagens, você precisa de templates aprovados no WhatsApp.
        </p>
        <a
          href="https://business.facebook.com/wa/manage/message-templates/"
          target="_blank"
          rel="noreferrer noopener"
          className="app-btn-secondary campaign-form__templates-link"
        >
          Abrir gerenciador de templates
        </a>
      </div>

      {type === 'template' ? (
        <div className="campaign-form__section">
          <div>
            <label htmlFor="campaign-form-template" className="campaign-form__label">
              Template
            </label>
            <select
              id="campaign-form-template"
              className="app-input"
              value={templateTemplateId}
              onChange={(event) => setTemplateTemplateId(event.target.value)}
              disabled={busy}
            >
              <option value="">Selecione um template</option>
              {templateOptions.map((templateOption) => (
                <option key={templateOption.value} value={templateOption.value}>
                  {templateOption.label}
                </option>
              ))}
            </select>
          </div>

          <div className="campaign-form__variables">
            <div className="campaign-form__variables-header">
              <p className="campaign-form__label campaign-form__label--inline">Variaveis</p>
              <button
                type="button"
                className="app-btn-secondary campaign-form__small-btn"
                onClick={addTemplateVariable}
                disabled={busy}
              >
                + Variavel
              </button>
            </div>

            {templateVariables.map((variable, index) => (
              <div key={`template-variable-${index}`} className="campaign-form__variable-row">
                <input
                  className="app-input"
                  type="text"
                  value={variable}
                  onChange={(event) => handleTemplateVariableChange(index, event.target.value)}
                  placeholder={`Variavel ${index + 1}`}
                  disabled={busy}
                />
                {templateVariables.length > 1 ? (
                  <button
                    type="button"
                    className="app-btn-secondary campaign-form__small-btn"
                    onClick={() => removeTemplateVariable(index)}
                    disabled={busy}
                  >
                    Remover
                  </button>
                ) : null}
              </div>
            ))}
          </div>
        </div>
      ) : null}

      {type === 'open' ? (
        <div className="campaign-form__section">
          <div>
            <label htmlFor="campaign-form-open-template" className="campaign-form__label">
              Template inicial
            </label>
            <select
              id="campaign-form-open-template"
              className="app-input"
              value={openTemplateId}
              onChange={(event) => setOpenTemplateId(event.target.value)}
              disabled={busy}
            >
              <option value="">Selecione um template</option>
              {templateOptions.map((templateOption) => (
                <option key={`open-${templateOption.value}`} value={templateOption.value}>
                  {templateOption.label}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="campaign-form-open-message" className="campaign-form__label">
              Mensagem pos-resposta
            </label>
            <textarea
              id="campaign-form-open-message"
              className="app-input campaign-form__textarea"
              value={openPostResponseMessage}
              onChange={(event) => setOpenPostResponseMessage(event.target.value)}
              disabled={busy}
              rows={4}
              placeholder="Mensagem enviada apos o cliente responder"
            />
          </div>
        </div>
      ) : null}

      {type === 'free' ? (
        <div>
          <label htmlFor="campaign-form-free-message" className="campaign-form__label">
            Mensagem
          </label>
          <textarea
            id="campaign-form-free-message"
            className="app-input campaign-form__textarea"
            value={freeMessage}
            onChange={(event) => setFreeMessage(event.target.value)}
            disabled={busy}
            rows={4}
            placeholder="Mensagem da campanha free (24h)"
          />
        </div>
      ) : null}

      <div className="campaign-form__actions">
        {onCancel ? (
          <button type="button" className="app-btn-secondary" onClick={onCancel} disabled={busy}>
            Cancelar
          </button>
        ) : null}
        <button type="submit" className="app-btn-primary" disabled={busy}>
          {submitLabel}
        </button>
      </div>
    </form>
  );
}

export default CampaignForm;
