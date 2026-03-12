import { REALTIME_EVENTS } from '@/constants/realtimeEvents';

const SUPPORTED_EVENTS = new Set(Object.values(REALTIME_EVENTS));

export function isSupportedRealtimeEvent(eventName) {
  return SUPPORTED_EVENTS.has(eventName);
}
