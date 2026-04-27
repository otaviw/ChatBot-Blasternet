/**
 * Definições de tipos JSDoc para os componentes UI compartilhados.
 *
 * Como usar no VSCode / WebStorm:
 *   import { } from '@/types/ui'; // não é necessário importar — o JSDoc funciona globalmente
 *   // Basta que o arquivo exista no projeto para o IDE reconhecer os tipos.
 *
 * Os tipos são DOCUMENTAÇÃO. O runtime não valida — use o console do browser em dev
 * para identificar problemas de props passadas erradas.
 */

/**
 * Variantes visuais do componente Button.
 * @typedef {'primary' | 'secondary' | 'ghost' | 'danger'} ButtonVariant
 */

/**
 * Props do componente Button.
 * @typedef {Object} ButtonProps
 * @property {'button' | 'submit' | 'reset'} [type='button'] - Tipo HTML do botão.
 * @property {ButtonVariant} [variant='secondary'] - Estilo visual.
 * @property {string} [className=''] - Classes CSS extras.
 * @property {boolean} [disabled=false] - Desabilita o botão.
 * @property {React.ReactNode} children - Conteúdo interno.
 */

/**
 * Props do componente EmptyState.
 * @typedef {Object} EmptyStateProps
 * @property {React.ReactNode} [icon=null] - Ícone customizado. Usa ícone padrão se omitido.
 * @property {string} [title='Nada por aqui ainda'] - Texto principal.
 * @property {string} [subtitle=''] - Texto secundário opcional.
 * @property {string} [actionLabel=''] - Label do botão de ação. Oculto se vazio.
 * @property {(() => void) | null} [onAction=null] - Callback do botão de ação.
 * @property {string} [className=''] - Classes CSS extras.
 */

/**
 * Props do componente LoadingSkeleton.
 * @typedef {Object} LoadingSkeletonProps
 * @property {string} [className=''] - Classes CSS para controlar tamanho (ex: "h-4 w-10/12").
 */

/**
 * Props do componente ConfirmDialog.
 * @typedef {Object} ConfirmDialogProps
 * @property {boolean} [open=false] - Controla visibilidade do diálogo.
 * @property {string} [title='Confirmar ação'] - Título do diálogo.
 * @property {string} [description=''] - Texto explicativo opcional.
 * @property {string} [confirmLabel='Confirmar'] - Label do botão de confirmação.
 * @property {string} [cancelLabel='Cancelar'] - Label do botão de cancelar.
 * @property {'danger' | 'primary'} [confirmTone='danger'] - Cor do botão de confirmação.
 * @property {boolean} [busy=false] - Mostra estado de loading e bloqueia interações.
 * @property {(() => void) | null} [onConfirm=null] - Callback ao confirmar.
 * @property {(() => void) | null} [onClose=null] - Callback ao cancelar ou fechar.
 */

/**
 * Props do componente ErrorMessage.
 * @typedef {Object} ErrorMessageProps
 * @property {string} [message] - Mensagem de erro. Usa texto genérico se omitido.
 * @property {string} [detail=''] - Detalhes técnicos adicionais.
 * @property {(() => void) | null} [onRetry=null] - Exibe botão "Tentar novamente" se fornecido.
 * @property {string} [retryLabel='Tentar novamente'] - Label do botão de retry.
 * @property {string} [className=''] - Classes CSS extras.
 */

/**
 * Props do componente PageState.
 *
 * PageState orquestra os três estados de uma seção: loading, erro e vazio.
 * Renderiza `children` apenas quando todos os três estão falsos.
 *
 * @typedef {Object} PageStateProps
 * @property {boolean} [loading=false] - Exibe spinner enquanto true.
 * @property {React.ReactNode} [loadingSlot=null] - Substituí o spinner padrão quando fornecido.
 * @property {any} [error=null] - Qualquer valor truthy exibe o ErrorMessage.
 * @property {string} [errorMessage=''] - Mensagem customizada de erro.
 * @property {(() => void) | null} [onRetry=null] - Habilita botão "Tentar novamente" no erro.
 * @property {boolean} [empty=false] - Exibe EmptyState quando true (e não loading/error).
 * @property {string} [emptyTitle='Nada por aqui ainda'] - Título do EmptyState.
 * @property {string} [emptySubtitle=''] - Subtítulo do EmptyState.
 * @property {React.ReactNode} [emptyIcon=null] - Ícone do EmptyState.
 * @property {string} [emptyActionLabel=''] - Label da ação do EmptyState.
 * @property {(() => void) | null} [onEmptyAction=null] - Callback da ação do EmptyState.
 * @property {string} [className=''] - Classes CSS do wrapper dos estados.
 * @property {React.ReactNode} children - Conteúdo exibido quando não há loading/erro/vazio.
 */

/**
 * Props do componente ErrorBoundary.
 * @typedef {Object} ErrorBoundaryProps
 * @property {React.ReactNode} children - Árvore de componentes protegida.
 * @property {any} [resetKey] - Quando este valor muda, o erro é limpo e os children são remontados.
 */

export {};
