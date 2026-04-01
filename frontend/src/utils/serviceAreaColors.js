/**
 * Cores estáveis por área: ordem em `service_areas` do bot define o índice;
 * áreas desconhecidas usam hash do nome para manter sempre a mesma cor.
 */

export const SERVICE_AREA_PALETTE = [
  { bg: '#dbeafe', border: '#3b82f6', text: '#1e3a8a' },
  { bg: '#dcfce7', border: '#22c55e', text: '#14532d' },
  { bg: '#fef3c7', border: '#d97706', text: '#78350f' },
  { bg: '#ede9fe', border: '#8b5cf6', text: '#4c1d95' },
  { bg: '#fce7f3', border: '#db2777', text: '#831843' },
  { bg: '#cffafe', border: '#06b6d4', text: '#164e63' },
  { bg: '#ecfccb', border: '#65a30d', text: '#365314' },
  { bg: '#ffedd5', border: '#ea580c', text: '#7c2d12' },
  { bg: '#e0e7ff', border: '#6366f1', text: '#312e81' },
  { bg: '#fae8ff', border: '#c026d3', text: '#701a75' },
  { bg: '#ccfbf1', border: '#14b8a6', text: '#134e4a' },
  { bg: '#fee2e2', border: '#ef4444', text: '#7f1d1d' },
];

function normalizeAreaName(value) {
  return String(value ?? '')
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');
}

export function getServiceAreaColorIndex(areaName, orderedServiceAreaNames = []) {
  const n = normalizeAreaName(areaName);
  if (!n) return 0;

  const list = Array.isArray(orderedServiceAreaNames) ? orderedServiceAreaNames : [];
  const idx = list.findIndex((a) => normalizeAreaName(a) === n);
  if (idx >= 0) {
    return idx % SERVICE_AREA_PALETTE.length;
  }

  let h = 0;
  for (let i = 0; i < n.length; i += 1) {
    h = (Math.imul(31, h) + n.charCodeAt(i)) | 0;
  }
  return Math.abs(h) % SERVICE_AREA_PALETTE.length;
}

export function getServiceAreaPaletteEntry(areaName, orderedServiceAreaNames) {
  return SERVICE_AREA_PALETTE[getServiceAreaColorIndex(areaName, orderedServiceAreaNames)];
}
