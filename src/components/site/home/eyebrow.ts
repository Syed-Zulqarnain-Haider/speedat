/**
 * The mono route line above the home headline: the owner's text when set,
 * otherwise `{origin} → {first five active destinations} · and N more`.
 * Pure; the page calls it on the server.
 */
import { originCities } from "@/lib/site/text";
import type { PublishedVersion, SiteData } from "@/lib/site/types";

export function heroEyebrow(site: SiteData | PublishedVersion): string {
  const own = site.content.heroEyebrow.trim();
  if (own) return own;
  const origin = originCities(site.company)[0] ?? site.company.origin;
  const active = site.destinations.filter((x) => x.active);
  const names = active.slice(0, 5).map((x) => x.name);
  const more = active.length - names.length;
  return `${origin} → ${names.join(" · ")}${more > 0 ? ` · and ${more} more` : ""}`;
}
