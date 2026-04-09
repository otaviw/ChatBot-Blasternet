import { useEffect } from 'react';

function TagsModal({
  open,
  detail,
  tagInput,
  onTagInputChange,
  onAddTag,
  onRemoveTag,
  onClose,
}) {
  useEffect(() => {
    if (!open) return undefined;

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') {
        onClose();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [open, onClose]);

  if (!open || !detail) {
    return null;
  }

  return (
    <div className="inbox-tags-modal-overlay" onClick={onClose} role="presentation">
      <div
        className="inbox-tags-modal"
        role="dialog"
        aria-modal="true"
        aria-label="Gerenciar tags da conversa"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="flex items-center justify-between mb-2">
          <h3 className="text-sm font-semibold">Tags</h3>
          <button
            type="button"
            onClick={onClose}
            className="text-[#525252] hover:text-[#171717]"
            aria-label="Fechar modal de tags"
          >
            x
          </button>
        </div>
        <div className="flex flex-wrap gap-1 mb-2">
          {(detail.tags ?? []).length === 0 ? (
            <span className="text-xs text-[#737373]">Nenhuma tag.</span>
          ) : null}
          {(detail.tags ?? []).map((tag) => (
            <span key={tag} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#f0f0f0] text-xs">
              {tag}
              <button
                type="button"
                onClick={() => onRemoveTag(tag)}
                className="text-[#706f6c] hover:text-red-600"
                aria-label={`Remover tag ${tag}`}
              >
                x
              </button>
            </span>
          ))}
        </div>
        <div className="flex gap-2">
          <input
            type="text"
            value={tagInput}
            onChange={(event) => onTagInputChange(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault();
                onAddTag();
              }
            }}
            placeholder="Nova tag..."
            className="flex-1 app-input text-xs py-1.5"
          />
          <button type="button" onClick={onAddTag} className="app-btn-primary text-xs py-1.5">
            Adicionar
          </button>
        </div>
      </div>
    </div>
  );
}

export default TagsModal;
