"use server";

/**
 * Admin server actions. Every one re-checks the session; the client's copy
 * of the document is validated before it touches the database; publishing
 * is refused when the live version moved underneath the draft.
 */
import { updateTag } from "next/cache";
import { audit } from "@/lib/audit";
import { Forbidden, requireAdmin } from "@/lib/auth/session";
import { markImportsPublished, releaseAppliedImports } from "@/lib/import/intake";
import { diffSite, summarise, validateSite } from "@/lib/site/diff";
import { getHold, HOLD_MESSAGE_MAX, setHold, type Hold } from "@/lib/site/hold";
import { SITE_TAG } from "@/lib/site/live";
import { migrate } from "@/lib/site/migrate";
import { PublishConflict, discardDraft, getDraft, getLatestVersion, getVersion, publishVersion, saveDraft } from "@/lib/site/repo";
import { SiteDataSchema } from "@/lib/site/schema";
import type { SiteData } from "@/lib/site/types";

export type ActionResult<T = object> = ({ ok: true } & T) | { ok: false; code: string; message: string; errors?: string[] };

function parse(data: unknown): SiteData {
  return migrate(SiteDataSchema.parse(data));
}

function fail(code: string, message: string, errors?: string[]): ActionResult<never> {
  return { ok: false, code, message, errors };
}

/** A schema rejection, named by field so the admin can find it (this page is admin-only; paths are not secrets). */
function invalid(err: unknown): ActionResult<never> | null {
  if (!(err instanceof Error && err.name === "ZodError")) return null;
  const issues = (err as unknown as { issues?: { path: (string | number)[]; message: string }[] }).issues ?? [];
  const where = issues.slice(0, 5).map((i) => `${i.path.join(".") || "document"}: ${i.message}`);
  return fail("invalid", `The editor sent something the server could not read${where[0] ? ` (${where[0]})` : ""}.`, where);
}

function onError(err: unknown): ActionResult<never> {
  if (err instanceof Forbidden) return fail("forbidden", err.message);
  if (err instanceof PublishConflict) return fail("conflict", `Version ${err.live} was published meanwhile. Reload to see it, then apply your changes again.`);
  console.error(err);
  return fail("error", "Something went wrong; your changes are still in this browser. Try again.");
}

export async function saveDraftAction(data: unknown): Promise<ActionResult<{ updatedAt: string }>> {
  try {
    const user = await requireAdmin();
    const doc = parse(data);
    await saveDraft(doc, user.email);
    return { ok: true, updatedAt: new Date().toISOString() };
  } catch (err) {
    const bad = invalid(err);
    if (bad) return bad;
    return onError(err);
  }
}

export async function discardDraftAction(): Promise<ActionResult<{ data: SiteData; baseVersion: number; updatedAt: string }>> {
  try {
    const user = await requireAdmin();
    await discardDraft();
    const d = await getDraft();
    // Sheets applied to the discarded draft are no longer in the editor; they wait to be applied again.
    const released = await releaseAppliedImports();
    await audit(user.email, "draft_discarded", { releasedImports: released });
    return { ok: true, data: d.data, baseVersion: d.baseVersion, updatedAt: d.updatedAt };
  } catch (err) {
    return onError(err);
  }
}

export interface PublishInput {
  data: unknown;
  expectedBase: number;
  goLive: boolean;
  /**
   * Leave the price hold on with this publish when exactly `false`. Anything
   * else lifts it if it is on — the server reads the real hold, so a hold
   * another admin switched on after this page loaded is lifted too, and the
   * result says so.
   */
  resume?: boolean;
}

export type PublishResult = ActionResult<{
  version: number;
  publishedAt: string;
  /** This publish lifted the hold. */
  resumed: boolean;
  /** The hold after the publish, as stored. */
  hold: Hold | null;
  /** A follow-up step failed after the version was committed; the publish itself stands. */
  warning?: string;
}>;

