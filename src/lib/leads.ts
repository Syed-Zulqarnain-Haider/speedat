/**
 * Leads: booked quotes and contact-form messages, worked from the admin
 * inbox. Quote leads are keyed by quote id so a customer who taps "Book"
 * twice is one lead, not two.
 */
import "server-only";
import { desc, eq, sql } from "drizzle-orm";
import { db, schema } from "@/lib/db";

export type LeadStatus = "new" | "contacted" | "booked" | "lost";
export const LEAD_STATUSES: LeadStatus[] = ["new", "contacted", "booked", "lost"];

export type Lead = typeof schema.leads.$inferSelect;

export interface MessageLeadInput {
  name: string;
  phone: string;
  email: string;
  message: string;
  destId: string | null;
  weightG: number | null;
  ipHash: string | null;
}

export async function createMessageLead(input: MessageLeadInput): Promise<Lead> {
  const [row] = await db
    .insert(schema.leads)
    .values({ kind: "message", ...input })
    .returning();
  if (!row) throw new Error("lead insert failed");
  return row;
}

/** Called when a customer taps Book on a quote; idempotent per quote id. */
export async function upsertQuoteLead(args: { quoteId: string; destId: string; weightG: number; summary: string; contents?: string }): Promise<void> {
  await db
    .insert(schema.leads)
    .values({ kind: "quote", quoteId: args.quoteId, destId: args.destId, weightG: args.weightG, message: [args.summary, args.contents ? `Contents: ${args.contents}` : ""].filter(Boolean).join("\n") })
    .onConflictDoNothing({ target: schema.leads.quoteId });
}

export async function listLeads(status: LeadStatus | "all" = "all", limit = 200): Promise<Lead[]> {
  const q = db.select().from(schema.leads);
  const rows = status === "all" ? await q.orderBy(desc(schema.leads.createdAt)).limit(limit) : await q.where(eq(schema.leads.status, status)).orderBy(desc(schema.leads.createdAt)).limit(limit);
  return rows;
}

export async function getLead(id: number): Promise<Lead | null> {
  const [row] = await db.select().from(schema.leads).where(eq(schema.leads.id, id)).limit(1);
  return row ?? null;
}

export async function updateLead(id: number, patch: { status?: LeadStatus; notes?: string }, by: string): Promise<Lead | null> {
  const [row] = await db
    .update(schema.leads)
    .set({ ...patch, updatedBy: by, updatedAt: new Date() })
    .where(eq(schema.leads.id, id))
    .returning();
  return row ?? null;
}

export interface LeadCounts {
  new: number;
  contacted: number;
  booked: number;
  lost: number;
}

export async function leadCounts(): Promise<LeadCounts> {
  const rows = await db.select({ status: schema.leads.status, n: sql<number>`count(*)::int` }).from(schema.leads).groupBy(schema.leads.status);
  const out: LeadCounts = { new: 0, contacted: 0, booked: 0, lost: 0 };
  for (const r of rows) if (r.status in out) out[r.status as LeadStatus] = r.n;
  return out;
}
