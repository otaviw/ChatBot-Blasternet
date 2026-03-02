const LEVELS = {
  debug: 10,
  info: 20,
  warn: 30,
  error: 40,
};

const currentLevelValue = LEVELS[process.env.REALTIME_LOG_LEVEL ?? 'info'] ?? LEVELS.info;

const write = (level, message, context = {}) => {
  if ((LEVELS[level] ?? 100) < currentLevelValue) {
    return;
  }

  const record = {
    level,
    message,
    timestamp: new Date().toISOString(),
    ...context,
  };

  process.stdout.write(`${JSON.stringify(record)}\n`);
};

export const logger = {
  debug: (message, context) => write('debug', message, context),
  info: (message, context) => write('info', message, context),
  warn: (message, context) => write('warn', message, context),
  error: (message, context) => write('error', message, context),
};
