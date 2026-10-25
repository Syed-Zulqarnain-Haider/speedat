/**
 * The intake pipeline: a rate sheet arrives (upload or email) and either
 * lands in the draft automatically — when its layout is already known — or
 * waits for an admin to map its columns once. Publishing is a separate step
 * unless the operator has set an auto-publish tolerance and the sheet moves
 * no price by more than that; only the unattended email path ever publishes.
 */
import "server-only";
import { desc, eq, inArray, ne, or, type SQL } from "drizzle-orm";
import { revalidateTag } from "next/cache";
import { alertNow } from "@/lib/alerts";
import { audit } from "@/lib/audit";
import { db, schema } from "@/lib/db";
import { diffSite, summarise, validateSite } from "@/lib/site/diff";
import { getHold } from "@/lib/site/hold";
import { SITE_TAG } from "@/lib/site/live";
import { PublishConflict, getDraft, getLatestVersion, publishVersion, saveDraft } from "@/lib/site/repo";
import type { SiteData } from "@/lib/site/types";
import { businessDayKey } from "@/lib/time";
import { applyImport, buildImport, describeMove, largestMove, suggestLayout, type Move } from "./parse";
import type { ImportOptions, ImportResult } from "./types";
import { WorkbookError, largestSheet, readWorkbook, type Workbook } from "./workbook";

export type ImportStatus = "needs_mapping" | "applied" | "published" | "rejected" | "failed";

export interface IntakeSettings {
  /** 0 = never publish automatically; otherwise the largest allowed % move. */
  autoPublishPct: number;
  /** Lower-case emails or "@domain" suffixes allowed to send sheets; empty = anyone with the webhook secret. */
  allowedSenders: string[];
  /** Hour (0–23, the office's wall clock — `businessNow`) by which a sheet is expected each working day; null = no expectation. */
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
  const draft = await getDraft();
  // Tries the remembered header rows too, so a layout an admin corrected once matches the next copy.
  const { headerRow, map, profile } = suggestLayout(rows, draft.data.importProfiles, draft.data.services);
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
  const applied = await applyToDraft({ importId, sheet, headerRow, map, opts, actor: args.actor, fileName: args.fileName, unattended: true });
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
  /**
   * Set only by `receiveSheet` for an emailed sheet with a known layout. It
   * alone may auto-publish: the admin's "Apply to editor" is open to editors,
   * who must never get an owner-only publish through a sheet.
   */
  unattended?: boolean;
}

export interface ApplyResult {
  status: ImportStatus;
  result: ImportResult | null;
  /** The draft after the import (for the admin to adopt). */
  draft: SiteData | null;
  publishedVersion: number | null;
  error: string | null;
}

/** Recompute the import from the stored rows, apply it to the draft, save, and — on the unattended email path only — auto-publish when allowed. */
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
    // An emailed sheet whose known layout no longer reads (e.g. a grid sheet against slab rates) must not stop
    // landing silently until the daily digest.
    if (args.unattended) {
      void alertNow(
        `Rate sheet ${rec.fileName} could not be read`,
        `The sheet from ${rec.fromEmail ?? "an unknown sender"} has a layout seen before, but no prices could be taken from it: ${result.errors[0] ?? "no destinations with prices were found"}. Nothing was changed. Open ${process.env.NEXT_PUBLIC_SITE_URL ?? ""}/admin and check it under Import from Excel.`,
      );
    }
    return { status: "needs_mapping", result, draft: null, publishedVersion: null, error: result.errors[0] ?? "No destinations with prices were found" };
  }
  const next = applyImport(draft.data, result);
  await saveDraft(next, args.actor);
  await db
    .update(schema.imports)
    .set({ result, status: "applied", profileSignature: result.profile?.signature ?? null, sheetName: args.sheet, decidedBy: args.actor, decidedAt: new Date(), error: null })
    .where(eq(schema.imports.id, args.importId));
  await audit(args.actor, "import_applied", { importId: args.importId, rows: result.rows.length, errors: result.errors.length, warnings: result.warnings.length });

  if (args.unattended && rec.source === "email") {
    const auto = await autoPublish({ importId: args.importId, rec, rows, args, result, draftNext: next });
    if (auto) return { status: "published", result, draft: next, publishedVersion: auto, error: null };
  }
  return { status: "applied", result, draft: next, publishedVersion: null, error: null };
}

