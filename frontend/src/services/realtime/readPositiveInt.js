export function readPositiveInt(source, keys) {
  if (!source || typeof source !== 'object') {
    return null;
  }

  for (const key of keys) {
    const parsed = Number.parseInt(String(source[key] ?? ''), 10);
    if (parsed > 0) {
      return parsed;
    }
  }

  return null;
}
