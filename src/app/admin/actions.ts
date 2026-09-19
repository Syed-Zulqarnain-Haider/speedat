"use server";

/**
 * Admin server actions. Every one re-checks the session; the client's copy
 * of the document is validated before it touches the database; publishing
 * is refused when the live version moved underneath the draft.
 */
import { revalidateTag } from "next/cache";
import { audit } from "@/lib/audit";
import { Forbidden, requireAdmin } from "@/lib/auth/session";
import { diffSite, summarise, validateSite } from "@/lib/site/diff";
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
    if (err instanceof Error && err.name === "ZodError") return fail("invalid", "The editor sent something the server could not read.");
    return onError(err);
  }
}

export async function discardDraftAction(): Promise<ActionResult<{ data: SiteData; baseVersion: number }>> {
  try {
    const user = await requireAdmin();
    await discardDraft();
    const d = await getDraft();
    await audit(user.email, "draft_discarded", {});
    return { ok: true, data: d.data, baseVersion: d.baseVersion };
  } catch (err) {
    return onError(err);
  }
}

export interface PublishInput {
  data: unknown;
  expectedBase: number;
  goLive: boolean;
}

export async function publishAction(input: PublishInput): Promise<ActionResult<{ version: number; publishedAt: string }>> {
  try {
    const user = await requireAdmin("owner");
    const doc = parse(input.data);
    if (input.goLive) doc.live = true;
    const errors = validateSite(doc);
    if (errors.length) return fail("invalid", "Fix these before publishing", errors);
    const live = await getLatestVersion();
    const diff = live ? diffSite(live, doc) : null;
    if (diff && diff.count === 0) return fail("nochange", "There is nothing to publish.");
    const summary = diff ? summarise(diff) : "First publish";
    const v = await publishVersion({ data: doc, by: user.email, source: "admin", summary, changeCount: diff?.count ?? 0, expectedBase: input.expectedBase });
    revalidateTag(SITE_TAG, "max");
    await audit(user.email, "publish", { version: v.version, summary, changes: diff?.lines.slice(0, 200) ?? [] });
    return { ok: true, version: v.version, publishedAt: v.publishedAt };
  } catch (err) {
    if (err instanceof Error && err.name === "ZodError") return fail("invalid", "The editor sent something the server could not read.");
    return onError(err);
  }
}

/** Copy an older version's rates, settings and text into the draft (not published until reviewed). */
export async function restoreVersionAction(version: number): Promise<ActionResult<{ data: SiteData }>> {
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
    await audit(user.email, "draft_restored", { fromVersion: version });
    return { ok: true, data };
  } catch (err) {
    return onError(err);
  }
}
