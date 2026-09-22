/**
 * The intake pipeline: a rate sheet arrives (upload or email) and either
 * lands in the draft automatically — when its layout is already known — or
 * waits for an admin to map its columns once. Publishing is a separate step
 * unless the operator has set an auto-publish tolerance and the sheet moves
 * no price by more than that.
 */
import "server-only";
import { desc, eq, inArray, or } from "drizzle-orm";
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
        // The published document is the whole draft, so any sheet held in it earlier goes live now too.
        const imports = await markImportsPublished(v.version);
        await audit(args.actor, "publish", { version: v.version, auto: true, importId: args.importId, imports, largestMovePct: worst?.pct ?? 0 });
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

/**
 * A publish takes every sheet that was applied to the draft live: mark them
 * `published` with the version they went out in, so the list stops saying
 * "publish to go live". Returns the ids that changed. Every publish path
 * (admin, email auto-publish) calls this after `publishVersion`.
 */
export async function markImportsPublished(version: number): Promise<number[]> {
  const rows = await db.update(schema.imports).set({ status: "published", appliedVersion: version }).where(eq(schema.imports.status, "applied")).returning({ id: schema.imports.id });
  return rows.map((r) => r.id);
}

/**
 * The draft was thrown away or replaced wholesale (discard, restore), so the
 * sheets applied to it are no longer in the editor. They go back to waiting,
 * with their stored rows and remembered mapping, so the admin can apply them
 * again rather than be told they are still in the editor - or, after the
 * next publish, that they went live. Returns the ids that changed.
 */
export async function releaseAppliedImports(): Promise<number[]> {
  const rows = await db
    .update(schema.imports)
    .set({ status: "needs_mapping", decidedBy: null, decidedAt: null })
    .where(eq(schema.imports.status, "applied"))
    .returning({ id: schema.imports.id });
  return rows.map((r) => r.id);
}

/** Whether a version was published unattended by the email intake (its `source` is `email:<sender>`). */
export function isAutoPublished(versionSource: string | null | undefined): boolean {
  return typeof versionSource === "string" && versionSource.startsWith("email:");
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
  /** For a published sheet: it went live unattended (email auto-publish) rather than by an admin's publish. */
  publishedAuto: boolean;
}

/** Statuses of a sheet that still waits on an admin: for a column mapping, or for a publish. */
export const OPEN_IMPORT_STATUSES: readonly ImportStatus[] = ["needs_mapping", "applied"];

/**
 * The `limit` most recent sheets — plus every sheet that is still open,
 * however old. This list is the only place a waiting sheet can be mapped or
 * rejected, and "sheets waiting" is counted from it: a sheet that fell off a
 * plain recency window (fifteen newer uploads, about three weeks of daily
 * rate sheets) could be neither reached nor counted, though the intake still
 * held it. Newest first.
 */
export async function listImports(limit = 15): Promise<ImportSummary[]> {
  const recent = db.select({ id: schema.imports.id }).from(schema.imports).orderBy(desc(schema.imports.receivedAt), desc(schema.imports.id)).limit(limit);
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
      versionSource: schema.versions.source,
    })
    .from(schema.imports)
    .leftJoin(schema.versions, eq(schema.versions.version, schema.imports.appliedVersion))
    .where(or(inArray(schema.imports.id, recent), inArray(schema.imports.status, [...OPEN_IMPORT_STATUSES])))
    .orderBy(desc(schema.imports.receivedAt), desc(schema.imports.id));
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
    publishedAuto: r.status === "published" && isAutoPublished(r.versionSource),
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
