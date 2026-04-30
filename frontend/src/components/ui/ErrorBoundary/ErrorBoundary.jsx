import { Component } from 'react';
import Button from '@/components/ui/Button/Button.jsx';
import { Sentry } from '@/lib/sentry';
import './ErrorBoundary.css';

/**
 * Captura erros de render na árvore de children, exibe UI de fallback e reporta ao Sentry.
 *
 * Props:
 *   resetKey  {any}     — quando muda, limpa o estado de erro (usar pathname da rota).
 *   label     {string}  — nome da seção exibido no painel de erro (ex: "Inbox", "Chat").
 *   children  {node}    — árvore de componentes a proteger.
 */
class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch(error, errorInfo) {
    Sentry.captureException(error, {
      contexts: { react: { componentStack: errorInfo.componentStack } },
      tags: { section: this.props.label ?? 'unknown' },
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
    if (!this.state.hasError) {
      return this.props.children;
    }

    const { label } = this.props;

    return (
      <div className="error-boundary" role="alert">
        <div className="error-boundary__panel">
          {label && (
            <p className="error-boundary__label">{label}</p>
          )}
          <h1 className="error-boundary__title">Algo deu errado. Recarregue a página.</h1>
          <p className="error-boundary__subtitle">
            O sistema encontrou um erro inesperado de interface.
          </p>
          <div className="error-boundary__actions">
            <Button type="button" variant="secondary" onClick={this.handleTryAgain}>
              Tentar novamente
            </Button>
            <Button type="button" variant="primary" onClick={() => window.location.reload()}>
              Recarregar página
            </Button>
          </div>
        </div>
      </div>
    );
  }
}

export default ErrorBoundary;
