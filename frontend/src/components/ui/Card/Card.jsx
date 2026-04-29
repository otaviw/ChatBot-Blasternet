import './Card.css';

function Card({ as: Tag = 'section', className = '', children }) {
  const classes = [
    'app-card',
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return <Tag className={classes}>{children}</Tag>;
}

export default Card;

