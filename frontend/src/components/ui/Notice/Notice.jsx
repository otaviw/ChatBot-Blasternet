import './Notice.css';

const TONE_CLASSES = {
  info: 'app-notice--info',
  success: 'app-notice--success',
  danger: 'app-notice--danger',
};

function Notice({ tone = 'info', className = '', children }) {
  const classes = [
    'app-notice',
    TONE_CLASSES[tone] ?? TONE_CLASSES.info,
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return <p className={classes}>{children}</p>;
}

export default Notice;

