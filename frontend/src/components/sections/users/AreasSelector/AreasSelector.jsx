import './AreasSelector.css';

function AreasSelector({
  title = 'Áreas de atuação',
  areas = [],
  selectedAreas = [],
  onToggle,
  emptyMessage,
}) {
  return (
    <div className="rounded-xl border border-[#d7dce6] bg-[#f8fafc] p-3 space-y-2">
      <p className="text-sm font-medium text-[#0f172a]">{title}</p>
      {!areas.length && <p className="text-xs text-[#64748b]">{emptyMessage}</p>}
      {areas.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {areas.map((area) => (
            <label
              key={area}
              className="inline-flex items-center gap-2 rounded-full border border-[#d7dce6] bg-white px-3 py-1 text-sm text-[#334155]"
            >
              <input
                type="checkbox"
                checked={selectedAreas.includes(area)}
                onChange={() => onToggle(area)}
                className="h-4 w-4 rounded border-[#cbd5e1] text-[#2563eb] focus:ring-[#2563eb]/25"
              />
              {area}
            </label>
          ))}
        </div>
      )}
    </div>
  );
}

export default AreasSelector;

