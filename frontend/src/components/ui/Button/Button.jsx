import './Button.css';

/** @typedef {import('@/types/ui').ButtonVariant} ButtonVariant */

const VARIANT_CLASSES = {
  primary: 'app-btn-primary',
  secondary: 'app-btn-secondary',
  ghost: 'app-btn-ghost',
  danger: 'app-btn-danger',
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
    'app-btn',
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

