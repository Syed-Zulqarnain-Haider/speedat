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

const load = unstable_cache(
  async (): Promise<PublishedVersion> => {
    try {
      const live = await getLatestVersion();
      if (live) return live;
    } catch (err) {
      console.error("live site: falling back to sample data", err);
    }
    return { ...SEED, live: false, version: 0, publishedAt: new Date(0).toISOString() };
  },
  ["live-site"],
  { tags: [SITE_TAG], revalidate: 300 },
);

const loadHold = unstable_cache(
  async (): Promise<Hold> => {
    try {
      return await getHold();
    } catch (err) {
      console.error("live site: could not read the price hold, showing prices", err);
      return NO_HOLD;
    }
  },
  ["live-hold"],
  { tags: [SITE_TAG], revalidate: 300 },
);

export function getLiveSite(): Promise<PublishedVersion> {
  return load();
}

export function getLiveHold(): Promise<Hold> {
  return loadHold();
}
