import { useEffect, useRef, useState } from 'react';
import useWhatsAppTemplates from '../hooks/useWhatsAppTemplates';

const CATEGORY_LABEL = {
  MARKETING: 'Marketing',
  UTILITY: 'Utilidade',
  AUTHENTICATION: 'Autenticacao',
};

const templateKey = (template) => `${template?.name ?? ''}__${template?.language ?? ''}`;

const extractBodyVariables = (template) => {
  const components = Array.isArray(template?.components) ? template.components : [];
  const variablesMap = new Map();
  const exampleByToken = new Map();

  for (const component of components) {
    const example = component?.example;
    if (!example || typeof example !== 'object') continue;
    for (const value of Object.values(example)) {
      if (!Array.isArray(value)) continue;
      for (const item of value) {
        const token = String(item?.param_name ?? '').trim();
        const sample = String(item?.example ?? '').trim();
        if (token && sample && !exampleByToken.has(token)) {
          exampleByToken.set(token, sample);
        }
      }
    }
  }

  for (const component of components) {
    const text = String(component?.text ?? JSON.stringify(component ?? {}));
    if (!text) continue;
    const matches = text.matchAll(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g);
    for (const match of matches) {
      const token = String(match[1] ?? '').trim();
      if (!token || variablesMap.has(token)) continue;
      const surrounding = text.slice(Math.max(0, match.index - 16), Math.min(text.length, (match.index ?? 0) + match[0].length + 16)).trim();
      variablesMap.set(token, surrounding || `{{${token}}}`);
    }
  }

  return [...variablesMap.entries()]
    .map(([token, hint]) => ({ token, hint, example: exampleByToken.get(token) ?? '' }));
};

