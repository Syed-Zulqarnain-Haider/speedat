"use server";

import { redirect } from "next/navigation";
import { z } from "zod";
import type { ActionResult } from "@/app/admin/actions";
import { audit } from "@/lib/audit";
import { Forbidden, requireAdmin } from "@/lib/auth/session";
import { getLatestVersion } from "@/lib/site/repo";
import { SHIPMENT_STATUSES, addShipmentEvent, createShipment, updateShipment, type ShipmentStatus } from "@/lib/shipments";

const Create = z.object({
  leadId: z.number().int().positive().nullable(),
  quoteId: z.string().max(40).nullable(),
  customerName: z.string().trim().max(120),
  customerPhone: z.string().trim().max(30),
  receiverName: z.string().trim().max(120),
  destId: z.string().max(60),
  serviceId: z.string().max(40),
  carrier: z.string().trim().max(80),
  trackingNo: z.string().trim().max(80),
  notes: z.string().max(4000),
});

export interface CreateState {
  error: string | null;
}

function field(form: FormData, k: string): string {
  return String(form.get(k) ?? "");
}

export async function createShipmentAction(_prev: CreateState, form: FormData): Promise<CreateState> {
  let user;
  try {
    user = await requireAdmin();
  } catch (err) {
    return { error: err instanceof Forbidden ? err.message : "Not allowed" };
  }
  const parsed = Create.safeParse({
    leadId: field(form, "leadId") ? Number(field(form, "leadId")) : null,
    quoteId: field(form, "quoteId") || null,
    customerName: field(form, "customerName"),
    customerPhone: field(form, "customerPhone"),
    receiverName: field(form, "receiverName"),
    destId: field(form, "destId"),
    serviceId: field(form, "serviceId"),
    carrier: field(form, "carrier"),
    trackingNo: field(form, "trackingNo"),
    notes: field(form, "notes"),
  });
  if (!parsed.success) return { error: "Check the values — something is out of range." };
  const live = await getLatestVersion();
  if (!live?.destinations.some((d) => d.id === parsed.data.destId)) return { error: "Choose a destination." };
  if (!live.services.some((s) => s.id === parsed.data.serviceId)) return { error: "Choose a service." };
  const row = await createShipment({ ...parsed.data, trackingNo: parsed.data.trackingNo || null }, user.email);
  await audit(user.email, "shipment_created", { shipmentId: row.id, leadId: row.leadId, quoteId: row.quoteId });
  redirect(`/admin/shipments/${row.id}`);
}

const Update = z.object({
  id: z.string().regex(/^SH-[A-Z0-9]{8}$/),
  customerName: z.string().trim().max(120).optional(),
  customerPhone: z.string().trim().max(30).optional(),
  receiverName: z.string().trim().max(120).optional(),
  carrier: z.string().trim().max(80).optional(),
  trackingNo: z.string().trim().max(80).optional(),
  notes: z.string().max(4000).optional(),
});

export async function updateShipmentAction(input: unknown): Promise<ActionResult> {
  try {
    const user = await requireAdmin();
    const { id, ...patch } = Update.parse(input);
    const row = await updateShipment(id, { ...patch, trackingNo: patch.trackingNo === undefined ? undefined : patch.trackingNo || null }, user.email);
    if (!row) return { ok: false, code: "not_found", message: "That shipment no longer exists." };
    await audit(user.email, "shipment_updated", { shipmentId: id, fields: Object.keys(patch) });
    return { ok: true };
  } catch (err) {
    if (err instanceof Forbidden) return { ok: false, code: "forbidden", message: err.message };
    if (err instanceof Error && err.name === "ZodError") return { ok: false, code: "invalid", message: "Check the values." };
    console.error(err);
    return { ok: false, code: "error", message: "Something went wrong. Try again." };
  }
}

const Event = z.object({
  id: z.string().regex(/^SH-[A-Z0-9]{8}$/),
  status: z.enum(SHIPMENT_STATUSES as unknown as [ShipmentStatus, ...ShipmentStatus[]]),
  note: z.string().max(1000),
});

export async function addEventAction(input: unknown): Promise<ActionResult<{ at: string }>> {
  try {
    const user = await requireAdmin();
    const { id, status, note } = Event.parse(input);
    const ev = await addShipmentEvent(id, status, note, user.email);
    if (!ev) return { ok: false, code: "not_found", message: "That shipment no longer exists." };
    await audit(user.email, "shipment_status", { shipmentId: id, status, note });
    return { ok: true, at: ev.at.toISOString() };
  } catch (err) {
    if (err instanceof Forbidden) return { ok: false, code: "forbidden", message: err.message };
    if (err instanceof Error && err.name === "ZodError") return { ok: false, code: "invalid", message: "Check the values." };
    console.error(err);
    return { ok: false, code: "error", message: "Something went wrong. Try again." };
  }
}
