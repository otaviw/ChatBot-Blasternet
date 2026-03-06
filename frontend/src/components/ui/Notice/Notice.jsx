import './Notice.css';

const TONE_CLASSES = {
  info: 'border-blue-200 bg-blue-50/80 text-blue-900',
  success: 'border-emerald-200 bg-emerald-50/80 text-emerald-800',
  danger: 'border-red-200 bg-red-50/80 text-red-800',
};

function Notice({ tone = 'info', className = '', children }) {
  const classes = [
    'rounded-lg border px-4 py-3 text-sm',
    TONE_CLASSES[tone] ?? TONE_CLASSES.info,
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return <p className={classes}>{children}</p>;
}

export default Notice;

