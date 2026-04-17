import './LoadingSpinner.css';

function LoadingSpinner({ label = 'Carregando...', className = '', size = 'md' }) {
  return (
    <div className={['loading-spinner', className].filter(Boolean).join(' ')} role="status" aria-live="polite">
      <span
        className={['loading-spinner__indicator', `loading-spinner__indicator--${size}`].join(' ')}
        aria-hidden="true"
      />
      <span className="loading-spinner__label">{label}</span>
    </div>
  );
}

export default LoadingSpinner;
