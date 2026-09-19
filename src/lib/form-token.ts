/**
 * Signed timestamps for public forms: the page embeds one when rendered,
 * the submission returns it, and we refuse anything submitted faster than a
 * human could type or older than a working day. No third-party CAPTCHA.
 */
import "server-only";
import { createHash, createHmac, timingSafeEqual } from "node:crypto";

function secret(): string {
  // APP_SECRET is the intended key; deriving from DATABASE_URL keeps the form
  // working (and stable across instances) on a deployment that forgot to set it.
  const s = process.env.APP_SECRET || process.env.DATABASE_URL;
  if (!s) throw new Error("APP_SECRET is not set");
  return createHash("sha256").update(`form:${s}`).digest("hex");
}

export function issueFormToken(now = Date.now()): string {
  const ts = String(now);
  const sig = createHmac("sha256", secret()).update(ts).digest("hex").slice(0, 32);
  return `${ts}.${sig}`;
}

export type TokenCheck = "ok" | "too_fast" | "expired" | "invalid";

export function checkFormToken(token: string | null | undefined, now = Date.now(), minMs = 3000, maxMs = 24 * 3600_000): TokenCheck {
  const m = String(token ?? "").match(/^(\d{10,16})\.([0-9a-f]{32})$/);
  if (!m) return "invalid";
  const expected = createHmac("sha256", secret()).update(m[1]!).digest("hex").slice(0, 32);
  const a = Buffer.from(m[2]!);
  const b = Buffer.from(expected);
  if (a.length !== b.length || !timingSafeEqual(a, b)) return "invalid";
  const age = now - Number(m[1]);
  if (age < minMs) return "too_fast";
  if (age > maxMs) return "expired";
  return "ok";
}
