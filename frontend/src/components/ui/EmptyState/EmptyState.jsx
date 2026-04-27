import Button from '@/components/ui/Button/Button.jsx';
import './EmptyState.css';

/** @typedef {import('@/types/ui').EmptyStateProps} EmptyStateProps */

function EmptyStateDefaultIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11Z"
        stroke="currentColor"
        strokeWidth="1.5"
      />
      <path d="M8 10h8M8 14h5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
    </svg>
  );
}

/** @param {EmptyStateProps} props */
function EmptyState({
  icon = null,
  title = 'Nada por aqui ainda',
  subtitle = '',
  actionLabel = '',
  onAction = null,
  className = '',
}) {
  return (
    <div className={['empty-state', className].filter(Boolean).join(' ')}>
      <div className="empty-state__icon-wrap">{icon ?? <EmptyStateDefaultIcon />}</div>
      <p className="empty-state__title">{title}</p>
      {subtitle ? <p className="empty-state__subtitle">{subtitle}</p> : null}
      {actionLabel && typeof onAction === 'function' ? (
        <div className="empty-state__action">
          <Button type="button" variant="secondary" className="text-xs px-3 py-1.5" onClick={onAction}>
            {actionLabel}
          </Button>
        </div>
      ) : null}
    </div>
  );
}

export default EmptyState;
