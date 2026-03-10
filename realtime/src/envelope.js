import { randomUUID } from 'node:crypto';

const ROOM_PATTERN = /^((company|user|conversation):\d+|chat:(conversation|user):\d+)$/;

const normalizeRooms = (rooms) => {
  if (!Array.isArray(rooms)) {
    return [];
  }

  return [...new Set(rooms.map((room) => String(room).trim()).filter((room) => ROOM_PATTERN.test(room)))];
};

export const normalizeEnvelope = (input) => {
  const eventName = String(input?.event ?? '').trim();
  if (eventName === '') {
    return null;
  }

  const rooms = normalizeRooms(input?.rooms);
  if (rooms.length === 0) {
    return null;
  }

  const payload = typeof input?.payload === 'object' && input.payload !== null ? input.payload : {};
  const incomingMeta = typeof input?.meta === 'object' && input.meta !== null ? input.meta : {};

  return {
    event: eventName,
    rooms,
    payload,
    meta: {
      requestId: String(incomingMeta.requestId ?? randomUUID()),
      timestamp: String(incomingMeta.timestamp ?? new Date().toISOString()),
      actorId:
        incomingMeta.actorId === null || incomingMeta.actorId === undefined
          ? null
          : Number.parseInt(String(incomingMeta.actorId), 10) || null,
    },
  };
};

export const emitEnvelope = (io, envelope) => {
  // let target = io;
  // for (const room of envelope.rooms) {
  //   target = target.to(room);
  // }

  const message = {
    event: envelope.event,
    payload: envelope.payload,
    meta: envelope.meta,
  }

  for (const room of envelope.rooms) {
    io.to(room).emit(envelope.event, message);
  }
};
