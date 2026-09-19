/**
 * Data access for the published site document and the admin draft.
 * Server-only: never import from a client component.
 */
import { desc, eq, sql } from "drizzle-orm";
import { db, schema } from "@/lib/db";
import { migrate } from "./migrate";
import type { PublishedVersion, SiteData } from "./types";

const DRAFT_ID = 1;

export interface VersionMeta {
  version: number;
  publishedAt: string;
  publishedBy: string;
  source: string;
  summary: string;
  changeCount: number;
}

function toPublished(row: typeof schema.versions.$inferSelect): PublishedVersion {
  return { ...migrate(row.data), version: row.version, publishedAt: row.publishedAt.toISOString() };
}

/** The document customers see right now, or null on an empty database. */
export async function getLatestVersion(): Promise<PublishedVersion | null> {
  const [row] = await db.select().from(schema.versions).orderBy(desc(schema.versions.version)).limit(1);
  return row ? toPublished(row) : null;
}

export async function getVersion(version: number): Promise<PublishedVersion | null> {
  const [row] = await db.select().from(schema.versions).where(eq(schema.versions.version, version)).limit(1);
  return row ? toPublished(row) : null;
}

export async function listVersions(limit = 20): Promise<VersionMeta[]> {
  const rows = await db
    .select({
      version: schema.versions.version,
      publishedAt: schema.versions.publishedAt,
      publishedBy: schema.versions.publishedBy,
      source: schema.versions.source,
      summary: schema.versions.summary,
      changeCount: schema.versions.changeCount,
    })
    .from(schema.versions)
    .orderBy(desc(schema.versions.version))
    .limit(limit);
  return rows.map((r) => ({ ...r, publishedAt: r.publishedAt.toISOString() }));
}

export interface Draft {
  baseVersion: number;
  data: SiteData;
  updatedAt: string;
  updatedBy: string;
}

/** The working copy; created from the live version on first use. */
export async function getDraft(): Promise<Draft> {
  const [row] = await db.select().from(schema.draft).where(eq(schema.draft.id, DRAFT_ID)).limit(1);
  if (row) return { baseVersion: row.baseVersion, data: migrate(row.data), updatedAt: row.updatedAt.toISOString(), updatedBy: row.updatedBy };
  const live = await getLatestVersion();
  if (!live) throw new Error("No published version exists; run the seed first");
  const { version, publishedAt: _publishedAt, ...data } = live;
  void _publishedAt;
  const [created] = await db
    .insert(schema.draft)
    .values({ id: DRAFT_ID, baseVersion: version, data, updatedBy: "system" })
    .onConflictDoNothing()
    .returning();
  if (created) return { baseVersion: version, data, updatedAt: created.updatedAt.toISOString(), updatedBy: "system" };
  return getDraft();
}

export async function saveDraft(data: SiteData, by: string): Promise<void> {
  const current = await getDraft();
  await db
    .update(schema.draft)
    .set({ data, updatedAt: new Date(), updatedBy: by, baseVersion: current.baseVersion })
    .where(eq(schema.draft.id, DRAFT_ID));
}

/** Throw away the draft; the next `getDraft` re-branches from the live version. */
export async function discardDraft(): Promise<void> {
  await db.delete(schema.draft).where(eq(schema.draft.id, DRAFT_ID));
}

export interface PublishArgs {
  data: SiteData;
  by: string;
  source: string;
  summary: string;
  changeCount: number;
  /** The version the caller believes is live; publishing on top of a newer one is refused. */
  expectedBase?: number;
}

export class PublishConflict extends Error {
  constructor(public readonly live: number) {
    super(`A newer version (${live}) was published meanwhile`);
  }
}

/** Insert the next version atomically and re-base the draft onto it. */
export async function publishVersion(args: PublishArgs): Promise<PublishedVersion> {
  return db.transaction(async (tx) => {
    // Serialise publishers: two admins clicking Publish at once get versions n+1 and n+2, never a duplicate.
    await tx.execute(sql`SELECT pg_advisory_xact_lock(7331)`);
    const [latest] = await tx.select({ v: schema.versions.version }).from(schema.versions).orderBy(desc(schema.versions.version)).limit(1);
    const liveVersion = latest?.v ?? 0;
    if (args.expectedBase != null && args.expectedBase !== liveVersion) throw new PublishConflict(liveVersion);
    const version = liveVersion + 1;
    const [row] = await tx
      .insert(schema.versions)
      .values({ version, publishedBy: args.by, source: args.source, summary: args.summary, changeCount: args.changeCount, data: args.data })
      .returning();
    await tx
      .insert(schema.draft)
      .values({ id: DRAFT_ID, baseVersion: version, data: args.data, updatedBy: args.by })
      .onConflictDoUpdate({ target: schema.draft.id, set: { baseVersion: version, data: args.data, updatedAt: new Date(), updatedBy: args.by } });
    if (!row) throw new Error("publish failed");
    return toPublished(row);
  });
}
