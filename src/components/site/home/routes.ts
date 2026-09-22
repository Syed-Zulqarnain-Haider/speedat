/**
 * Rows for the rates board (`boardRows`, brief v3 §3) and the older route
 * rows (`routeRows`, kept and tested): every active destination priced for a
 * 1 kg parcel per service, plus the flat document rate. Computed on the
 * server; under hold no price is produced, so nothing rate-shaped reaches
 * the browser. Every price is the calculator's own 1 kg quick-rate call
 * with the on-by-default add-ons added, so a number printed here is the
 * number the calculator prints for 1 kg — one number per fact.
 */
import { parseDaysRange } from "@/lib/pricing/dates";
import { addonsList, priceAll } from "@/lib/pricing/engine";
import { fmtMoney } from "@/lib/pricing/format";
import { daysText, docPrice } from "@/lib/site/copy";
import type { PublishedVersion, SiteData } from "@/lib/site/types";

export interface RouteRowData {
  id: string;
  /** Label only (GB, FRA): derived from the id, never assumed to be an ISO code. */
  code: string;
  name: string;
  /** "3–5 days" / "3 days", or null when no service carries a range. */
  days: string | null;
  /** "PKR 4,500" (the cheapest service at 1 kg), or null under hold / unpriced. */
  price: string | null;
}

export function routeCode(id: string): string {
  return (id.length <= 3 ? id : id.slice(0, 3)).toUpperCase();
}

export function routeRows(site: SiteData | PublishedVersion, holdOn: boolean): RouteRowData[] {
  const active = site.destinations.filter((x) => x.active).sort((a, b) => a.name.localeCompare(b.name));
  return active.map((dest) => {
    let best: [number, number] | null = null;
    for (const svc of site.services) {
      const r = parseDaysRange(dest.rates[svc.id]?.days);
      if (r && (!best || r[0] < best[0])) best = r;
    }
    const days = best ? (best[0] === best[1] ? `${best[0]} days` : `${best[0]}–${best[1]} days`) : null;
    let price: string | null = null;
    if (!holdOn) {
      const r = priceAll(site, { destId: dest.id, type: "pkg", rows: [{ kg: 1, qty: 1 }] });
      if (r.ok) {
        const totals = site.services.map((svc) => r.prices[svc.id]?.total).filter((t): t is number => typeof t === "number");
        if (totals.length) price = fmtMoney(Math.min(...totals), site.settings.currency);
      }
    }
    return { id: dest.id, code: routeCode(dest.id), name: dest.name, days, price };
  });
}

export interface BoardCell {
  serviceId: string;
  /** The 1 kg parcel total with the default add-ons, or null under hold / when the service is not offered. */
  price: number | null;
  /** "3–5" / "4", or null when the rate carries no number. */
  days: string | null;
}

export interface BoardRow {
  id: string;
  name: string;
  /** One cell per `site.services` entry, in that order. */
  cells: BoardCell[];
  /** The cheapest flat document rate with the default add-ons, or null under hold / without a document rate. */
  doc: number | null;
}

/** Every active destination in the admin's order, priced per service for 1 kg and for a document. */
export function boardRows(site: SiteData | PublishedVersion, holdOn: boolean): BoardRow[] {
  const addonsOn = addonsList(site.settings)
    .filter((a) => a.on)
    .reduce((t, a) => t + a.amount, 0);
  return site.destinations
    .filter((x) => x.active)
    .map((dest) => {
      const r = holdOn ? null : priceAll(site, { destId: dest.id, type: "pkg", rows: [{ kg: 1, qty: 1 }] });
      const cells: BoardCell[] = site.services.map((svc) => {
        const p = r?.ok ? r.prices[svc.id] : null;
        const days = daysText(dest.rates[svc.id]?.days);
        return { serviceId: svc.id, price: p ? p.total + addonsOn : null, days: days || null };
      });
      let doc: number | null = null;
      if (!holdOn) {
        for (const svc of site.services) {
          const d = docPrice(site, dest.id, svc.id);
          if (d != null && (doc === null || d < doc)) doc = d;
        }
      }
      return { id: dest.id, name: dest.name, cells, doc };
    });
}
