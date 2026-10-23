/**
 * What the public site reads: the published document and the price hold,
 * each cached across requests and tagged so a publish or a hold switch can
 * expire them instantly. The document falls back to the sample data
 * (version 0, sample notice on) rather than failing the page if the database
 * is empty or unreachable — a courier site with yesterday's prices beats a
 * courier site with an error. The hold falls back to "off" for the same
 * reason: it is a business switch, not a security control.
 */
import { unstable_cache } from "next/cache";
import { getHold, NO_HOLD, type Hold } from "./hold";
import { getLatestVersion } from "./repo";
import { SEED } from "./seed";
import type { PublishedVersion } from "./types";

export const SITE_TAG = "site";

/**
 * Only real reads are cached. A database error propagates out of the cached
 * function (a rejected call is not stored) and the fallback is built outside
 * it, per request: caching the sample document or "hold off" for five minutes
 * kept a blip on the database showing sample prices — or prices during a hold
 * — long after the database came back. An empty database is a real answer
 * (null) and is cached; the first publish expires it.
 */
const load = unstable_cache(async (): Promise<PublishedVersion | null> => getLatestVersion(), ["live-site"], { tags: [SITE_TAG], revalidate: 300 });

const loadHold = unstable_cache(async (): Promise<Hold> => getHold(), ["live-hold"], { tags: [SITE_TAG], revalidate: 300 });

function sample(): PublishedVersion {
  return { ...SEED, live: false, version: 0, publishedAt: new Date(0).toISOString() };
}

export async function getLiveSite(): Promise<PublishedVersion> {
  try {
    return (await load()) ?? sample();
  } catch (err) {
    console.error("live site: falling back to sample data", err);
    return sample();
  }
}

export async function getLiveHold(): Promise<Hold> {
  try {
    return await loadHold();
  } catch (err) {
    console.error("live site: could not read the price hold, showing prices", err);
    return NO_HOLD;
  }
}
