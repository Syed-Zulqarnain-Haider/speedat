"use server";

/** Server actions behind the admin's "Import from Excel" section. */
import { and, eq, inArray } from "drizzle-orm";
import { z } from "zod";
import { audit } from "@/lib/audit";
import { Forbidden, requireAdmin } from "@/lib/auth/session";
import { db, schema } from "@/lib/db";
import { applyToDraft, getIntakeSettings, receiveSheet, setIntakeSettings, type ImportStatus, type IntakeSettings } from "@/lib/import/intake";
import { suggestLayout } from "@/lib/import/parse";
import type { ImportOptions, ImportResult } from "@/lib/import/types";
import { getDraft } from "@/lib/site/repo";
import type { SiteData } from "@/lib/site/types";
import { MAX_FILE_BYTES } from "@/lib/import/workbook";
import type { ActionResult } from "./actions";

export interface SheetPayload {
  importId: number;
  names: string[];
  sheets: Record<string, string[][]>;
  sheet: string;
  headerRow: number;
  map: Record<string, number>;
  profileMatched: boolean;
  fileName: string;
}

function onError(err: unknown): ActionResult<never> {
  if (err instanceof Forbidden) return { ok: false, code: "forbidden", message: err.message };
  console.error(err);
  return { ok: false, code: "error", message: "Something went wrong. Try again." };
}

export async function uploadSheetAction(form: FormData): Promise<ActionResult<SheetPayload>> {
  try {
    const user = await requireAdmin();
    const file = form.get("file");
    if (!(file instanceof File) || !file.name) return { ok: false, code: "bad_request", message: "Choose a file first." };
    if (file.size > MAX_FILE_BYTES) return { ok: false, code: "too_large", message: "That file is larger than 8 MB." };
    const bytes = new Uint8Array(await file.arrayBuffer());
    const r = await receiveSheet({ source: "upload", fileName: file.name, fromEmail: null, bytes, actor: user.email });
    if (r.status === "failed" || !r.workbook || !r.sheet) return { ok: false, code: "unreadable", message: r.error ?? "The file could not be read." };
    return { ok: true, importId: r.importId, names: r.workbook.names, sheets: r.workbook.sheets, sheet: r.sheet, headerRow: r.headerRow, map: r.map, profileMatched: r.profileMatched, fileName: file.name };
  } catch (err) {
    return onError(err);
  }
}

/** Open a sheet that arrived by email (or an earlier upload) in the mapper. */
export async function loadImportAction(importId: number): Promise<ActionResult<SheetPayload>> {
  try {
    await requireAdmin();
    const [rec] = await db.select().from(schema.imports).where(eq(schema.imports.id, importId)).limit(1);
    if (!rec || !rec.rows) return { ok: false, code: "not_found", message: "That import is no longer available." };
    const names = Object.keys(rec.rows);
    const sheet = rec.sheetName && rec.rows[rec.sheetName] ? rec.sheetName : (names[0] ?? "");
    const rows = rec.rows[sheet] ?? [];
    const draft = await getDraft();
    // A mapping tried earlier on this sheet wins; otherwise the remembered or guessed layout.
    const layout = suggestLayout(rows, draft.data.importProfiles, draft.data.services, rec.result?.headerRow);
    const map = rec.result?.map ?? layout.map;
    return { ok: true, importId, names, sheets: rec.rows, sheet, headerRow: layout.headerRow, map, profileMatched: !!layout.profile, fileName: rec.fileName };
  } catch (err) {
    return onError(err);
  }
}

const ApplyInput = z.object({
  importId: z.number().int().positive(),
  sheet: z.string().max(200),
  headerRow: z.number().int().min(0).max(5000),
  map: z.record(z.string().max(60), z.number().int().min(-1).max(200)),
  opts: z.object({ addNew: z.boolean(), hideMissing: z.boolean(), cost: z.boolean(), margin: z.number().finite(), mround: z.number().finite().min(1) }),
});

/**
 * "Apply to editor". Open to every admin, editors included, so it never
 * publishes — not even an emailed sheet under an auto-publish tolerance:
 * publishing is owner-only, and only the unattended email intake
 * (`receiveSheet`) may do it on the owner's standing instruction.
 */
export async function applyImportAction(input: unknown): Promise<ActionResult<{ draft: SiteData; result: ImportResult; status: ImportStatus; updatedAt: string }>> {
  try {
    const user = await requireAdmin();
    const parsed = ApplyInput.parse(input);
    const opts: ImportOptions = parsed.opts;
    const r = await applyToDraft({ ...parsed, opts, actor: user.email, unattended: false });
    if (!r.draft || !r.result) return { ok: false, code: r.status, message: r.error ?? "Nothing was applied." };
    // A returned draft is one applyToDraft has just saved (never published from here).
    return { ok: true, draft: r.draft, result: r.result, status: r.status, updatedAt: new Date().toISOString() };
  } catch (err) {
    if (err instanceof Error && err.name === "ZodError") return { ok: false, code: "invalid", message: "The mapping could not be read." };
    return onError(err);
  }
}

/** Statuses a sheet can be rejected from: it has changed nothing yet. */
const REJECTABLE: ImportStatus[] = ["needs_mapping", "failed"];

/**
 * Reject a sheet that is still waiting. An applied sheet's prices are already
 * in the draft; marking it rejected would leave them there to be published
 * under a "rejected" label, so it is refused — the admin edits the prices or
 * discards the draft instead (which puts the sheet back to waiting).
 */
export async function rejectImportAction(importId: number): Promise<ActionResult> {
  try {
    const user = await requireAdmin();
    const done = await db
      .update(schema.imports)
      .set({ status: "rejected", decidedBy: user.email, decidedAt: new Date() })
      .where(and(eq(schema.imports.id, importId), inArray(schema.imports.status, REJECTABLE)))
      .returning({ id: schema.imports.id });
    if (!done.length) {
      return { ok: false, code: "not_waiting", message: "Only a sheet that is still waiting can be rejected. This one is already in the editor or published — change its prices there, or discard the draft." };
    }
    await audit(user.email, "import_rejected", { importId });
    return { ok: true };
  } catch (err) {
    return onError(err);
  }
}

const IntakeInput = z.object({
  autoPublishPct: z.number().finite().min(0).max(100),
  allowedSenders: z.array(z.string().max(120)).max(50),
  expectedByHour: z.number().int().min(0).max(23).nullable(),
});

export async function getIntakeAction(): Promise<ActionResult<{ settings: IntakeSettings }>> {
  try {
    await requireAdmin();
    return { ok: true, settings: await getIntakeSettings() };
  } catch (err) {
    return onError(err);
  }
}

export async function saveIntakeAction(input: unknown): Promise<ActionResult> {
  try {
    const user = await requireAdmin("owner");
    const s = IntakeInput.parse(input);
    const settings: IntakeSettings = { ...s, allowedSenders: s.allowedSenders.map((x) => x.trim().toLowerCase()).filter(Boolean) };
    await setIntakeSettings(settings, user.email);
    await audit(user.email, "intake_settings", settings as unknown as Record<string, unknown>);
    return { ok: true };
  } catch (err) {
    if (err instanceof Error && err.name === "ZodError") return { ok: false, code: "invalid", message: "Check the values." };
    return onError(err);
  }
}