/** The live document without its version stamp, as the base an import is measured and published against. */
function liveData(live: NonNullable<Awaited<ReturnType<typeof getLatestVersion>>>): SiteData {
  const { version: _v, publishedAt: _p, ...data } = live;
  void _v;
  void _p;
  return data;
}

/** Why an unattended sheet was not published, in the words the alert uses. */
export function holdReason(a: { autoPublishPct: number; rowErrors: number; validation: string[]; worst: Move | null }): string | null {
  if (a.autoPublishPct <= 0) return "auto-publish is off";
  if (a.rowErrors) return `${a.rowErrors} row${a.rowErrors === 1 ? "" : "s"} could not be read`;
  if (a.validation[0]) return a.validation[0];
  if (a.worst && a.worst.pct > a.autoPublishPct) return describeMove(a.worst);
  return null;
}

const HOLD_NOTE = "Prices remain on hold: customers still see the hold message, not these rates. Press Resume in the admin when you are ready to show prices again.";

/**
 * The unattended publish. It is measured against, and publishes, the LIVE
 * version plus this sheet — never the draft, which may hold an admin's
 * unreviewed edits. The draft keeps its edits and the sheet (the caller saved
 * it); `publishVersion` re-bases the draft onto what it published, so the
 * draft is written back afterwards. `expectedBase` makes a publish that raced
 * an admin's fail instead of overwriting it. A price hold is never lifted
 * here: lifting is a person's call, so the alert says the hold is still on.
 * Returns the published version, or null when the sheet waits for review.
 */
async function autoPublish(a: {
  importId: number;
  rec: typeof schema.imports.$inferSelect;
  rows: string[][];
  args: ApplyArgs;
  result: ImportResult;
  draftNext: SiteData;
}): Promise<number | null> {
  const { rec, args } = a;
  const site = process.env.NEXT_PUBLIC_SITE_URL ?? "";
  const settings = await getIntakeSettings();
  const hold = await getHold();
  const holdLine = hold.on ? `\n\n${HOLD_NOTE}` : "";
  const latest = await getLatestVersion();
  const held = async (reason: string) => {
    await audit(args.actor, "import_held", { importId: a.importId, reason, holdOn: hold.on });
    void alertNow(
      `Rate sheet ${rec.fileName} is waiting for your review`,
      `The sheet from ${rec.fromEmail ?? "an unknown sender"} was read and applied to the editor (${a.result.rows.length} destinations) but not published: ${reason}.${holdLine}

Review and publish at ${site}/admin.`,
    );
    return null;
  };
  if (!latest) return held("nothing has been published yet");
  const live = liveData(latest);
  const im = buildImport({ rows: a.rows, headerRow: args.headerRow, map: args.map, opts: args.opts, draft: live, fileName: args.fileName ?? rec.fileName });
  const next = applyImport(live, im);
  const diff = diffSite(live, next);
  // Unreadable rows hold the sheet even when the readable ones match live: the unread ones may not.
  if (im.errors.length) return held(holdReason({ autoPublishPct: settings.autoPublishPct, rowErrors: im.errors.length, validation: [], worst: null }) ?? "rows could not be read");
  if (!diff.lines.some((l) => l.kind !== "profile")) {
    // The live site already quotes these prices: say so, rather than leave the sheet "in the editor" silently.
    await db.update(schema.imports).set({ status: "published", appliedVersion: latest.version }).where(eq(schema.imports.id, a.importId));
    await audit(args.actor, "import_unchanged", { importId: a.importId, version: latest.version });
    void alertNow(
      `Rate sheet ${rec.fileName} changed nothing`,
      `The sheet from ${rec.fromEmail ?? "an unknown sender"} has the same prices as the live site (version ${latest.version}), so nothing was published.${holdLine}`,
    );
    return null;
  }
  const worst = largestMove(live, im);
  const reason = holdReason({ autoPublishPct: settings.autoPublishPct, rowErrors: im.errors.length, validation: validateSite(next), worst });
  if (reason) return held(reason);
  let v: Awaited<ReturnType<typeof publishVersion>>;
  try {
    v = await publishVersion({
      data: next,
      by: args.actor,
      source: `email:${rec.fromEmail ?? "unknown"}`,
      summary: `${summarise(diff)} (auto from ${rec.fileName})`,
      changeCount: diff.count,
      expectedBase: latest.version,
      // Read the hold inside the publish transaction but never lift it: that is a person's call.
      liftHold: false,
    });
  } catch (err) {
    if (err instanceof PublishConflict) return held(`version ${err.live} was published while the sheet was being read`);
    throw err;
  }
  // The version is committed. Expire the cache first so customers get it whatever fails next; outside a server
  // action updateTag is not allowed, and { expire: 0 } is its route-handler equivalent (no stale serve).
  revalidateTag(SITE_TAG, { expire: 0 });
  // The rest is best effort: a throw here would 500 the webhook and make the sender retry a sheet that is live.
  const after = async (what: string, fn: () => Promise<unknown>) => {
    try {
      await fn();
    } catch (err) {
      console.error(`[intake] after publishing version ${v.version}: ${what} failed`, err);
    }
  };
  // Only this sheet went live; others applied to the draft are still only in the draft.
  await after("marking the sheet published", () => db.update(schema.imports).set({ status: "published", appliedVersion: v.version }).where(eq(schema.imports.id, a.importId)));
  // publishVersion re-based the draft onto `next`; put back the admin's draft (with this sheet in it).
  await after("restoring the editor's draft", () => saveDraft(a.draftNext, args.actor));
  // The hold as the publish transaction saw it wins over the earlier read.
  const holdOn = v.hold?.on ?? hold.on;
  const holdAfter = holdOn ? `\n\n${HOLD_NOTE}` : "";
  await after("writing the audit entry", () => audit(args.actor, "publish", { version: v.version, auto: true, importId: a.importId, imports: [a.importId], largestMovePct: worst?.pct ?? 0, holdOn }));
  void alertNow(
    `Rate sheet ${rec.fileName} was published automatically`,
    `The sheet from ${rec.fromEmail ?? "an unknown sender"} went live as version ${v.version}: ${summarise(diff)}. ${worst ? `Largest change: ${describeMove(worst)}.` : ""}${holdAfter}

Check it at ${site}/admin.`,
  );
  return v.version;
}

