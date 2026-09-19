/**
 * Structured JSON logs for the server. One line per event so Vercel's log
 * drain (or any collector) can filter on `event` and `level` without
 * parsing prose. Never logs secrets; callers pass fields deliberately.
 */
type Level = "info" | "warn" | "error";

export interface LogFields {
  [k: string]: unknown;
}

function emit(level: Level, event: string, fields: LogFields = {}): void {
  const line = JSON.stringify({ ts: new Date().toISOString(), level, event, ...fields });
  if (level === "error") console.error(line);
  else if (level === "warn") console.warn(line);
  else console.log(line);
}

export const log = {
  info: (event: string, fields?: LogFields) => emit("info", event, fields),
  warn: (event: string, fields?: LogFields) => emit("warn", event, fields),
  error: (event: string, fields?: LogFields) => emit("error", event, fields),
};

/** Safe summary of an error for logs (message + name, no stack in production). */
export function errInfo(err: unknown): LogFields {
  if (err instanceof Error) return { errorName: err.name, errorMessage: err.message, ...(process.env.NODE_ENV !== "production" ? { stack: err.stack } : {}) };
  return { errorMessage: String(err) };
}
