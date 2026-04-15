import Button from '@/components/ui/Button/Button.jsx';
import './ErrorPanel.css';

function ErrorIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="1.5" />
      <path d="M12 8v4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
      <circle cx="12" cy="16" r="0.75" fill="currentColor" />
    </svg>
  );
}

/**
 * ErrorPanel — painel inline para erros de fetch/API.
 *
 * Props:
 *   message  : string — mensagem principal exibida ao usuário.
 *   detail   : string — detalhe técnico opcional (ex: status code).
 *   onRetry  : () => void — se fornecido, exibe botão "Tentar novamente".
 *   className: string
 */
function ErrorPanel({ message, detail, onRetry, className = '' }) {
  const displayMessage = message || 'Não foi possível carregar os dados.';

  return (
    <div
      className={['error-panel', className].filter(Boolean).join(' ')}
      role="alert"
      aria-live="polite"
    >
      <div className="error-panel__icon-wrap" aria-hidden="true">
        <ErrorIcon />
      </div>

      <p className="error-panel__message">{displayMessage}</p>

      {detail ? <p className="error-panel__detail">{detail}</p> : null}

      {typeof onRetry === 'function' ? (
        <div className="error-panel__action">
          <Button
            type="button"
            variant="secondary"
            className="text-xs px-3 py-1.5"
            onClick={onRetry}
          >
            Tentar novamente
          </Button>
        </div>
      ) : null}
    </div>
  );
}

export default ErrorPanel;
