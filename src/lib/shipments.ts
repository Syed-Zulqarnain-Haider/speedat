/**
 * Shipments: a booked job moving through statuses until delivered. Ids are
 * random (not sequential); every status change is an event with a note,
 * written by a named admin. Customers get updates on WhatsApp.
 */
import "server-only";
import { randomBytes } from "node:crypto";
import { desc, eq, sql } from "drizzle-orm";
import { db, schema } from "@/lib/db";

export const SHIPMENT_STATUSES = ["booked", "picked_up", "in_transit", "customs", "out_for_delivery", "delivered", "exception"] as const;
export type ShipmentStatus = (typeof SHIPMENT_STATUSES)[number];

export const STATUS_LABEL: Record<ShipmentStatus, string> = {
  booked: "Booked",
  picked_up: "Picked up",
  in_transit: "In transit",
  customs: "At customs",
  out_for_delivery: "Out for delivery",
  delivered: "Delivered",
  exception: "Needs attention",
};

/** What the customer reads for each status in WhatsApp updates. */
export const STATUS_CUSTOMER_TEXT: Record<ShipmentStatus, string> = {
  booked: "Your shipment is booked. We will collect it and hand it to the airline.",
  picked_up: "We have collected your shipment and it is being prepared for the flight.",
  in_transit: "Your shipment is on its way to the destination country.",
  customs: "Your shipment is with customs at the destination. This can take a day or two.",
  out_for_delivery: "Your shipment is out for delivery today.",
  delivered: "Your shipment has been delivered.",
  exception: "There is a hold-up with your shipment. We are on it and will update you shortly.",
};

const ALPHABET = "23456789ABCDEFGHJKMNPQRSTVWXYZ"; // no 0/O/1/I/L/U

export function newShipmentId(): string {
  const bytes = randomBytes(8);
  let s = "";
  for (let i = 0; i < 8; i++) s += ALPHABET[bytes[i]! % ALPHABET.length];
  return `SH-${s}`;
}

export type Shipment = typeof schema.shipments.$inferSelect;
export type ShipmentEvent = typeof schema.shipmentEvents.$inferSelect;

export interface CreateShipmentInput {
  leadId: number | null;
  quoteId: string | null;
  customerName: string;
  customerPhone: string;
  receiverName: string;
  destId: string;
  serviceId: string;
  carrier: string;
  trackingNo: string | null;
  notes: string;
}

export async function createShipment(input: CreateShipmentInput, by: string): Promise<Shipment> {
  return db.transaction(async (tx) => {
    let id = newShipmentId();
    for (let i = 0; i < 5; i++) {
      const [clash] = await tx.select({ id: schema.shipments.id }).from(schema.shipments).where(eq(schema.shipments.id, id)).limit(1);
      if (!clash) break;
      id = newShipmentId();
    }
    const [row] = await tx
      .insert(schema.shipments)
      .values({ id, ...input, status: "booked", createdBy: by })
      .returning();
    if (!row) throw new Error("shipment insert failed");
    await tx.insert(schema.shipmentEvents).values({ shipmentId: id, status: "booked", note: "", by });
    if (input.leadId) await tx.update(schema.leads).set({ status: "booked", updatedBy: by, updatedAt: new Date() }).where(eq(schema.leads.id, input.leadId));
    return row;
  });
}

export interface UpdateShipmentInput {
  customerName?: string;
  customerPhone?: string;
  receiverName?: string;
  carrier?: string;
  trackingNo?: string | null;
  notes?: string;
}

export async function updateShipment(id: string, patch: UpdateShipmentInput, by: string): Promise<Shipment | null> {
  void by;
  const [row] = await db
    .update(schema.shipments)
    .set({ ...patch, updatedAt: new Date() })
    .where(eq(schema.shipments.id, id))
    .returning();
  return row ?? null;
}

export async function addShipmentEvent(id: string, status: ShipmentStatus, note: string, by: string): Promise<ShipmentEvent | null> {
  return db.transaction(async (tx) => {
    const [s] = await tx.update(schema.shipments).set({ status, updatedAt: new Date() }).where(eq(schema.shipments.id, id)).returning({ id: schema.shipments.id });
    if (!s) return null;
    const [ev] = await tx.insert(schema.shipmentEvents).values({ shipmentId: id, status, note, by }).returning();
    return ev ?? null;
  });
}

export async function listShipments(status: ShipmentStatus | "open" | "all" = "open", limit = 200): Promise<Shipment[]> {
  const base = db.select().from(schema.shipments);
  if (status === "all") return base.orderBy(desc(schema.shipments.updatedAt)).limit(limit);
  if (status === "open") return base.where(sql`${schema.shipments.status} <> 'delivered'`).orderBy(desc(schema.shipments.updatedAt)).limit(limit);
  return base.where(eq(schema.shipments.status, status)).orderBy(desc(schema.shipments.updatedAt)).limit(limit);
}

export async function getShipment(id: string): Promise<{ shipment: Shipment; events: ShipmentEvent[] } | null> {
  const [shipment] = await db.select().from(schema.shipments).where(eq(schema.shipments.id, id)).limit(1);
  if (!shipment) return null;
  const events = await db.select().from(schema.shipmentEvents).where(eq(schema.shipmentEvents.shipmentId, id)).orderBy(schema.shipmentEvents.at);
  return { shipment, events };
}

export async function shipmentCounts(): Promise<{ open: number; exception: number }> {
  const rows = await db.select({ status: schema.shipments.status, n: sql<number>`count(*)::int` }).from(schema.shipments).groupBy(schema.shipments.status);
  let open = 0;
  let exception = 0;
  for (const r of rows) {
    if (r.status !== "delivered") open += r.n;
    if (r.status === "exception") exception = r.n;
  }
  return { open, exception };
}
