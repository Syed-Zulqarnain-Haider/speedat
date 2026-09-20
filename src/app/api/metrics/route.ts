/**
 * GET /api/metrics — Prometheus text format, computed from the database on
 * each scrape (there is no long-lived process to keep counters in).
 * Protected: `Authorization: Bearer $METRICS_TOKEN` (CRON_SECRET also works).
 */
import { and, eq, gte, sql } from "drizzle-orm";
import { NextResponse } from "next/server";
import { db, schema } from "@/lib/db";
import { getHold } from "@/lib/site/hold";
import { getLatestVersion } from "@/lib/site/repo";

export const dynamic = "force-dynamic";

const count = async (q: Promise<{ n: number }[]>) => (await q)[0]?.n ?? 0;

export async function GET(req: Request) {
  const token = process.env.METRICS_TOKEN || process.env.CRON_SECRET;
  if (!token || req.headers.get("authorization") !== `Bearer ${token}`) return NextResponse.json({ error: { code: "unauthorized", message: "Bad or missing token" } }, { status: 401 });
  const since = new Date(Date.now() - 86_400_000);
  const n = sql<number>`count(*)::int`;
  const [quotes24h, booked24h, leadsNew, shipmentsOpen, shipmentsException, importsFailed24h, importsHeld, notifyFailed24h, live, hold] = await Promise.all([
    count(db.select({ n }).from(schema.quotes).where(gte(schema.quotes.createdAt, since))),
    count(db.select({ n }).from(schema.quotes).where(and(gte(schema.quotes.createdAt, since), eq(schema.quotes.booked, true)))),
    count(db.select({ n }).from(schema.leads).where(eq(schema.leads.status, "new"))),
    count(db.select({ n }).from(schema.shipments).where(sql`${schema.shipments.status} <> 'delivered'`)),
    count(db.select({ n }).from(schema.shipments).where(eq(schema.shipments.status, "exception"))),
    count(db.select({ n }).from(schema.imports).where(and(gte(schema.imports.receivedAt, since), eq(schema.imports.status, "failed")))),
    count(db.select({ n }).from(schema.imports).where(sql`${schema.imports.status} in ('needs_mapping', 'applied')`)),
    count(db.select({ n }).from(schema.auditLog).where(and(gte(schema.auditLog.at, since), sql`${schema.auditLog.action} in ('notify_failed', 'notify_skipped')`))),
    getLatestVersion(),
    getHold(),
  ]);
  const holdAge = hold.on && hold.since ? Math.floor((Date.now() - new Date(hold.since).getTime()) / 1000) : 0;
  const publishAge = live ? Math.floor((Date.now() - new Date(live.publishedAt).getTime()) / 1000) : -1;
  const lines = [
    "# HELP speedat_prices_held Whether prices are hidden from the website (1) or shown (0)",
    "# TYPE speedat_prices_held gauge",
    `speedat_prices_held ${hold.on ? 1 : 0}`,
    "# HELP speedat_prices_held_seconds How long prices have been on hold, 0 when shown",
    "# TYPE speedat_prices_held_seconds gauge",
    `speedat_prices_held_seconds ${holdAge}`,
    "# HELP speedat_quotes_24h Quotes logged in the last 24 hours",
    "# TYPE speedat_quotes_24h gauge",
    `speedat_quotes_24h ${quotes24h}`,
    "# HELP speedat_quotes_booked_24h Quotes where Book was tapped in the last 24 hours",
    "# TYPE speedat_quotes_booked_24h gauge",
    `speedat_quotes_booked_24h ${booked24h}`,
    "# HELP speedat_leads_new Leads waiting in the inbox",
    "# TYPE speedat_leads_new gauge",
    `speedat_leads_new ${leadsNew}`,
    "# HELP speedat_shipments_open Shipments not yet delivered",
    "# TYPE speedat_shipments_open gauge",
    `speedat_shipments_open ${shipmentsOpen}`,
    "# HELP speedat_shipments_exception Shipments needing attention",
    "# TYPE speedat_shipments_exception gauge",
    `speedat_shipments_exception ${shipmentsException}`,
    "# HELP speedat_imports_failed_24h Rate sheets that could not be read in the last 24 hours",
    "# TYPE speedat_imports_failed_24h gauge",
    `speedat_imports_failed_24h ${importsFailed24h}`,
    "# HELP speedat_imports_pending Rate sheets waiting for mapping or publish",
    "# TYPE speedat_imports_pending gauge",
    `speedat_imports_pending ${importsHeld}`,
    "# HELP speedat_notify_failed_24h Notifications not delivered in the last 24 hours",
    "# TYPE speedat_notify_failed_24h gauge",
    `speedat_notify_failed_24h ${notifyFailed24h}`,
    "# HELP speedat_rates_version Live rates version",
    "# TYPE speedat_rates_version gauge",
    `speedat_rates_version ${live?.version ?? 0}`,
    "# HELP speedat_rates_publish_age_seconds Seconds since the live rates were published",
    "# TYPE speedat_rates_publish_age_seconds gauge",
    `speedat_rates_publish_age_seconds ${publishAge}`,
    "",
  ];
  return new NextResponse(lines.join("\n"), { headers: { "content-type": "text/plain; version=0.0.4; charset=utf-8" } });
}
