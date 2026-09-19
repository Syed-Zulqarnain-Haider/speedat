/**
 * The published document as the site reads it: cached across requests and
 * tagged so a publish can expire it instantly. Falls back to the sample data
 * (version 0, sample notice on) rather than failing the page if the database
 * is empty or unreachable — a courier site with yesterday's prices beats a
 * courier site with an error.
 */
import { unstable_cache } from "next/cache";
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

export function getLiveSite(): Promise<PublishedVersion> {
  return load();
}
