import { Component } from 'react';
import Button from '@/components/ui/Button/Button.jsx';
import { Sentry } from '@/lib/sentry';
import './ErrorBoundary.css';

/** @typedef {import('@/types/ui').ErrorBoundaryProps} ErrorBoundaryProps */

/**
 * Captura erros de render na árvore de children, exibe UI de fallback e reporta ao Sentry.
 * Use `resetKey` para limpar o erro quando o contexto da página muda.
 *
 * @extends {Component<ErrorBoundaryProps>}
 */
class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = {
      hasError: false,
    };
  }

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch(error, errorInfo) {
    Sentry.captureException(error, {
      contexts: { react: { componentStack: errorInfo.componentStack } },
    });
  }

  componentDidUpdate(prevProps) {
    const { resetKey } = this.props;
    if (this.state.hasError && resetKey !== prevProps.resetKey) {
      this.setState({ hasError: false });
    }
  }

  handleTryAgain = () => {
    this.setState({ hasError: false });
  };

  render() {
    if (this.state.hasError) {
      return (
        <div className="error-boundary">
          <div className="error-boundary__panel">
            <h1 className="error-boundary__title">Algo deu errado. Recarregue a pagina.</h1>
            <p className="error-boundary__subtitle">O sistema encontrou um erro inesperado de interface.</p>
            <div className="error-boundary__actions">
              <Button type="button" variant="secondary" onClick={this.handleTryAgain}>
                Tentar novamente
              </Button>
              <Button type="button" variant="primary" onClick={() => window.location.reload()}>
                Recarregar pagina
              </Button>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}

export default ErrorBoundary;
