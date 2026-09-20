/**
 * The intake pipeline: a rate sheet arrives (upload or email) and either
 * lands in the draft automatically — when its layout is already known — or
 * waits for an admin to map its columns once. Publishing is a separate step
 * unless the operator has set an auto-publish tolerance and the sheet moves
 * no price by more than that.
 */
import "server-only";
import { desc, eq } from "drizzle-orm";
import { revalidateTag } from "next/cache";
import { alertNow } from "@/lib/alerts";
import { audit } from "@/lib/audit";
import { db, schema } from "@/lib/db";
import { diffSite, summarise, validateSite } from "@/lib/site/diff";
import { SITE_TAG } from "@/lib/site/live";
import { getDraft, getLatestVersion, publishVersion, saveDraft } from "@/lib/site/repo";
import { applyImport, autoMap, buildImport, detectHeaderRow, findProfile, headerNames, headerSignature, largestMove } from "./parse";
import type { ImportOptions, ImportResult } from "./types";
import { WorkbookError, largestSheet, readWorkbook, type Workbook } from "./workbook";

export type ImportStatus = "needs_mapping" | "applied" | "published" | "rejected" | "failed";

export interface IntakeSettings {
  /** 0 = never publish automatically; otherwise the largest allowed % move. */
  autoPublishPct: number;
  /** Lower-case emails or "@domain" suffixes allowed to send sheets; empty = anyone with the webhook secret. */
  allowedSenders: string[];
  /** Hour (0–23, server local time) by which a sheet is expected each working day; null = no expectation. */
  expectedByHour: number | null;
}

export const DEFAULT_INTAKE: IntakeSettings = { autoPublishPct: 0, allowedSenders: [], expectedByHour: null };

export async function getIntakeSettings(): Promise<IntakeSettings> {
  const [row] = await db.select().from(schema.appSettings).where(eq(schema.appSettings.key, "intake")).limit(1);
  const v = (row?.value ?? {}) as Partial<IntakeSettings>;
  return {
    autoPublishPct: Number(v.autoPublishPct) || 0,
    allowedSenders: Array.isArray(v.allowedSenders) ? v.allowedSenders.map((s) => String(s).toLowerCase()) : [],
    expectedByHour: v.expectedByHour == null ? null : Number(v.expectedByHour),
  };
}

export async function setIntakeSettings(s: IntakeSettings, by: string): Promise<void> {
  await db
    .insert(schema.appSettings)
    .values({ key: "intake", value: s, updatedBy: by })
    .onConflictDoUpdate({ target: schema.appSettings.key, set: { value: s, updatedAt: new Date(), updatedBy: by } });
}

export function senderAllowed(from: string | null, allowed: string[]): boolean {
  if (!allowed.length) return true;
  const f = (from ?? "").toLowerCase().trim();
  return allowed.some((a) => (a.startsWith("@") ? f.endsWith(a) : f === a));
}

export interface ReceiveArgs {
  source: "upload" | "email";
  fileName: string;
  fromEmail: string | null;
  bytes: Uint8Array;
  actor: string;
}

export interface ReceiveResult {
  importId: number;
  status: ImportStatus;
  workbook: Workbook | null;
  sheet: string | null;
  headerRow: number;
  /** Suggested (auto or remembered) column mapping for the mapper UI. */
  map: Record<string, number>;
  profileMatched: boolean;
  result: ImportResult | null;
  publishedVersion: number | null;
  error: string | null;
}

