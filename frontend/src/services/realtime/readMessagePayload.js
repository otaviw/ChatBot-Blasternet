export function readMessagePayload(payload) {
  if (!payload || typeof payload !== 'object') {
    return null;
  }

  const nested = payload.message;
  return nested && typeof nested === 'object' ? nested : null;
}
