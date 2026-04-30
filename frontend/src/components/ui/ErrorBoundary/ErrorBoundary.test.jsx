import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ErrorBoundary from './ErrorBoundary.jsx';

const { captureException } = vi.hoisted(() => ({
  captureException: vi.fn(),
}));

vi.mock('@/lib/sentry', () => ({
  Sentry: {
    captureException,
  },
}));

function Bomb({ explode }) {
  if (explode) {
    throw new Error('boom');
  }

  return <p>Conteudo seguro</p>;
}

describe('ErrorBoundary', () => {
  let container;
  let root;
  const originalConsoleError = console.error;

  afterEach(() => {
    console.error = originalConsoleError;
    delete globalThis.IS_REACT_ACT_ENVIRONMENT;
    captureException.mockClear();
    if (root) {
      act(() => {
        root.unmount();
      });
    }
    if (container?.parentNode) {
      container.parentNode.removeChild(container);
    }
    container = null;
    root = null;
  });

  it('exibe fallback e permite retry quando erro de render acontece', () => {
    globalThis.IS_REACT_ACT_ENVIRONMENT = true;
    console.error = vi.fn();

    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);

    let explode = true;
    const renderTree = () => (
      <ErrorBoundary label="Inbox">
        <Bomb explode={explode} />
      </ErrorBoundary>
    );

    act(() => {
      root.render(renderTree());
    });

    expect(container.textContent).toContain('Algo deu errado');
    expect(container.textContent).toContain('Inbox');
    expect(captureException).toHaveBeenCalledTimes(1);

    explode = false;
    act(() => {
      root.render(renderTree());
    });

    const retryButton = container.querySelector('button');
    expect(retryButton).not.toBeNull();

    act(() => {
      retryButton.click();
    });

    expect(container.textContent).toContain('Conteudo seguro');
  });
});
