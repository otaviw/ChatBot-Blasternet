import { useEffect, useState } from 'react';
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
  for (const component of components) {
    const text = JSON.stringify(component ?? {});
    if (!text) continue;
    const matches = text.matchAll(/\{\{\s*(\d+)\s*\}\}/g);
    for (const match of matches) {
      const parsed = Number.parseInt(match[1], 10);
      if (Number.isInteger(parsed) && parsed > 0) {
        if (!variablesMap.has(parsed)) {
          const surrounding = text.slice(Math.max(0, match.index - 16), Math.min(text.length, (match.index ?? 0) + match[0].length + 16)).trim();
          variablesMap.set(parsed, surrounding || `{{${parsed}}}`);
        }
      }
    }
  }

  return [...variablesMap.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([index, hint]) => ({ index, hint }));
};

function TemplateCard({ template, selected, onSelect }) {
  return (
    <button
      type="button"
      onClick={() => onSelect(template.name)}
      className={`w-full text-left px-3 py-2 rounded-lg border transition text-xs ${
        selected
          ? 'border-blue-500 bg-blue-50'
          : 'border-[#e5e5e5] hover:border-[#cbd5e1] hover:bg-[#f8fafc]'
      }`}
      aria-label={`Selecionar template ${template.name}`}
    >
      <div className="font-medium text-[#0f172a]">{template.name}</div>
      <div className="text-[#737373] mt-0.5">
        {CATEGORY_LABEL[template.category] ?? template.category}
        {' · '}
        {template.language}
      </div>
    </button>
  );
}

function SendTemplateModal({ open, onClose, onConfirm, detail, busy, error, success }) {
  const [selectedTemplateKey, setSelectedTemplateKey] = useState('');
  const [templateVariables, setTemplateVariables] = useState([]);
  const { templates, templatesLoading, templatesError, loadTemplates } = useWhatsAppTemplates();

  useEffect(() => {
    if (!open) return;
    setSelectedTemplateKey('');
    setTemplateVariables([]);
    loadTemplates();
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

  if (!open || !detail) return null;

  const selectedTemplateData = templates.find((template) => templateKey(template) === selectedTemplateKey) ?? null;
  const bodyVariables = extractBodyVariables(selectedTemplateData);
  const allVariablesFilled = bodyVariables.every((_, index) => String(templateVariables[index] ?? '').trim() !== '');

  const contactLabel = detail.customer_name
    ? `${detail.customer_name} (${detail.customer_phone})`
    : detail.customer_phone;

  const handleConfirm = () => {
    onConfirm(
      selectedTemplateData?.name || 'iniciar_conversa',
      bodyVariables.map((_, index) => String(templateVariables[index] ?? '').trim())
    );
  };

  return (
    <div className="inbox-tags-modal-overlay" onClick={onClose} role="presentation">
      <div
        className="inbox-tags-modal"
        style={{ maxWidth: 420, width: '100%' }}
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label="Enviar template"
      >
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-semibold">Enviar template</h3>
          <button
            type="button"
            onClick={onClose}
            className="text-[#525252] hover:text-[#171717]"
            aria-label="Fechar modal de envio de template"
          >
            x
          </button>
        </div>

        <p className="text-xs text-[#374151] mb-0.5">Para:</p>
        <p className="text-xs font-medium text-[#0f172a] mb-3">{contactLabel}</p>

        {detail.status === 'closed' ? (
          <p className="text-xs text-[#737373] mb-3 bg-amber-50 border border-amber-200 rounded px-2 py-1.5">
            A conversa esta encerrada e sera reaberta apos o envio.
          </p>
        ) : null}

        <div className="mb-3">
          <p className="text-xs font-medium text-[#374151] mb-1.5">Selecione o template:</p>

          {templatesLoading ? (
            <p className="text-xs text-[#737373] py-2">Carregando templates da Meta...</p>
          ) : null}

          {!templatesLoading && templatesError ? (
            <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1.5">
              {templatesError}
            </p>
          ) : null}

          {!templatesLoading && !templatesError && templates.length === 0 ? (
            <p className="text-xs text-[#737373]">Nenhum template aprovado encontrado.</p>
          ) : null}

      {!templatesLoading && templates.length > 0 ? (
            <div className="flex flex-col gap-1.5 max-h-52 overflow-y-auto pr-1">
              {templates.map((template) => (
                <TemplateCard
                  key={templateKey(template)}
                  template={template}
                  selected={selectedTemplateKey === templateKey(template)}
                  onSelect={() => {
                    const currentKey = templateKey(template);
                    setSelectedTemplateKey(currentKey);
                    const selected = templates.find((item) => templateKey(item) === currentKey);
                    const indexes = extractBodyVariables(selected);
                    setTemplateVariables((previous) => indexes.map((_, index) => previous[index] ?? ''));
                  }}
                />
              ))}
            </div>
          ) : null}

          {!templatesLoading && selectedTemplateData && bodyVariables.length > 0 ? (
            <div className="mt-2 space-y-1.5">
              <p className="text-xs text-[#525252]">
                Este template exige {bodyVariables.length} variavel(is) de corpo.
              </p>
              {bodyVariables.map((variable, index) => (
                <div key={`send-template-variable-${variable.index}`} className="space-y-1">
                  <p className="text-[11px] text-[#6b7280]">
                    Variavel {`{{${variable.index}}}`} • Contexto: {variable.hint}
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
                    placeholder={`Valor para {{${variable.index}}}`}
                    disabled={busy}
                    className="w-full app-input text-xs py-1.5"
                  />
                </div>
              ))}
            </div>
          ) : null}
        </div>

        {success ? <p className="text-xs text-green-600 mb-2">{success}</p> : null}
        {error ? <p className="text-xs text-red-600 mb-2">{error}</p> : null}

        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="app-btn-secondary text-xs py-1.5"
          >
            Cancelar
          </button>
          <button
            type="button"
            onClick={handleConfirm}
            disabled={busy || !!success || (!selectedTemplateData && templates.length > 0) || (bodyVariables.length > 0 && !allVariablesFilled)}
            className="app-btn-primary text-xs py-1.5"
          >
            {busy ? 'Enviando...' : 'Enviar template'}
          </button>
        </div>
      </div>
    </div>
  );
}

export default SendTemplateModal;
