import ErrorMessage from '@/components/ui/ErrorMessage/ErrorMessage.jsx';

function ErrorPanel({ message, detail = '', onRetry = null, className = '' }) {
  return (
    <ErrorMessage
      message={message}
      detail={detail}
      onRetry={onRetry}
      className={className}
    />
  );
}

export default ErrorPanel;
