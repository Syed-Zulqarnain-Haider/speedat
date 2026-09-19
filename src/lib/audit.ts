/**
 * Append-only audit trail for everything an admin does. Never throws to the
 * caller: a failed audit write is logged loudly but does not undo the action
 * it describes (the action already happened; hiding it would be worse).
 */
import "server-only";
import { headers } from "next/headers";
import { db, schema } from "@/lib/db";

export async function audit(actor: string, action: string, detail: Record<string, unknown> = {}): Promise<void> {
  let ip: string | null = null;
  let userAgent: string | null = null;
  try {
    const h = await headers();
    ip = h.get("x-forwarded-for")?.split(",")[0]?.trim() ?? h.get("x-real-ip");
    userAgent = h.get("user-agent")?.slice(0, 300) ?? null;
  } catch {
    /* not in a request context (seed, cron) */
  }
  try {
    await db.insert(schema.auditLog).values({ actor, action, detail, ip, userAgent });
  } catch (err) {
    console.error("audit write failed", { actor, action }, err);
  }
}
