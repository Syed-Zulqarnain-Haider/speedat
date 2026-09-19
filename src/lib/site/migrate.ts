/**
 * Bring a stored document up to the current shape. Old snapshots (and the
 * prototype's exported data) may lack fields added later; every missing
 * field gets the same default the prototype used, so restoring version 1
 * years from now still renders.
 */
import { SEED } from "./seed";
import type { SiteData } from "./types";

type Loose = Partial<SiteData> & { settings?: Partial<SiteData["settings"]>; company?: Partial<SiteData["company"]> };

export function migrate(input: SiteData | Loose): SiteData {
  const s = structuredClone(input) as Loose;
  const settings = { ...SEED.settings, ...(s.settings ?? {}) };
  // The prototype stored a blank cutoff as ""; we store null.
  const rawCutoff = (s.settings as { cutoffHour?: unknown } | undefined)?.cutoffHour;
  settings.cutoffHour = rawCutoff === "" || rawCutoff == null ? null : Number(rawCutoff);
  if (!Number.isFinite(settings.cutoffHour as number)) settings.cutoffHour = null;
  if (settings.docMaxKg == null) settings.docMaxKg = settings.firstKg;
  const company = { ...SEED.company, ...(s.company ?? {}) };
  const content = { ...SEED.content, ...(s.content ?? {}) };
  return {
    live: !!s.live,
    company,
    settings,
    services: s.services?.length ? s.services : structuredClone(SEED.services),
    destinations: s.destinations ?? [],
    content,
    importProfiles: s.importProfiles ?? [],
  };
}
