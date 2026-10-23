/**
 * Operational alerts to the office, each sent at most once per business day
 * per key (state kept in app_settings so serverless instances agree). Each
 * alert has its own row, `alert:<key>` → "YYYY-MM-DD" in the office's zone,
 * claimed with one conditional upsert: of any number of concurrent callers,
 * exactly one gets a row back and sends. A shared JSON map would need a
 * read-modify-write, which loses updates and double-sends under concurrency.
 * Everything here is also visible in the admin, so a lost email never hides
 * a problem.
 */
import "server-only";
import { and, eq, sql } from "drizzle-orm";
import { db, schema } from "@/lib/db";
import { errInfo, log } from "@/lib/log";
import { notifyOffice } from "@/lib/notify";
import { businessDayKey } from "@/lib/time";

const rowKey = (alertKey: string) => `alert:${alertKey}`;

/** Claim today's send for this key. True only for the one caller that moved the row to today. */
async function claim(alertKey: string, day: string): Promise<boolean> {
  const rows = await db
    .insert(schema.appSettings)
    .values({ key: rowKey(alertKey), value: day, updatedBy: "system" })
    .onConflictDoUpdate({
      target: schema.appSettings.key,
      set: { value: day, updatedAt: new Date(), updatedBy: "system" },
      setWhere: sql`${schema.appSettings.value} is distinct from excluded.value`,
    })
    .returning({ key: schema.appSettings.key });
  return rows.length > 0;
}

/**
 * Give the claim back after a failed send so the next run retries instead of
 * staying silent until tomorrow. Only today's claim is removed, so a claim
 * another day's run has since taken is left alone.
 */
async function release(alertKey: string, day: string): Promise<void> {
  await db.delete(schema.appSettings).where(and(eq(schema.appSettings.key, rowKey(alertKey)), sql`${schema.appSettings.value} = ${JSON.stringify(day)}::jsonb`));
}

export interface DedupeSteps {
  claim: () => Promise<boolean>;
  send: () => Promise<boolean>;
  release: () => Promise<void>;
}

/**
 * Claim → send → release-on-failure. A send that fails (or a provider that
 * is not configured) leaves the alert unmarked; a failed release is logged
 * and costs at most that day's retries. Separate from the database so the
 * ordering can be tested.
 */
export async function sendDeduped(steps: DedupeSteps): Promise<boolean> {
  if (!(await steps.claim())) return false;
  let sent = false;
  try {
    sent = await steps.send();
  } finally {
    if (!sent) await steps.release().catch((err) => log.error("alert_release_failed", errInfo(err)));
  }
  return sent;
}

/** Send once per business day per key. Returns whether a message went out this call. */
export async function alertOnce(alertKey: string, subject: string, text: string): Promise<boolean> {
  const day = businessDayKey();
  return sendDeduped({
    claim: () => claim(alertKey, day),
    send: () => {
      log.warn("alert", { key: alertKey, subject });
      return notifyOffice({ subject: `[Speedat] ${subject}`, text, tag: "alert" });
    },
    release: () => release(alertKey, day),
  });
}

/** Immediate (not deduped) notice for events that each deserve a message. */
export async function alertNow(subject: string, text: string): Promise<boolean> {
  log.warn("alert", { subject });
  return notifyOffice({ subject: `[Speedat] ${subject}`, text, tag: "alert" });
}
