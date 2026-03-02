const toInt = (value, fallback) => {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(parsed) ? parsed : fallback;
};

const parseOrigins = (input) => {
  const raw = String(input ?? '').trim();
  if (raw === '') {
    return [];
  }

  return raw
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
};

const requiredEnv = (name) => {
  const value = String(process.env[name] ?? '').trim();
  if (value === '') {
    throw new Error(`Missing required env: ${name}`);
  }

  return value;
};

export const config = {
  nodeEnv: process.env.NODE_ENV ?? 'development',
  host: process.env.REALTIME_HOST ?? '0.0.0.0',
  port: toInt(process.env.REALTIME_PORT, 8081),
  corsOrigins: parseOrigins(process.env.REALTIME_CORS_ORIGINS),
  jwt: {
    secret: requiredEnv('REALTIME_JWT_SECRET'),
    issuer: process.env.REALTIME_JWT_ISSUER ?? 'http://localhost',
    audience: process.env.REALTIME_JWT_AUDIENCE ?? 'realtime',
  },
  redis: {
    url: process.env.REALTIME_REDIS_URL ?? 'redis://127.0.0.1:6379',
    channel: process.env.REALTIME_REDIS_CHANNEL ?? 'realtime.events',
  },
  internal: {
    key: requiredEnv('REALTIME_INTERNAL_KEY'),
  },
  logLevel: process.env.REALTIME_LOG_LEVEL ?? 'info',
};