/**
 * An admin's publish takes the sheets applied to the draft live: mark them
 * `published` with the version they went out in, so the list stops saying
 * "publish to go live". Every applied sheet is marked, including one a newer
 * sheet or a hand edit has since overridden: it was reviewed and superseded,
 * and sending it back to waiting would invite re-applying old prices over the
 * new ones. Returns the ids marked published. The email auto-publish marks its
 * own sheet and does not call this.
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
  return summaries(or(inArray(schema.imports.id, recent), inArray(schema.imports.status, [...OPEN_IMPORT_STATUSES])));
}

async function summaries(where: SQL | undefined, limit?: number): Promise<ImportSummary[]> {
  const q = summarySelect().where(where).orderBy(desc(schema.imports.receivedAt), desc(schema.imports.id));
  const rows = await (limit ? q.limit(limit) : q);
  return rows.map(toSummary);
}

function summarySelect() {
  return db
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
    .leftJoin(schema.versions, eq(schema.versions.version, schema.imports.appliedVersion));
}

function toSummary(r: Awaited<ReturnType<typeof summarySelect>>[number]): ImportSummary {
  return {
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
  };
}

/**
 * Was `receivedAt` on the office's calendar day of `now`? Judged by
 * `businessDayKey`, not the server clock: Vercel runs in UTC, so a sheet sent
 * at 03:00 in Lahore used to count as yesterday's.
 */
export function receivedToday(receivedAt: Date | string, now: Date = new Date()): boolean {
  return businessDayKey(new Date(receivedAt)) === businessDayKey(now);
}

/**
 * The latest sheet that arrived today and could be read, or null. Used by the
 * daily "no sheet by the expected hour" check: an unreadable attachment is
 * not a rate sheet, so it must not silence that alert.
 */
export async function latestSheetToday(now: Date = new Date()): Promise<ImportSummary | null> {
  const [last] = await summaries(ne(schema.imports.status, "failed"), 1);
  return last && receivedToday(last.receivedAt, now) ? last : null;
}
