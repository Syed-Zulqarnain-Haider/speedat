/**
 * Per-IP limits that hold across serverless instances: a fixed-window
 * counter row per (bucket, ip, window) in Postgres, incremented atomically.
 * Old windows are swept opportunistically. Addresses are stored hashed.
 */
import "server-only";
import { createHash } from "node:crypto";
import { and, lt, sql } from "drizzle-orm";
import { headers } from "next/headers";
import { db, schema } from "@/lib/db";

export async function clientIp(): Promise<string> {
  try {
    const h = await headers();
    return h.get("x-forwarded-for")?.split(",")[0]?.trim() || h.get("x-real-ip") || "unknown";
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
    console.error("rate limit unavailable; allowing", err);
    return { ok: true, remaining: limit, retryAfter };
  }
}
