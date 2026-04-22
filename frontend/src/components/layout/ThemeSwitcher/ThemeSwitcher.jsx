export default function ThemeSwitcher({ themeMode, onToggle }) {
  return (
    <button
      type="button"
      className={`layout-profile__item layout-profile__theme-toggle ${
        themeMode === 'dark' ? 'layout-profile__theme-toggle--active' : ''
      }`}
      onClick={onToggle}
      aria-pressed={themeMode === 'dark'}
      title="Alternar tema escuro"
    >
      <span className="layout-profile__theme-toggle-label">Tema escuro</span>
      <span className="layout-profile__theme-toggle-value">
        {themeMode === 'dark' ? 'Ativo' : 'Inativo'}
      </span>
    </button>
  );
}
