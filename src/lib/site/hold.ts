/**
 * The price hold: an admin's switch that takes every price off the website
 * at once, without publishing anything. It lives OUTSIDE the published
 * document on purpose — turning it on must never push a half-keyed draft,
 * and turning it off must never need a diff. While it is on, the quote page
 * shows the hold message and a WhatsApp form instead of the calculator, and
 * no price reaches the browser. Publishing new rates lifts it (the publisher
 * chooses), as does the Resume button. Server-only.
 */
import { eq } from "drizzle-orm";
import { db, schema } from "@/lib/db";
import { cleanHoldMessage, type Hold } from "./hold-shared";

export { cleanHoldMessage, DEFAULT_HOLD_MESSAGE, HOLD_MESSAGE_MAX, NO_HOLD, type Hold } from "./hold-shared";

export const HOLD_KEY = "hold";

export async function getHold(): Promise<Hold> {
  const [row] = await db.select().from(schema.appSettings).where(eq(schema.appSettings.key, HOLD_KEY)).limit(1);
  const v = (row?.value ?? {}) as Partial<Hold>;
  return {
    on: v.on === true,
    message: cleanHoldMessage(v.message),
    by: typeof v.by === "string" ? v.by : "",
    since: typeof v.since === "string" ? v.since : null,
  };
}

/** Switch the hold on or off. Returns what is now stored. */
export async function setHold(on: boolean, by: string, message?: string): Promise<Hold> {
  const current = await getHold();
  const next: Hold = { on, message: message === undefined ? current.message : cleanHoldMessage(message), by, since: new Date().toISOString() };
  await db
    .insert(schema.appSettings)
    .values({ key: HOLD_KEY, value: next, updatedBy: by })
    .onConflictDoUpdate({ target: schema.appSettings.key, set: { value: next, updatedAt: new Date(), updatedBy: by } });
  return next;
}
