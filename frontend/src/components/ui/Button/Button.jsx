import './Button.css';

/** @typedef {import('@/types/ui').ButtonVariant} ButtonVariant */

const VARIANT_CLASSES = {
  primary: 'app-btn-primary',
  secondary: 'app-btn-secondary',
  ghost: 'app-btn-ghost',
  danger: 'app-btn-danger focus-visible:ring-red-200',
};

/**
 * Botão base do sistema. Suporta 4 variantes visuais e repassa atributos HTML nativos.
 *
 * @param {import('@/types/ui').ButtonProps & React.ButtonHTMLAttributes<HTMLButtonElement>} props
 */
function Button({
  type = 'button',
  variant = 'secondary',
  className = '',
  disabled = false,
  children,
  ...props
}) {
  const classes = [
    'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-50',
    VARIANT_CLASSES[variant] ?? VARIANT_CLASSES.secondary,
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <button type={type} className={classes} disabled={disabled} {...props}>
      {children}
    </button>
  );
}

export default Button;

