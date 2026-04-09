import { useEffect, useState } from 'react';
import useWhatsAppTemplates from '../hooks/useWhatsAppTemplates';

const CATEGORY_LABEL = {
  MARKETING: 'Marketing',
  UTILITY: 'Utilidade',
  AUTHENTICATION: 'Autenticacao',
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
  const [selectedTemplate, setSelectedTemplate] = useState('');
  const { templates, templatesLoading, templatesError, loadTemplates } = useWhatsAppTemplates();

  useEffect(() => {
    if (!open) return;
    setSelectedTemplate('');
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

  const contactLabel = detail.customer_name
    ? `${detail.customer_name} (${detail.customer_phone})`
    : detail.customer_phone;

  const handleConfirm = () => {
    onConfirm(selectedTemplate || 'iniciar_conversa');
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
                  key={`${template.name}-${template.language}`}
                  template={template}
                  selected={selectedTemplate === template.name}
                  onSelect={setSelectedTemplate}
                />
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
            disabled={busy || !!success || (!selectedTemplate && templates.length > 0)}
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