function NewConversationModal({ open, onClose, onSubmit, busy, error }) {
  const [phone, setPhone] = useState('');
  const [name, setName] = useState('');
  const [sendTemplate, setSendTemplate] = useState(true);
  const [selectedTemplateKey, setSelectedTemplateKey] = useState('');
  const [templateVariables, setTemplateVariables] = useState([]);
  const phoneRef = useRef(null);

  const { templates, templatesLoading, templatesError, loadTemplates } = useWhatsAppTemplates();

  useEffect(() => {
    if (!open) return;

    setPhone('');
    setName('');
    setSendTemplate(true);
    setSelectedTemplateKey('');
    setTemplateVariables([]);
    loadTemplates();
    setTimeout(() => phoneRef.current?.focus(), 50);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return undefined;

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') onClose();
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [open, onClose]);

  if (!open) return null;

  const selectedTemplateData = templates.find((template) => templateKey(template) === selectedTemplateKey) ?? null;
  const bodyVariables = extractBodyVariables(selectedTemplateData);
  const allVariablesFilled = bodyVariables.every((_, index) => String(templateVariables[index] ?? '').trim() !== '');

  const handleSubmit = (event) => {
    event.preventDefault();
    const templateName = sendTemplate ? (selectedTemplateData?.name || 'iniciar_conversa') : null;
    onSubmit({
      phone: phone.trim(),
      name: name.trim(),
      sendTemplate,
      templateName,
      templateVariables: bodyVariables.map((_, index) => String(templateVariables[index] ?? '').trim()),
    });
  };

  const canSubmit = phone.trim()
    && (!sendTemplate || selectedTemplateData || templates.length === 0)
    && (!sendTemplate || bodyVariables.length === 0 || allVariablesFilled);

  return (
    <div className="inbox-tags-modal-overlay" onClick={onClose} role="presentation">
      <div
        className="inbox-tags-modal"
        style={{ maxWidth: 420, width: '100%' }}
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label="Nova conversa"
      >
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-semibold">Nova conversa</h3>
          <button
            type="button"
            onClick={onClose}
            className="text-[var(--ui-text-muted)] hover:text-[var(--ui-text)]"
            aria-label="Fechar modal de nova conversa"
          >
            x
          </button>
        </div>

        <form onSubmit={handleSubmit} className="flex flex-col gap-3">
          <div>
            <label className="block text-xs text-[var(--ui-text-muted)] mb-1">
              Telefone <span className="text-red-600">*</span>
            </label>
            <input
              ref={phoneRef}
              type="tel"
              value={phone}
              onChange={(event) => setPhone(event.target.value)}
              placeholder="5511999999999"
              required
              disabled={busy}
              className="w-full app-input text-xs py-1.5"
            />
            <p className="text-[10px] text-[var(--ui-text-subtle)] mt-0.5">
              Com codigo do pais e DDD, sem espacos ou tracos.
            </p>
          </div>

          <div>
            <label className="block text-xs text-[var(--ui-text-muted)] mb-1">Nome (opcional)</label>
            <input
              type="text"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="Nome do contato"
              disabled={busy}
              className="w-full app-input text-xs py-1.5"
            />
          </div>

          <label className="flex items-center gap-2 cursor-pointer select-none">
            <input
              type="checkbox"
              checked={sendTemplate}
              onChange={(event) => setSendTemplate(event.target.checked)}
              disabled={busy}
              className="rounded"
            />
            <span className="text-xs text-[var(--ui-text-muted)]">Enviar template ao criar</span>
          </label>

          {sendTemplate ? (
            <div>
              <p className="text-xs font-medium text-[var(--ui-text-muted)] mb-1.5">Selecione o template:</p>

              {templatesLoading ? (
                <p className="text-xs text-[var(--ui-text-muted)] py-1">Carregando templates da Meta...</p>
              ) : null}

              {!templatesLoading && templatesError ? (
                <p className="text-xs text-amber-800 dark:text-amber-200 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 rounded px-2 py-1.5">
                  {templatesError}
                </p>
              ) : null}

              {!templatesLoading && !templatesError && templates.length === 0 ? (
                <p className="text-xs text-[var(--ui-text-muted)]">
                  Nenhum template aprovado encontrado. Sera usado <strong>iniciar_conversa</strong>.
                </p>
              ) : null}

              {!templatesLoading && templates.length > 0 ? (
                <div className="flex flex-col gap-1.5 max-h-44 overflow-y-auto pr-1">
                  {templates.map((template) => (
                    <button
                      key={templateKey(template)}
                      type="button"
                      onClick={() => {
                        const currentKey = templateKey(template);
                        setSelectedTemplateKey(currentKey);
                        const indexes = extractBodyVariables(template);
                        setTemplateVariables((previous) => indexes.map((_, index) => previous[index] ?? ''));
                      }}
                      className={`w-full text-left px-3 py-2 rounded-lg border transition text-xs ${
                        selectedTemplateKey === templateKey(template)
                          ? 'border-[var(--ui-accent)] bg-[var(--ui-accent-muted)] dark:bg-[color-mix(in_srgb,var(--ui-accent)_22%,var(--ui-surface))]'
                          : 'border-[var(--ui-border)] hover:border-[var(--ui-border-strong)] hover:bg-[var(--ui-surface-elevated)] dark:hover:bg-[color-mix(in_srgb,var(--ui-surface-elevated)_80%,black)]'
                      }`}
                      aria-label={`Selecionar template ${template.name}`}
                    >
                      <div className="font-medium text-[var(--ui-text)]">{template.name}</div>
                      <div className="text-[var(--ui-text-muted)] mt-0.5">
                        {CATEGORY_LABEL[template.category] ?? template.category}
                        {' · '}
                        {template.language}
                      </div>
                    </button>
                  ))}
                </div>
              ) : null}

              {!templatesLoading && selectedTemplateData && bodyVariables.length > 0 ? (
                <div className="mt-2 space-y-1.5">
                  <p className="text-xs text-[var(--ui-text-muted)]">
                    Este template exige {bodyVariables.length} variavel(is).
                  </p>
                  {bodyVariables.map((variable, index) => (
                    <div key={`new-conversation-template-variable-${variable.token}`} className="space-y-1">
                      <p className="text-[11px] text-[var(--ui-text-subtle)]">
                        Variavel {`{{${variable.token}}}`} • Contexto: {variable.hint}
                        {variable.example ? ` • Exemplo: ${variable.example}` : ''}
                      </p>
                      <input
                        type="text"
                        value={templateVariables[index] ?? ''}
                        onChange={(event) => {
                          const value = event.target.value;
                          setTemplateVariables((previous) =>
                            previous.map((item, currentIndex) => (currentIndex === index ? value : item))
                          );
                        }}
                        placeholder={`Valor para {{${variable.token}}}`}
                        disabled={busy}
                        className="w-full app-input text-xs py-1.5"
                      />
                    </div>
                  ))}
                </div>
              ) : null}
            </div>
          ) : null}

          {error ? <p className="text-xs text-red-600">{error}</p> : null}

          <div className="flex justify-end gap-2 pt-1">
            <button
              type="button"
              onClick={onClose}
              disabled={busy}
              className="app-btn-secondary text-xs py-1.5"
            >
              Cancelar
            </button>
            <button
              type="submit"
              disabled={busy || !canSubmit}
              className="app-btn-primary text-xs py-1.5"
            >
              {busy ? 'Criando...' : 'Criar conversa'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default NewConversationModal;
