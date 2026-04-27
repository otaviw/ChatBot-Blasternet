import * as Sentry from '@sentry/react';

export function initSentry() {
  const dsn = import.meta.env.VITE_SENTRY_DSN;

  // Não inicializa sem DSN — evita erros silenciosos em desenvolvimento
  if (!dsn) {
    return;
  }

  Sentry.init({
    dsn,
    environment: import.meta.env.MODE,
    release: import.meta.env.VITE_APP_VERSION,
    // Captura 10% das transações para performance monitoring
    tracesSampleRate: 0.1,
    // Integra automaticamente com React Router para breadcrumbs de navegação
    integrations: [Sentry.browserTracingIntegration()],
    // Não loga erros de cancelamento de request (comportamento normal do app)
    ignoreErrors: ['ERR_CANCELED', 'CanceledError'],
  });
}

export { Sentry };
