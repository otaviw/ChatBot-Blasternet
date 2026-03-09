function TagsModal({
  open,
  detail,
  tagInput,
  onTagInputChange,
  onAddTag,
  onRemoveTag,
  onClose,
}) {
  if (!open || !detail) {
    return null;
  }

  return (
    <div className="inbox-tags-modal-overlay" onClick={onClose}>
      <div className="inbox-tags-modal" onClick={(event) => event.stopPropagation()}>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-base font-medium">Tags</h3>
          <button type="button" onClick={onClose} className="text-[#525252] hover:text-[#171717]">
            ✕
          </button>
        </div>
        <div className="flex flex-wrap gap-1 mb-3">
          {(detail.tags ?? []).length === 0 && <span className="text-xs text-[#737373]">Nenhuma tag.</span>}
          {(detail.tags ?? []).map((tag) => (
            <span key={tag} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#f0f0f0] text-xs">
              {tag}
              <button type="button" onClick={() => onRemoveTag(tag)} className="text-[#706f6c] hover:text-red-600">
                ×
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
            className="flex-1 app-input text-sm py-1.5"
          />
          <button type="button" onClick={onAddTag} className="app-btn-primary text-sm py-1.5">
            Adicionar
          </button>
        </div>
      </div>
    </div>
  );
}

export default TagsModal;
