import './Button.css';

const VARIANT_CLASSES = {
  primary:
    'bg-[#2563eb] text-white hover:bg-[#1d4ed8] focus-visible:ring-[#2563eb]/35',
  secondary:
    'bg-white text-[#1e293b] border border-[#d7dce6] hover:bg-[#f8fafc] focus-visible:ring-[#93c5fd]/40',
  ghost: 'bg-transparent text-[#1e293b] hover:bg-[#f8fafc] focus-visible:ring-[#93c5fd]/40',
  danger: 'bg-[#1d4ed8] text-white hover:bg-[#1e40af] focus-visible:ring-[#bfdbfe]/40',
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
    'inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-4 disabled:cursor-not-allowed disabled:opacity-60',
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

