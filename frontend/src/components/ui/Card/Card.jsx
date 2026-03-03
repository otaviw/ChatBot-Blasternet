import './Card.css';

function Card({ as: Tag = 'section', className = '', children }) {
  const classes = [
    'rounded-2xl border border-[#d7dce6] bg-white/95 p-5 shadow-[0_14px_30px_-22px_rgba(15,23,42,0.8)] backdrop-blur',
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return <Tag className={classes}>{children}</Tag>;
}

export default Card;