/** Store the sheet, and for a known layout apply it to the draft (and maybe publish). */
export async function receiveSheet(args: ReceiveArgs): Promise<ReceiveResult> {
  let wb: Workbook;
  try {
    wb = readWorkbook(args.fileName, args.bytes);
  } catch (err) {
    const msg = err instanceof WorkbookError ? err.message : "The file could not be read";
    const [row] = await db
      .insert(schema.imports)
      .values({ source: args.source, fileName: args.fileName, fromEmail: args.fromEmail, status: "failed", error: msg })
      .returning({ id: schema.imports.id });
    await audit(args.actor, "import_failed", { importId: row?.id, fileName: args.fileName, error: msg });
    if (args.source === "email") void alertNow(`Rate sheet ${args.fileName} could not be read`, `${msg}. The file came from ${args.fromEmail ?? "an unknown sender"}. Ask them to resend it as .xlsx or .csv, or upload it by hand at ${process.env.NEXT_PUBLIC_SITE_URL ?? ""}/admin.`);
    return { importId: row?.id ?? 0, status: "failed", workbook: null, sheet: null, headerRow: 0, map: {}, profileMatched: false, result: null, publishedVersion: null, error: msg };
  }
  const sheet = largestSheet(wb);
  const rows = wb.sheets[sheet] ?? [];
  const headerRow = detectHeaderRow(rows);
  const headers = headerNames(rows[headerRow] ?? []);
  const draft = await getDraft();
  const profile = findProfile(draft.data.importProfiles, headerSignature(headers));
  const map = profile ? profile.map : autoMap(headers, draft.data.services, headerRow > 0 ? rows[headerRow - 1] : undefined);
  const [row] = await db
    .insert(schema.imports)
    .values({
      source: args.source,
      fileName: args.fileName,
      fromEmail: args.fromEmail,
      status: "needs_mapping",
      sheetName: sheet,
      profileSignature: profile?.signature ?? null,
      rows: wb.sheets,
    })
    .returning({ id: schema.imports.id });
  const importId = row!.id;
  await audit(args.actor, "import_received", { importId, source: args.source, fileName: args.fileName, from: args.fromEmail, profileMatched: !!profile });

  // Uploads always go through the mapper (pre-filled); only email uses the remembered layout unattended.
  if (args.source === "upload" || !profile) {
    if (args.source === "email") {
      void alertNow(
        `Rate sheet ${args.fileName} needs a column mapping`,
        `A sheet from ${args.fromEmail ?? "an unknown sender"} arrived with a layout the admin has not seen before. Nothing was changed. Open ${process.env.NEXT_PUBLIC_SITE_URL ?? ""}/admin, find it under Import from Excel and map its columns once.`,
      );
    }
    return { importId, status: "needs_mapping", workbook: wb, sheet, headerRow, map, profileMatched: !!profile, result: null, publishedVersion: null, error: null };
  }
  const opts: ImportOptions = { addNew: true, hideMissing: false, cost: profile.cost, margin: profile.margin, mround: profile.mround };
  const applied = await applyToDraft({ importId, sheet, headerRow, map, opts, actor: args.actor, fileName: args.fileName });
  return { importId, status: applied.status, workbook: wb, sheet, headerRow, map, profileMatched: true, result: applied.result, publishedVersion: applied.publishedVersion, error: applied.error };
}

export interface ApplyArgs {
  importId: number;
  sheet: string;
  headerRow: number;
  map: Record<string, number>;
  opts: ImportOptions;
  actor: string;
  fileName?: string;
}

export interface ApplyResult {
  status: ImportStatus;
  result: ImportResult | null;
  /** The draft after the import (for the admin to adopt). */
  draft: import("@/lib/site/types").SiteData | null;
  publishedVersion: number | null;
  error: string | null;
}

