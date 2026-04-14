import { useState } from 'react';

const STATUS_OPTIONS = [
  { value: '', label: 'Qualquer status' },
  { value: 'open', label: 'Aberta' },
  { value: 'in_progress', label: 'Em atendimento' },
  { value: 'closed', label: 'Encerrada' },
];

function ConversationsFilter({ filters, onFiltersChange, serviceAreaNames = [], attendants = [], companyTags = [] }) {
  const [open, setOpen] = useState(false);

  const activeCount = Object.values(filters).filter(Boolean).length;

  function handleChange(key, value) {
    onFiltersChange({ ...filters, [key]: value });
  }

  function handleClear() {
    onFiltersChange({ status: '', area: '', attendant_id: '', tag_id: '', date_from: '', date_to: '' });
  }

  return (
    <div className="inbox-filter-wrap">
      <button
        type="button"
        className={`inbox-filter-toggle${activeCount > 0 ? ' inbox-filter-toggle--active' : ''}`}
        onClick={() => setOpen((prev) => !prev)}
        aria-expanded={open}
      >
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path d="M1 3h14M4 8h8M7 13h2" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
        </svg>
        Filtros
        {activeCount > 0 && (
          <span className="inbox-filter-badge">{activeCount}</span>
        )}
      </button>

      {open && (
        <div className="inbox-filter-panel">
          <div className="inbox-filter-row">
            <label className="inbox-filter-label">Status</label>
            <select
              className="inbox-filter-select app-input"
              value={filters.status}
              onChange={(e) => handleChange('status', e.target.value)}
            >
              {STATUS_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>{opt.label}</option>
              ))}
            </select>
          </div>

          {serviceAreaNames.length > 0 && (
            <div className="inbox-filter-row">
              <label className="inbox-filter-label">Área</label>
              <select
                className="inbox-filter-select app-input"
                value={filters.area}
                onChange={(e) => handleChange('area', e.target.value)}
              >
                <option value="">Qualquer área</option>
                {serviceAreaNames.map((name) => (
                  <option key={name} value={name}>{name}</option>
                ))}
              </select>
            </div>
          )}

          {attendants.length > 0 && (
            <div className="inbox-filter-row">
              <label className="inbox-filter-label">Atendente</label>
              <select
                className="inbox-filter-select app-input"
                value={filters.attendant_id}
                onChange={(e) => handleChange('attendant_id', e.target.value)}
              >
                <option value="">Qualquer atendente</option>
                {attendants.map((att) => (
                  <option key={att.id} value={String(att.id)}>{att.name}</option>
                ))}
              </select>
            </div>
          )}

          {companyTags.length > 0 && (
            <div className="inbox-filter-row">
              <label className="inbox-filter-label">Tag</label>
              <select
                className="inbox-filter-select app-input"
                value={filters.tag_id}
                onChange={(e) => handleChange('tag_id', e.target.value)}
              >
                <option value="">Qualquer tag</option>
                {companyTags.map((tag) => (
                  <option key={tag.id} value={String(tag.id)}>{tag.name}</option>
                ))}
              </select>
            </div>
          )}

          <div className="inbox-filter-row">
            <label className="inbox-filter-label">De</label>
            <input
              type="date"
              className="inbox-filter-select app-input"
              value={filters.date_from}
              onChange={(e) => handleChange('date_from', e.target.value)}
            />
          </div>

          <div className="inbox-filter-row">
            <label className="inbox-filter-label">Até</label>
            <input
              type="date"
              className="inbox-filter-select app-input"
              value={filters.date_to}
              onChange={(e) => handleChange('date_to', e.target.value)}
            />
          </div>

          {activeCount > 0 && (
            <button type="button" className="inbox-filter-clear" onClick={handleClear}>
              Limpar filtros
            </button>
          )}
        </div>
      )}
    </div>
  );
}

export default ConversationsFilter;
