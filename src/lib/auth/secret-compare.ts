/**
 * Constant-time comparison of shared secrets (cron, metrics, inbound
 * webhook). A plain `!==` returns as soon as a byte differs, which leaks how
 * much of a guess was right through response timing. Both sides are hashed to
 * fixed-length digests first so `timingSafeEqual` (which demands equal
 * lengths) never short-circuits on, or reveals, the secret's length.
 * Pure: no database, safe to import from tests.
 */
import { createHash, timingSafeEqual } from "node:crypto";

const digest = (s: string) => createHash("sha256").update(s, "utf8").digest();

/** True when `given` equals `secret`. An empty or missing secret never matches. */
export function safeEqual(given: string | null | undefined, secret: string | null | undefined): boolean {
  if (!secret || given == null) return false;
  return timingSafeEqual(digest(given), digest(secret));
}

/** True when an `Authorization` header is exactly `Bearer <secret>`. */
export function bearerMatches(header: string | null | undefined, secret: string | null | undefined): boolean {
  if (!secret || !header?.startsWith("Bearer ")) return false;
  return safeEqual(header.slice("Bearer ".length), secret);
}
