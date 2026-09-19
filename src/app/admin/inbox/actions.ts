"use server";

import { z } from "zod";
import type { ActionResult } from "@/app/admin/actions";
import { audit } from "@/lib/audit";
import { Forbidden, requireAdmin } from "@/lib/auth/session";
import { LEAD_STATUSES, updateLead, type LeadStatus } from "@/lib/leads";

const Input = z.object({
  id: z.number().int().positive(),
  status: z.enum(LEAD_STATUSES as [LeadStatus, ...LeadStatus[]]).optional(),
  notes: z.string().max(4000).optional(),
});

export async function updateLeadAction(input: unknown): Promise<ActionResult<{ status: string; notes: string; updatedAt: string }>> {
  try {
    const user = await requireAdmin();
    const { id, status, notes } = Input.parse(input);
    const row = await updateLead(id, { status, notes }, user.email);
    if (!row) return { ok: false, code: "not_found", message: "That lead no longer exists." };
    await audit(user.email, "lead_updated", { leadId: id, status, notesChanged: notes != null });
    return { ok: true, status: row.status, notes: row.notes, updatedAt: row.updatedAt.toISOString() };
  } catch (err) {
    if (err instanceof Forbidden) return { ok: false, code: "forbidden", message: err.message };
    if (err instanceof Error && err.name === "ZodError") return { ok: false, code: "invalid", message: "Check the values." };
    console.error(err);
    return { ok: false, code: "error", message: "Something went wrong. Try again." };
  }
}
