import { useEffect } from 'react';
import { Link } from 'react-router-dom';

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
            className="text-[var(--ui-text-muted)] hover:text-[var(--ui-text)] text-lg leading-none"
            aria-label="Fechar modal de tags"
          >
            ×
          </button>
        </div>

        {/* Tags já vinculadas */}
        <div className="mb-3">
          <p className="text-[10px] uppercase font-semibold text-[var(--ui-text-subtle)] mb-1.5">Vinculadas</p>
          {(detail.tags ?? []).length === 0 ? (
            <p className="text-xs text-[var(--ui-text-muted)]">Nenhuma tag nesta conversa.</p>
          ) : (
            <div className="flex flex-wrap gap-1.5">
              {(detail.tags ?? []).map((tag) => (
                <span
                  key={tag.id}
                  className="inline-flex items-center gap-0.5 px-2.5 py-0.5 rounded-full text-xs font-medium text-white ring-1 ring-black/10 dark:ring-white/25"
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
            <div className="border-t border-[var(--ui-border)] my-3" />
            <p className="text-[10px] uppercase font-semibold text-[var(--ui-text-subtle)] mb-1.5">Adicionar</p>
            <div className="flex flex-wrap gap-1.5">
              {companyTags
                .filter((t) => !attachedIds.has(t.id))
                .map((tag) => (
                  <button
                    key={tag.id}
                    type="button"
                    onClick={() => onAttachTag(tag.id)}
                    className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium text-white opacity-70 hover:opacity-100 transition ring-1 ring-black/10 dark:ring-white/25"
                    style={{ backgroundColor: tag.color }}
                  >
                    + {tag.name}
                  </button>
                ))}
              {companyTags.filter((t) => !attachedIds.has(t.id)).length === 0 && (
                <p className="text-xs text-[var(--ui-text-muted)]">Todas as tags já estão vinculadas.</p>
              )}
            </div>
          </>
        )}

        {companyTags.length === 0 && (
          <p className="text-xs text-[var(--ui-text-muted)] mt-2">
            Crie tags em{' '}
            <Link to="/minha-conta/tags" className="text-[var(--ui-accent)] underline hover:text-[var(--ui-accent-hover)]">
              Configurações → Tags
            </Link>
            .
          </p>
        )}
      </div>
    </div>
  );
}

export default TagsModal;
