import Button from '@/components/ui/Button/Button.jsx';
import './ErrorMessage.css';

function ErrorMessageIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="1.5" />
      <path d="M12 8v4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
      <circle cx="12" cy="16" r="0.75" fill="currentColor" />
    </svg>
  );
}

function ErrorMessage({ message, detail = '', onRetry = null, retryLabel = 'Tentar novamente', className = '' }) {
  const displayMessage = message || 'Não foi possível carregar os dados.';

  return (
    <div
      className={['error-message', className].filter(Boolean).join(' ')}
      role="alert"
      aria-live="polite"
    >
      <div className="error-message__icon-wrap" aria-hidden="true">
        <ErrorMessageIcon />
      </div>

      <p className="error-message__title">{displayMessage}</p>
      {detail ? <p className="error-message__detail">{detail}</p> : null}

      {typeof onRetry === 'function' ? (
        <div className="error-message__action">
          <Button type="button" variant="secondary" className="text-xs px-3 py-1.5" onClick={onRetry}>
            {retryLabel}
          </Button>
        </div>
      ) : null}
    </div>
  );
}

export default ErrorMessage;
