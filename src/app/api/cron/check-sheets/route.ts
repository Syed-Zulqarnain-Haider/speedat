/**
 * GET /api/cron/check-sheets — run by Vercel Cron (see vercel.json). If the
 * operator expects a rate sheet by a certain hour and none has arrived
 * today, the miss is recorded in the audit log and surfaced in the admin.
 * Vercel authenticates the call with `Authorization: Bearer $CRON_SECRET`.
 */
import { NextResponse } from "next/server";
import { audit } from "@/lib/audit";
import { getIntakeSettings, latestSheetToday } from "@/lib/import/intake";

export async function GET(req: Request) {
  const secret = process.env.CRON_SECRET;
  if (!secret || req.headers.get("authorization") !== `Bearer ${secret}`) {
    return NextResponse.json({ error: { code: "unauthorized", message: "Bad or missing cron secret" } }, { status: 401 });
  }
  const settings = await getIntakeSettings();
  if (settings.expectedByHour == null) return NextResponse.json({ ok: true, checked: false, reason: "no expected hour configured" });
  const now = new Date();
  if (now.getHours() < settings.expectedByHour) return NextResponse.json({ ok: true, checked: false, reason: "before the expected hour" });
  const today = await latestSheetToday();
  if (today) return NextResponse.json({ ok: true, checked: true, missing: false, importId: today.id, status: today.status });
  await audit("cron", "intake_missing", { expectedByHour: settings.expectedByHour, date: now.toISOString().slice(0, 10) });
  return NextResponse.json({ ok: true, checked: true, missing: true });
}