/** Recompute the import from the stored rows, apply it to the draft, save, and auto-publish when allowed. */
export async function applyToDraft(args: ApplyArgs): Promise<ApplyResult> {
  const [rec] = await db.select().from(schema.imports).where(eq(schema.imports.id, args.importId)).limit(1);
  if (!rec || !rec.rows) return { status: "failed", result: null, draft: null, publishedVersion: null, error: "Import not found" };
  if (rec.status === "applied" || rec.status === "published") return { status: rec.status, result: rec.result ?? null, draft: null, publishedVersion: rec.appliedVersion, error: "Already applied" };
  const rows = rec.rows[args.sheet];
  if (!rows) return { status: "failed", result: null, draft: null, publishedVersion: null, error: "Sheet not found" };
  const draft = await getDraft();
  const result = buildImport({ rows, headerRow: args.headerRow, map: args.map, opts: args.opts, draft: draft.data, fileName: args.fileName ?? rec.fileName });
  if (!result.rows.length) {
    await db.update(schema.imports).set({ result, status: "needs_mapping", error: result.errors[0] ?? "No destinations with prices were found" }).where(eq(schema.imports.id, args.importId));
    return { status: "needs_mapping", result, draft: null, publishedVersion: null, error: result.errors[0] ?? "No destinations with prices were found" };
  }
  const next = applyImport(draft.data, result);
  await saveDraft(next, args.actor);
  await db
    .update(schema.imports)
    .set({ result, status: "applied", profileSignature: result.profile?.signature ?? null, sheetName: args.sheet, decidedBy: args.actor, decidedAt: new Date(), error: null })
    .where(eq(schema.imports.id, args.importId));
  await audit(args.actor, "import_applied", { importId: args.importId, rows: result.rows.length, errors: result.errors.length, warnings: result.warnings.length });

  // Unattended path: publish only when every check passes and no price moved more than the tolerance.
  if (rec.source === "email") {
    const settings = await getIntakeSettings();
    const worst = largestMove(draft.data, result);
    const errors = validateSite(next);
    if (settings.autoPublishPct > 0 && !result.errors.length && !errors.length && (!worst || worst.pct <= settings.autoPublishPct)) {
      const live = await getLatestVersion();
      const diff = live ? diffSite(live, next) : null;
      if (diff && diff.count > 0) {
        const v = await publishVersion({ data: next, by: args.actor, source: `email:${rec.fromEmail ?? "unknown"}`, summary: `${summarise(diff)} (auto from ${rec.fileName})`, changeCount: diff.count });
        revalidateTag(SITE_TAG, "max");
        await db.update(schema.imports).set({ status: "published", appliedVersion: v.version }).where(eq(schema.imports.id, args.importId));
        await audit(args.actor, "publish", { version: v.version, auto: true, importId: args.importId, largestMovePct: worst?.pct ?? 0 });
        return { status: "published", result, draft: next, publishedVersion: v.version, error: null };
      }
    } else {
      const reason = errors[0] ?? (worst ? `${worst.label} moved ${worst.pct.toFixed(1)}%` : "auto-publish is off");
      await audit(args.actor, "import_held", { importId: args.importId, reason });
      void alertNow(
        `Rate sheet ${rec.fileName} is waiting for your review`,
        `The sheet from ${rec.fromEmail ?? "an unknown sender"} was read and applied to the editor (${result.rows.length} destinations) but not published: ${reason}.

Review and publish at ${process.env.NEXT_PUBLIC_SITE_URL ?? ""}/admin.`,
      );
    }
  }
  return { status: "applied", result, draft: next, publishedVersion: null, error: null };
}

export interface ImportSummary {
  id: number;
  receivedAt: string;
  source: string;
  fileName: string;
  fromEmail: string | null;
  status: string;
  error: string | null;
  rows: number;
  errors: number;
  warnings: number;
  appliedVersion: number | null;
}

export async function listImports(limit = 15): Promise<ImportSummary[]> {
  const rows = await db
    .select({
      id: schema.imports.id,
      receivedAt: schema.imports.receivedAt,
      source: schema.imports.source,
      fileName: schema.imports.fileName,
      fromEmail: schema.imports.fromEmail,
      status: schema.imports.status,
      error: schema.imports.error,
      result: schema.imports.result,
      appliedVersion: schema.imports.appliedVersion,
    })
    .from(schema.imports)
    .orderBy(desc(schema.imports.receivedAt))
    .limit(limit);
  return rows.map((r) => ({
    id: r.id,
    receivedAt: r.receivedAt.toISOString(),
    source: r.source,
    fileName: r.fileName,
    fromEmail: r.fromEmail,
    status: r.status,
    error: r.error,
    rows: r.result?.rows.length ?? 0,
    errors: r.result?.errors.length ?? 0,
    warnings: r.result?.warnings.length ?? 0,
    appliedVersion: r.appliedVersion,
  }));
}

/** Has a sheet arrived today (local server date)? Used by the daily check. */
export async function latestSheetToday(): Promise<ImportSummary | null> {
  const [first] = await listImports(1);
  if (!first) return null;
  const d = new Date(first.receivedAt);
  const now = new Date();
  return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate() ? first : null;
}
