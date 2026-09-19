/**
 * GET /api/health — liveness plus a real database round-trip. 200 when the
 * site can serve prices, 503 when the database is unreachable (the site
 * itself keeps serving the last cached version meanwhile).
 */
import { sql } from "drizzle-orm";
import { NextResponse } from "next/server";
import { db } from "@/lib/db";
import { getLatestVersion } from "@/lib/site/repo";

export const dynamic = "force-dynamic";

export async function GET() {
  const version = process.env.VERCEL_GIT_COMMIT_SHA?.slice(0, 7) ?? "dev";
  const started = Date.now();
  try {
    await Promise.race([db.execute(sql`select 1`), new Promise((_, rej) => setTimeout(() => rej(new Error("db timeout")), 4000))]);
    const live = await getLatestVersion();
    return NextResponse.json({
      status: "ok",
      version,
      db: "ok",
      dbMs: Date.now() - started,
      ratesVersion: live?.version ?? 0,
      ratesPublishedAt: live?.publishedAt ?? null,
      ratesLive: live?.live ?? false,
    });
  } catch (err) {
    return NextResponse.json({ status: "degraded", version, db: "error", error: err instanceof Error ? err.message : "unknown" }, { status: 503 });
  }
}
