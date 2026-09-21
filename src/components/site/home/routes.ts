/**
 * Rows for the route board: every active destination with its display code,
 * fastest working-day range and cheapest 1 kg parcel price. Computed on the
 * server; under hold no price is produced, so nothing rate-shaped reaches
 * the browser. The 1 kg call is exactly the calculator's quick-rate call.
 */
import { parseDaysRange } from "@/lib/pricing/dates";
import { priceAll } from "@/lib/pricing/engine";
import { fmtMoney } from "@/lib/pricing/format";
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
