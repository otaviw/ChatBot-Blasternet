import ErrorMessage from '@/components/ui/ErrorMessage/ErrorMessage.jsx';
import LoadingSpinner from '@/components/ui/LoadingSpinner/LoadingSpinner.jsx';

function AsyncState({
  loading = false,
  error = null,
  loadingLabel = 'Carregando...',
  loadingSlot = null,
  errorSlot = null,
  errorMessage = '',
  onRetry = null,
  className = '',
  children,
}) {
  if (loading) {
    if (loadingSlot) {
      return loadingSlot;
    }

    return (
      <div className={className}>
        <LoadingSpinner label={loadingLabel} />
      </div>
    );
  }

  if (error) {
    if (errorSlot) {
      return errorSlot;
    }

    const message = errorMessage || (typeof error === 'string' ? error : '');
    return (
      <ErrorMessage
        message={message}
        onRetry={typeof onRetry === 'function' ? onRetry : undefined}
        className={className}
      />
    );
  }

  return <>{children}</>;
}

export default AsyncState;
