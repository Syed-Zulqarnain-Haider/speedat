/**
 * Per-IP limits that hold across serverless instances: a fixed-window
 * counter row per (bucket, ip, window) in Postgres, incremented atomically.
 * Old windows are swept opportunistically. Addresses are stored hashed.
 * The `ip` argument is any subject string — an address, an admin's email
 * (per-account limits) or a form token's signature (per-token use caps).
 */
import "server-only";
import { createHash } from "node:crypto";
import { and, lt, sql } from "drizzle-orm";
import { headers } from "next/headers";
import { db, schema } from "@/lib/db";
import { errInfo, log } from "@/lib/log";

interface HeaderReader {
  get(name: string): string | null;
}

/**
 * Which address to throttle. `x-forwarded-for` is a list the client can start
 * itself: proxies append the address they saw, so only the right-most entries
 * are trustworthy and the left-most one is whatever an attacker typed —
 * trusting it lets anyone mint a fresh "IP" per request and walk past every
 * limit. Preference order:
 *  1. `x-vercel-forwarded-for` — set by Vercel's edge, which overwrites any
 *     client-sent copy;
 *  2. `x-real-ip` — set by the platform (Vercel, or nginx `real_ip`) from the
 *     connecting peer;
 *  3. the LAST `x-forwarded-for` hop — the address our own front proxy saw.
 * Behind extra proxies of our own the last hop would be one of them; set the
 * platform header there rather than reading further left.
 */
export function pickClientIp(h: HeaderReader): string {
  const first = (v: string | null) => v?.split(",")[0]?.trim() || "";
  const vercel = first(h.get("x-vercel-forwarded-for"));
  if (vercel) return vercel;
  const real = h.get("x-real-ip")?.trim();
  if (real) return real;
  const hops = (h.get("x-forwarded-for") ?? "").split(",").map((x) => x.trim()).filter(Boolean);
  return hops[hops.length - 1] || "unknown";
}

export async function clientIp(): Promise<string> {
  try {
    return pickClientIp(await headers());
  } catch {
    return "unknown";
  }
}

export function ipHash(ip: string): string {
  const salt = process.env.IP_HASH_SALT ?? "speedat";
  return createHash("sha256").update(`${salt}:${ip}`).digest("hex").slice(0, 32);
}

export interface LimitResult {
  ok: boolean;
  remaining: number;
  /** Seconds until the window resets. */
  retryAfter: number;
}

/** Allow `limit` hits per `windowSec` for this bucket+ip. Fails open if the database is unreachable. */
export async function rateLimit(bucket: string, ip: string, limit: number, windowSec: number): Promise<LimitResult> {
  const key = `${bucket}:${ipHash(ip)}`;
  const now = Date.now();
  const windowStart = new Date(Math.floor(now / (windowSec * 1000)) * windowSec * 1000);
  const retryAfter = Math.ceil((windowStart.getTime() + windowSec * 1000 - now) / 1000);
  try {
    const [row] = await db
      .insert(schema.rateLimits)
      .values({ key, windowStart, count: 1 })
      .onConflictDoUpdate({ target: [schema.rateLimits.key, schema.rateLimits.windowStart], set: { count: sql`${schema.rateLimits.count} + 1` } })
      .returning({ count: schema.rateLimits.count });
    const count = row?.count ?? 1;
    if (Math.random() < 0.02) {
      // Sweep windows older than a day, rarely, without holding anyone up.
      void db.delete(schema.rateLimits).where(and(lt(schema.rateLimits.windowStart, new Date(now - 86_400_000)))).catch(() => {});
    }
    return { ok: count <= limit, remaining: Math.max(0, limit - count), retryAfter };
  } catch (err) {
    // Fail open: a database blip must not lock the office out of the admin or
    // customers out of the contact form. Logged as an error (not a warning)
    // so an outage that silently disables every limit shows up in alerts.
    log.error("rate_limit_unavailable_failing_open", { bucket, ...errInfo(err) });
    return { ok: true, remaining: limit, retryAfter };
  }
}