export async function publishAction(input: PublishInput): Promise<PublishResult> {
  let user: Awaited<ReturnType<typeof requireAdmin>>;
  let doc: SiteData;
  let v: Awaited<ReturnType<typeof publishVersion>>;
  let summary: string;
  let diff: ReturnType<typeof diffSite> | null;
  try {
    user = await requireAdmin("owner");
    doc = parse(input.data);
    if (input.goLive) doc.live = true;
    const errors = validateSite(doc);
    if (errors.length) return fail("invalid", "Fix these before publishing", errors);
    const live = await getLatestVersion();
    diff = live ? diffSite(live, doc) : null;
    if (diff && diff.count === 0) return fail("nochange", "There is nothing to publish.");
    summary = diff ? summarise(diff) : "First publish";
    // The hold is read and lifted inside the publish transaction, so it comes off with the new prices or not at all.
    v = await publishVersion({ data: doc, by: user.email, source: "admin", summary, changeCount: diff?.count ?? 0, expectedBase: input.expectedBase, liftHold: input.resume !== false });
  } catch (err) {
    const bad = invalid(err);
    if (bad) return bad;
    return onError(err);
  }
  // The version is committed: customers get it whatever happens below. Telling the owner it failed would make
  // him publish it again (a conflict) or worse, assume the old prices are still up. Every follow-up step is
  // therefore best-effort, the cache is expired no matter what, and a failure comes back as a warning.
  const resumed = v.hold?.lifted === true;
  const missed: string[] = [];
  const attempt = async (what: string, step: () => Promise<unknown>) => {
    try {
      return await step();
    } catch (err) {
      console.error(`publish v${v.version}: ${what} failed`, err);
      missed.push(what);
      return undefined;
    }
  };
  try {
    // Every sheet applied to the draft went out in this version; the Recent sheets list says so.
    const imports = await attempt("marking imported sheets as published", () => markImportsPublished(v.version, doc));
    await attempt("writing the audit log", async () => {
      await audit(user.email, "publish", { version: v.version, summary, resumedPrices: resumed, imports, changes: diff?.lines.slice(0, 200) ?? [] });
      if (resumed) await audit(user.email, "hold_off", { version: v.version, viaPublish: true });
    });
  } finally {
    try {
      updateTag(SITE_TAG); // immediate expiry: the next request anywhere reads the new state, no stale-while-revalidate
    } catch (err) {
      console.error(`publish v${v.version}: cache expiry failed`, err);
      missed.push("refreshing the website cache (it catches up within 5 minutes)");
    }
  }
  const warning = missed.length ? `Version ${v.version} is published, but ${missed.join(" and ")} did not complete.` : undefined;
  const hold: Hold | null = v.hold ? { on: v.hold.on, message: v.hold.message, by: v.hold.by, since: v.hold.since } : null;
  return { ok: true, version: v.version, publishedAt: v.publishedAt, resumed, hold, warning };
}

/** Copy an older version's rates, settings and text into the draft (not published until reviewed). */
export async function restoreVersionAction(version: number): Promise<ActionResult<{ data: SiteData; updatedAt: string }>> {
  try {
    const user = await requireAdmin();
    const old = await getVersion(version);
    if (!old) return fail("not_found", "That version does not exist");
    const current = await getDraft();
    const data: SiteData = {
      ...current.data,
      company: old.company,
      settings: old.settings,
      services: old.services,
      destinations: old.destinations,
      content: old.content,
    };
    await saveDraft(data, user.email);
    // The restored rates replace whatever a sheet put in the draft; it waits to be applied again.
    const released = await releaseAppliedImports();
    await audit(user.email, "draft_restored", { fromVersion: version, releasedImports: released });
    return { ok: true, data, updatedAt: new Date().toISOString() };
  } catch (err) {
    return onError(err);
  }
}

export interface HoldInput {
  on: boolean;
  message?: string;
}

/**
 * Take every price off the website now (any admin — the safe direction), or
 * put them back (owner — the same trust as publishing). Nothing in the draft
 * or the live version changes; the site re-reads the switch immediately.
 */
export async function setHoldAction(input: HoldInput): Promise<ActionResult<{ hold: Hold }>> {
  try {
    const user = await requireAdmin(input.on ? undefined : "owner");
    if (input.message !== undefined && (typeof input.message !== "string" || input.message.length > HOLD_MESSAGE_MAX * 4)) return fail("invalid", "The message is too long.");
    const before = await getHold();
    const hold = await setHold(input.on, user.email, input.message);
    updateTag(SITE_TAG); // immediate expiry: the next request anywhere reads the new state, no stale-while-revalidate
    await audit(user.email, input.on ? "hold_on" : "hold_off", { message: hold.message, was: before.on });
    return { ok: true, hold };
  } catch (err) {
    return onError(err);
  }
}
