# Design Tokens (Frontend)

Este projeto usa tokens CSS globais em `src/styles/globals.css` para padronizar UI.

## Categorias

- Cores:
  - `--color-bg-*`, `--color-surface-*`, `--color-text-*`, `--color-border-*`
  - `--color-accent-*` (mapeadas da identidade da marca)
  - `--color-success-*`, `--color-danger-*`, `--color-warning-*`, `--color-info-*`
- Spacing:
  - `--space-1` a `--space-10`
- Tipografia:
  - `--font-size-*`, `--line-height-*`, `--font-weight-*`, `--font-family-base`
- Radius:
  - `--radius-xs` a `--radius-xl`, `--radius-pill`
- Sombras:
  - `--shadow-xs`, `--shadow-sm`, `--shadow-md`, `--shadow-lg`

## Compatibilidade com legado

Os aliases `--ui-*` continuam disponíveis (ex.: `--ui-bg`, `--ui-text`, `--ui-shadow`), agora apontando para os tokens novos.

## Componentes padronizados

- `Button` (`app-btn`, `app-btn-primary|secondary|ghost|danger`)
- `Card` (`app-card`)
- `FormControls` (`app-input`, `app-field-label`, `app-checkbox`)
- `Notice` (`app-notice`, `app-notice--info|success|danger`)
- `EmptyState`, `ErrorMessage`, `ConfirmDialog`, `AppToaster` com tokens sem hardcode de cor.
