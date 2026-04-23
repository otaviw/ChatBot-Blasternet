import './Button.css';

const VARIANT_CLASSES = {
  primary: 'app-btn-primary',
  secondary: 'app-btn-secondary',
  ghost: 'app-btn-ghost',
  danger: 'app-btn-danger focus-visible:ring-red-200',
};

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

