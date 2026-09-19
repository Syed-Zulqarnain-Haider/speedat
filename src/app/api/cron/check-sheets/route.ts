/**
 * GET /api/cron/check-sheets — run by Vercel Cron (see vercel.json), every
 * two hours. Checks, in order, and emails the office at most once a day each:
 *  1. no rate sheet arrived by the expected hour,
 *  2. rate sheets are waiting for mapping or publish,
 *  3. the live rates are older than the staleness threshold.
 * Vercel authenticates the call with `Authorization: Bearer $CRON_SECRET`.
 */
import { NextResponse } from "next/server";
import { alertOnce } from "@/lib/alerts";
import { audit } from "@/lib/audit";
import { getIntakeSettings, latestSheetToday, listImports } from "@/lib/import/intake";
import { getLatestVersion } from "@/lib/site/repo";

export const dynamic = "force-dynamic";

const STALE_DAYS = Number(process.env.RATES_STALE_DAYS ?? 7);

export async function GET(req: Request) {
  const secret = process.env.CRON_SECRET;
  if (!secret || req.headers.get("authorization") !== `Bearer ${secret}`) {
    return NextResponse.json({ error: { code: "unauthorized", message: "Bad or missing cron secret" } }, { status: 401 });
  }
  const base = process.env.NEXT_PUBLIC_SITE_URL ?? "";
  const out: Record<string, unknown> = { ok: true };
  const settings = await getIntakeSettings();
  const now = new Date();

  // 1. Missing sheet
  if (settings.expectedByHour != null && now.getHours() >= settings.expectedByHour) {
    const today = await latestSheetToday();
    out.sheetMissing = !today;
    if (!today) {
      await audit("cron", "intake_missing", { expectedByHour: settings.expectedByHour, date: now.toISOString().slice(0, 10) });
      out.sheetAlert = await alertOnce(
        "sheet-missing",
        `No rate sheet received today by ${settings.expectedByHour}:00`,
        `No airline rate sheet has arrived at the intake address today (expected by ${settings.expectedByHour}:00).\n\nThe website is still serving the last published rates. If the airline sent nothing, no action is needed; otherwise forward the sheet or upload it at ${base}/admin.`,
      );
    }
  } else out.sheetMissing = null;

  // 2. Sheets waiting
  const pending = (await listImports(20)).filter((i) => i.status === "needs_mapping" || i.status === "applied");
  out.pending = pending.length;
  if (pending.length) {
    const lines = pending.map((p) => `• ${p.fileName} (${p.status === "applied" ? "in the editor, not published" : "needs a column mapping"}, ${p.receivedAt.slice(0, 16).replace("T", " ")})`);
    out.pendingAlert = await alertOnce("sheets-pending", `${pending.length} rate sheet${pending.length === 1 ? "" : "s"} waiting for you`, `${lines.join("\n")}\n\nOpen ${base}/admin and look under Import from Excel.`);
  }

  // 3. Stale rates
  const live = await getLatestVersion();
  const ageDays = live ? Math.floor((now.getTime() - new Date(live.publishedAt).getTime()) / 86_400_000) : null;
  out.ratesAgeDays = ageDays;
  if (ageDays != null && ageDays >= STALE_DAYS) {
    await audit("cron", "rates_stale", { ageDays, version: live?.version });
    out.staleAlert = await alertOnce("rates-stale", `Rates were last published ${ageDays} days ago`, `Version ${live?.version} has been live since ${live?.publishedAt.slice(0, 10)}. If newer airline rates exist, import and publish them at ${base}/admin.`);
  }
  return NextResponse.json(out);
}
