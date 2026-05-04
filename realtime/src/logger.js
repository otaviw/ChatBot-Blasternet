const LEVELS = {
  debug: 10,
  info: 20,
  warn: 30,
  error: 40,
};

const currentLevelValue = LEVELS[process.env.REALTIME_LOG_LEVEL ?? 'info'] ?? LEVELS.info;
const MAX_STRING_LEN = 500;

const sanitizeString = (value) =>
  String(value)
    .replace(/[\r\n\t]+/g, ' ')
    .slice(0, MAX_STRING_LEN);

const sanitizeValue = (value) => {
  if (typeof value === 'string') {
    return sanitizeString(value);
  }

  if (Array.isArray(value)) {
    return value.slice(0, 50).map(sanitizeValue);
  }

  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value)
        .slice(0, 50)
        .map(([key, item]) => [sanitizeString(key), sanitizeValue(item)])
    );
  }

  return value;
};

const write = (level, message, context = {}) => {
  if ((LEVELS[level] ?? 100) < currentLevelValue) {
    return;
  }

  const record = {
    level,
    message: sanitizeString(message),
    timestamp: new Date().toISOString(),
    ...sanitizeValue(context),
  };

  process.stdout.write(`${JSON.stringify(record)}\n`);
};

export const logger = {
  debug: (message, context) => write('debug', message, context),
  info: (message, context) => write('info', message, context),
  warn: (message, context) => write('warn', message, context),
  error: (message, context) => write('error', message, context),
};
