/**
 * Sentences the site generates from the rate document itself (brief v3 §1):
 * the home headline, the line under it, the rates board's caption and the
 * from-price on a country tile. Every number here comes from `settings`,
 * `company` or a rate — never from a content string — so the page can never
 * say something the rate document does not. A content field, when set,
 * overrides the generated sentence; blank means automatic. Pure; no
 * `"use client"`; shared by server components and the calculator.
 */
import { parseDaysRange } from "@/lib/pricing/dates";
import { addonsList, isNum, priceAll } from "@/lib/pricing/engine";
import { fmtMoney } from "@/lib/pricing/format";
import type { SiteData } from "./types";
import { originCities } from "./text";

/** "Canada" · "Canada and Germany" · "Australia, Canada, France and Germany" · "A, B, C and 4 more countries". */
export function countryList(names: string[]): string {
  const n = names.length;
  if (n === 0) return "";
  if (n === 1) return names[0];
  if (n <= 4) return `${names.slice(0, -1).join(", ")} and ${names[n - 1]}`;
  const rest = n - 3;
  return `${names.slice(0, 3).join(", ")} and ${rest} more ${rest === 1 ? "country" : "countries"}`;
}

/** Active destinations in the admin's order (the order the tiles and the board use). */
function activeDestinations(site: SiteData) {
  return site.destinations.filter((d) => d.active);
}

/** The add-ons ticked by default, summed: what the calculator adds to every price before anyone touches it. */
function addonsOnTotal(site: SiteData): number {
  return addonsList(site.settings)
    .filter((a) => a.on)
    .reduce((t, a) => t + a.amount, 0);
}

/** "3–5" from "3–5", "3 to 5 days" or "3-5"; "4" from "4"; "" when the rate carries no number. */
export function daysText(raw: string | null | undefined): string {
  const r = parseDaysRange(raw);
  if (!r) return "";
  return r[0] === r[1] ? String(r[0]) : `${r[0]}–${r[1]}`;
}

export interface FromPrice {
  /** The cheapest service's 1 kg parcel total plus the on-by-default add-ons — the number the calculator prints for 1 kg. */
  total: number;
  /** That service's transit days as `daysText` prints them ("3–5", "4", or ""). */
  days: string;
  serviceId: string;
}

/**
 * The 1 kg parcel price on a country tile and in the headline: exactly the
 * calculator's quick-rate call for 1 kg, the cheapest priced service, plus
 * the add-ons that are on by default. Null when the destination is not
 * priced (inactive, no service, over the limit).
 */
export function fromPrice(site: SiteData, destId: string): FromPrice | null {
  const r = priceAll(site, { destId, type: "pkg", rows: [{ kg: 1, qty: 1 }] });
  if (!r.ok) return null;
  let best: FromPrice | null = null;
  for (const svc of site.services) {
    const p = r.prices[svc.id];
    if (!p) continue;
    if (!best || p.total < best.total) best = { total: p.total, days: daysText(p.days), serviceId: svc.id };
  }
  if (!best) return null;
  return { ...best, total: best.total + addonsOnTotal(site) };
}

/**
 * The flat document price for one service (an envelope at the document
 * limit, or the first slab when no limit is set), plus the on-by-default
 * add-ons. Null unless the engine actually applied the document rate.
 */
export function docPrice(site: SiteData, destId: string, serviceId: string): number | null {
  const s = site.settings;
  const kg = s.docMaxKg > 0 ? s.docMaxKg : s.firstKg;
  const r = priceAll(site, { destId, type: "doc", rows: [{ kg, qty: 1 }] });
  if (!r.ok) return null;
  const p = r.prices[serviceId];
  if (!p || !p.docRate) return null;
  return p.total + addonsOnTotal(site);
}

/** The shortest lower bound and the longest upper bound of transit days over every active destination and service. */
export function daySpan(site: SiteData): [number, number] | null {
  let lo = Infinity;
  let hi = -Infinity;
  for (const d of activeDestinations(site)) {
    for (const svc of site.services) {
      const r = parseDaysRange(d.rates[svc.id]?.days);
      if (!r) continue;
      lo = Math.min(lo, r[0]);
      hi = Math.max(hi, r[1]);
    }
  }
  return Number.isFinite(lo) && Number.isFinite(hi) ? [lo, hi] : null;
}

/** "Lahore to Australia, Canada, France and Germany." — the first pickup city (or the origin) and the live destinations. */
export function heroTitleAuto(site: SiteData): string {
  const from = originCities(site.company)[0] ?? site.company.origin;
  const list = countryList(activeDestinations(site).map((d) => d.name));
  if (!list) return site.company.name;
  return `${from} to ${list}.`;
}

/**
 * "1 kg from PKR 5,000 · 3 to 10 days" — the lowest 1 kg price on the site
 * and the day span, in the idiom of the country tiles under it; on hold or
 * without a price, "Pickup in Lahore · 3 to 10 days". Without days the
 * first part stands alone. One line on a phone: ten words at most, so the
 * first country tile stays inside the first screen (brief v3 §7 F6). The
 * pickup charge is not repeated here; the board's caption and the price's
 * "Includes" line already carry it.
 */
export function heroSubAuto(site: SiteData, holdOn: boolean): string {
  const cur = site.settings.currency;
  const span = daySpan(site);
  const when = span ? (span[0] === span[1] ? ` · ${span[0]} ${span[0] === 1 ? "day" : "days"}` : ` · ${span[0]} to ${span[1]} days`) : "";
  let min: number | null = null;
  if (!holdOn) {
    for (const d of activeDestinations(site)) {
      const f = fromPrice(site, d.id);
      if (f && (min === null || f.total < min)) min = f.total;
    }
  }
  if (min !== null) return `1 kg from ${fmtMoney(min, cur)}${when}`;
  const cities = originCities(site.company);
  const first = cities.length ? `Pickup in ${cities.join(" and ")}` : `From ${site.company.origin}`;
  return `${first}${when}`;
}

/** True when any active destination carries a document rate on any service (so the board shows a Documents column). */
export function hasDocRate(site: SiteData): boolean {
  if (!(site.settings.docMaxKg > 0)) return false;
  return activeDestinations(site).some((d) => site.services.some((svc) => isNum(d.rates[svc.id]?.doc) && (d.rates[svc.id]?.doc ?? 0) > 0));
}

/** A number and its unit or currency stay on one line: "PKR 500", "0.5 kg" never break across two. */
const NBSP = " ";
function hard(s: string): string {
  return s.replace(/ /g, NBSP);
}

/**
 * "1 kg parcel · documents up to 0.5 kg · pickup PKR 500 included" — the
 * second line of the board's caption. On a phone it wraps once; the hard
 * spaces keep "PKR 500" and "0.5 kg" whole where it does. On hold the board
 * prints only the days per service (no price, no Documents column), so the
 * caption says that instead: the parcel, the document limit and the pickup
 * charge describe prices that are not there, and the hold's promise is that
 * nothing price-shaped reaches the browser.
 */
export function boardNote(site: SiteData, holdOn = false): string {
  if (holdOn) return "Working days per service · prices are being updated";
  const s = site.settings;
  const addonTotal = addonsOnTotal(site);
  let out = `1${NBSP}kg parcel`;
  if (hasDocRate(site)) out += ` · documents up to ${s.docMaxKg}${NBSP}kg`;
  if (addonTotal > 0) out += ` · pickup ${hard(fmtMoney(addonTotal, s.currency))} included`;
  return out;
}
