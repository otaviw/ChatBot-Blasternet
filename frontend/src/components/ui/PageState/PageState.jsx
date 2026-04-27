import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
import ErrorMessage from '@/components/ui/ErrorMessage/ErrorMessage.jsx';
import LoadingSpinner from '@/components/ui/LoadingSpinner/LoadingSpinner.jsx';
import './PageState.css';

/** @typedef {import('@/types/ui').PageStateProps} PageStateProps */

/**
 * PageState — orquestrador de estados de uma seção ou página inteira.
 *
 * Elimina o padrão repetitivo:
 *   if (loading) return <Layout><p>Carregando...</p></Layout>
 *   if (error)   return <Layout><p>Erro...</p></Layout>
 *
 * Substituído por:
 *   <Layout>
 *     <PageState loading={loading} error={error} onRetry={refetch}>
 *       <ConteúdoReal />
 *     </PageState>
 *   </Layout>
 *
 * Props de loading:
 *   loading      : boolean
 *   loadingSlot  : ReactNode — substitui o loading padrão se fornecido
 *
 * Props de erro:
 *   error        : any — truthy → exibe ErrorMessage
 *   errorMessage : string — mensagem customizada; padrão genérico se omitido
 *   onRetry      : () => void — habilita botão "Tentar novamente"
 *
 * Props de vazio:
 *   empty           : boolean — exibe EmptyState quando true (e não loading/error)
 *   emptyTitle      : string
 *   emptySubtitle   : string
 *   emptyIcon       : ReactNode
 *   emptyActionLabel: string
 *   onEmptyAction   : () => void
 *
 * Outras:
 *   className: string — aplicado ao wrapper do slot de erro/loading/vazio
 *
 * @param {PageStateProps} props
 */
function PageState({
  // loading
  loading = false,
  loadingSlot = null,
  // error
  error = null,
  errorMessage = '',
  onRetry = null,
  // empty
  empty = false,
  emptyTitle = 'Nada por aqui ainda',
  emptySubtitle = '',
  emptyIcon = null,
  emptyActionLabel = '',
  onEmptyAction = null,
  // misc
  className = '',
  children,
}) {
  if (loading) {
    if (loadingSlot) {
      return <div className={['page-state-loading', className].filter(Boolean).join(' ')}>{loadingSlot}</div>;
    }
    return (
      <div className={['page-state-loading', className].filter(Boolean).join(' ')}>
        <LoadingSpinner label="Carregando..." />
      </div>
    );
  }

  if (error) {
    const message = errorMessage || (typeof error === 'string' ? error : '');
    return (
      <ErrorMessage
        message={message}
        onRetry={typeof onRetry === 'function' ? onRetry : undefined}
        className={className}
      />
    );
  }

  if (empty) {
    return (
      <EmptyState
        title={emptyTitle}
        subtitle={emptySubtitle}
        icon={emptyIcon || null}
        actionLabel={emptyActionLabel}
        onAction={typeof onEmptyAction === 'function' ? onEmptyAction : null}
        className={className}
      />
    );
  }

  return <>{children}</>;
}

export default PageState;
