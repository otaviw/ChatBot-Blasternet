export function normalizeServiceAreas(rawAreas) {
  const normalizedAreasMap = new Map();
  for (const rawArea of rawAreas ?? []) {
    const label = String(rawArea ?? '').trim();
    if (!label) continue;
    const key = label.toLowerCase();
    if (!normalizedAreasMap.has(key)) {
      normalizedAreasMap.set(key, label);
    }
  }
  return [...normalizedAreasMap.values()];
}

export function normalizeKeywordReplies(rawReplies) {
  return (rawReplies ?? []).filter((item) => item?.keyword?.trim() && item?.reply?.trim());
}

export function coerceInactivityCloseHours(value, fallback = 24) {
  const parsed = Number(value ?? fallback);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

