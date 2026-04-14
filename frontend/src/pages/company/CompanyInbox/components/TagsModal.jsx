import { useEffect } from 'react';

function TagsModal({
  open,
  detail,
  companyTags = [],
  onAttachTag,
  onDetachTag,
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

  const attachedIds = new Set((detail.tags ?? []).map((t) => t.id));

  return (
    <div className="inbox-tags-modal-overlay" onClick={onClose} role="presentation">
      <div
        className="inbox-tags-modal"
        role="dialog"
        aria-modal="true"
        aria-label="Gerenciar tags da conversa"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-semibold">Tags da conversa</h3>
          <button
            type="button"
            onClick={onClose}
            className="text-[#525252] hover:text-[#171717] text-lg leading-none"
            aria-label="Fechar modal de tags"
          >
            ×
          </button>
        </div>

        {/* Tags já vinculadas */}
        <div className="mb-3">
          <p className="text-[10px] uppercase font-semibold text-[#a3a3a3] mb-1.5">Vinculadas</p>
          {(detail.tags ?? []).length === 0 ? (
            <p className="text-xs text-[#737373]">Nenhuma tag nesta conversa.</p>
          ) : (
            <div className="flex flex-wrap gap-1.5">
              {(detail.tags ?? []).map((tag) => (
                <span
                  key={tag.id}
                  className="inline-flex items-center gap-0.5 px-2.5 py-0.5 rounded-full text-xs font-medium text-white"
                  style={{ backgroundColor: tag.color }}
                >
                  {tag.name}
                  <button
                    type="button"
                    onClick={() => onDetachTag(tag.id)}
                    className="ml-0.5 opacity-80 hover:opacity-100 leading-none"
                    aria-label={`Remover tag ${tag.name}`}
                  >
                    ×
                  </button>
                </span>
              ))}
            </div>
          )}
        </div>

        {/* Tags disponíveis para vincular */}
        {companyTags.length > 0 && (
          <>
            <div className="border-t border-[#f0f0f0] my-3" />
            <p className="text-[10px] uppercase font-semibold text-[#a3a3a3] mb-1.5">Adicionar</p>
            <div className="flex flex-wrap gap-1.5">
              {companyTags
                .filter((t) => !attachedIds.has(t.id))
                .map((tag) => (
                  <button
                    key={tag.id}
                    type="button"
                    onClick={() => onAttachTag(tag.id)}
                    className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium text-white opacity-70 hover:opacity-100 transition"
                    style={{ backgroundColor: tag.color }}
                  >
                    + {tag.name}
                  </button>
                ))}
              {companyTags.filter((t) => !attachedIds.has(t.id)).length === 0 && (
                <p className="text-xs text-[#737373]">Todas as tags já estão vinculadas.</p>
              )}
            </div>
          </>
        )}

        {companyTags.length === 0 && (
          <p className="text-xs text-[#737373] mt-2">
            Crie tags em{' '}
            <a href="/minha-conta/tags" className="text-[#2563eb] underline">
              Configurações → Tags
            </a>
            .
          </p>
        )}
      </div>
    </div>
  );
}

export default TagsModal;
