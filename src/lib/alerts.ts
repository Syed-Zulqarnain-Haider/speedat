/**
 * Operational alerts to the office, each sent at most once per day per key
 * (state kept in app_settings so serverless instances agree). Everything
 * here is also visible in the admin, so a lost email never hides a problem.
 */
import "server-only";
import { eq } from "drizzle-orm";
import { db, schema } from "@/lib/db";
import { log } from "@/lib/log";
import { notifyOffice } from "@/lib/notify";

const KEY = "alerts";

async function sentToday(alertKey: string): Promise<boolean> {
  const [row] = await db.select().from(schema.appSettings).where(eq(schema.appSettings.key, KEY)).limit(1);
  const map = (row?.value ?? {}) as Record<string, string>;
  return map[alertKey] === new Date().toISOString().slice(0, 10);
}

async function markSent(alertKey: string): Promise<void> {
  const [row] = await db.select().from(schema.appSettings).where(eq(schema.appSettings.key, KEY)).limit(1);
  const map = { ...((row?.value ?? {}) as Record<string, string>), [alertKey]: new Date().toISOString().slice(0, 10) };
  await db
    .insert(schema.appSettings)
    .values({ key: KEY, value: map, updatedBy: "system" })
    .onConflictDoUpdate({ target: schema.appSettings.key, set: { value: map, updatedAt: new Date(), updatedBy: "system" } });
}

/** Send once per day per key. Returns whether a message went out this call. */
export async function alertOnce(alertKey: string, subject: string, text: string): Promise<boolean> {
  if (await sentToday(alertKey)) return false;
  log.warn("alert", { key: alertKey, subject });
  const sent = await notifyOffice({ subject: `[Speedat] ${subject}`, text, tag: "alert" });
  await markSent(alertKey);
  return sent;
}

/** Immediate (not deduped) notice for events that each deserve a message. */
export async function alertNow(subject: string, text: string): Promise<boolean> {
  log.warn("alert", { subject });
  return notifyOffice({ subject: `[Speedat] ${subject}`, text, tag: "alert" });
}
