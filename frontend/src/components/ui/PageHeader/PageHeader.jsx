import './PageHeader.css';

function PageHeader({ title, subtitle = '', action = null, className = '' }) {
  return (
    <div className={['mb-6 flex flex-wrap items-start justify-between gap-4', className].join(' ').trim()}>
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight text-[#0f172a]">{title}</h1>
        {subtitle ? <p className="max-w-3xl text-sm text-[#64748b]">{subtitle}</p> : null}
      </div>
      {action}
    </div>
  );
}

export default PageHeader;

