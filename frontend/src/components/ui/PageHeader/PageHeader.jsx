import './PageHeader.css';

function PageHeader({ title, subtitle = '', action = null, className = '' }) {
  return (
    <div className={['mb-7 flex flex-wrap items-start justify-between gap-4', className].join(' ').trim()}>
      <div className="space-y-1">
        <h1 className="app-page-title">{title}</h1>
        {subtitle ? <p className="app-page-subtitle max-w-3xl">{subtitle}</p> : null}
      </div>
      {action}
    </div>
  );
}

export default PageHeader;

