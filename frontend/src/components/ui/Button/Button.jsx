import './Button.css';

const VARIANT_CLASSES = {
  primary:
    'bg-[#2563eb] text-white hover:bg-[#1d4ed8] focus-visible:ring-[#2563eb]/25',
  secondary:
    'bg-white text-[#525252] border border-[#e5e5e5] hover:bg-[#fafafa] hover:border-[#d4d4d4] focus-visible:ring-[#2563eb]/20',
  ghost: 'bg-transparent text-[#525252] hover:bg-[#f5f5f5] focus-visible:ring-[#2563eb]/20',
  danger: 'bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 focus-visible:ring-red-200',
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

