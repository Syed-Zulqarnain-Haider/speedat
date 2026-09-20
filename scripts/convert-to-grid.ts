/**
 * One-off maintenance: switch a site that still prices by weight slabs
 * ("first 0.5 kg + each additional 0.5 kg") to per-kilogram price boxes.
 * Every box is filled from the slab prices, so customers see the same
 * numbers before and after; the admin then edits boxes directly.
 *
 *   pnpm exec dotenv -e .env.local -- tsx scripts/convert-to-grid.ts
 *
 * Publishes a new version (source "convert") and re-bases the draft on it.
 * Does nothing when the live version is already per-kilogram. The site's
 * cached copy refreshes within five minutes or on the next admin publish.
 */
import { slabToGrid } from "@/lib/pricing/engine";
import { getLatestVersion, publishVersion } from "@/lib/site/repo";
import type { SiteData } from "@/lib/site/types";

async function main() {
  const live = await getLatestVersion();
  if (!live) throw new Error("No published version; run pnpm db:seed first");
  if (live.settings.pricingMode === "grid") {
    console.log(`Version ${live.version} already prices per kilogram; nothing to do.`);
    return;
  }
  const { version, publishedAt: _at, ...rest } = live;
  void _at;
  const data: SiteData = structuredClone(rest);
  data.settings.pricingMode = "grid";
  if (!(data.settings.maxKg > 0)) data.settings.maxKg = 25;
  let boxes = 0;
  for (const dest of data.destinations) {
    for (const rate of Object.values(dest.rates)) {
      if (!rate) continue;
      rate.grid = slabToGrid(data.settings, rate);
      boxes += Object.keys(rate.grid ?? {}).length;
    }
  }
  const v = await publishVersion({
    data,
    by: "convert-to-grid",
    source: "convert",
    summary: `Switched to per-kilogram prices (1–${data.settings.maxKg} kg) from version ${version}`,
    changeCount: boxes,
    expectedBase: version,
  });
  console.log(`Published version ${v.version}: ${data.destinations.length} destinations, ${boxes} price boxes.`);
}

main()
  .then(() => process.exit(0))
  .catch((err) => {
    console.error(err);
    process.exit(1);
  });
