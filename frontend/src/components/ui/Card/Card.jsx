import './Card.css';

function Card({ as: Tag = 'section', className = '', children }) {
  const classes = [
    'rounded-[var(--ui-radius,12px)] border border-[#eeeeee] bg-white p-6 shadow-[var(--ui-shadow-sm,0_1px_2px_rgba(0,0,0,0.04))]',
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return <Tag className={classes}>{children}</Tag>;
}

export default Card;

