import './Notice.css';

const TONE_CLASSES = {
  info: 'border-[#bfdbfe] bg-[#eff6ff] text-[#1e3a8a]',
  success: 'border-[#bbf7d0] bg-[#f0fdf4] text-[#166534]',
  danger: 'border-[#fecaca] bg-[#fef2f2] text-[#b91c1c]',
};

function Notice({ tone = 'info', className = '', children }) {
  const classes = [
    'rounded-xl border px-3 py-2 text-sm',
    TONE_CLASSES[tone] ?? TONE_CLASSES.info,
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return <p className={classes}>{children}</p>;
}

export default Notice;

