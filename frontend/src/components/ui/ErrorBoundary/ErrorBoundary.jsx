import { Component } from 'react';
import Button from '@/components/ui/Button/Button.jsx';
import './ErrorBoundary.css';

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

  componentDidCatch(error, info) {
    console.error('ErrorBoundary capturou um erro:', error, info);
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
            <h1 className="error-boundary__title">Algo deu errado nesta pagina</h1>
            <p className="error-boundary__subtitle">
              O sistema encontrou um erro inesperado de interface. Tente novamente ou recarregue.
            </p>
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
