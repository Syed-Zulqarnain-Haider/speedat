import { describe, expect, it } from "vitest";
import { addonsList, priceAll } from "@/lib/pricing/engine";
import { fmtMoney } from "@/lib/pricing/format";
import { fromPrice } from "@/lib/site/copy";
import { SEED, gridSeed } from "@/lib/site/seed";
import type { SiteData } from "@/lib/site/types";
import { boardRows, routeCode, routeRows } from "./routes";

/** The cheapest 1 kg parcel total across services, exactly as the calculator's quick rate would compute it. */
function cheapest1kg(site: SiteData, destId: string): string | null {
  const r = priceAll(site, { destId, type: "pkg", rows: [{ kg: 1, qty: 1 }] });
  if (!r.ok) return null;
  const totals = site.services.map((s) => r.prices[s.id]?.total).filter((t): t is number => typeof t === "number");
  return totals.length ? fmtMoney(Math.min(...totals), site.settings.currency) : null;
}

describe("routeCode", () => {
  it("upper-cases short ids and truncates long ones to three letters", () => {
    expect(routeCode("gb")).toBe("GB");
    expect(routeCode("france")).toBe("FRA");
    expect(routeCode("ae")).toBe("AE");
  });
});

describe("routeRows", () => {
  for (const [name, site] of [
    ["SEED (slab)", SEED],
    ["gridSeed (grid)", gridSeed()],
  ] as const) {
    it(`prices every row at the cheapest 1 kg total on ${name}`, () => {
      const rows = routeRows(site, false);
      const active = site.destinations.filter((d) => d.active);
      expect(rows).toHaveLength(active.length);
      for (const row of rows) {
        expect(row.price).toBe(cheapest1kg(site, row.id));
        expect(row.price).not.toBeNull();
      }
    });

    it(`carries no price under hold on ${name}`, () => {
      for (const row of routeRows(site, true)) expect(row.price).toBeNull();
    });
  }

  it("sorts by name, derives the code from the id and picks the fastest range", () => {
    const site = structuredClone(SEED);
    site.destinations.push({
      id: "france",
      name: "France",
      active: true,
      rates: { express: { first: 4700, addl: 1150, days: "3–5", doc: 4100 }, normal: { first: 3300, addl: 880, days: "6–9", doc: 2900 } },
    });
    const rows = routeRows(site, false);
    const names = rows.map((r) => r.name);
    expect(names).toEqual([...names].sort((a, b) => a.localeCompare(b)));
    const fr = rows.find((r) => r.id === "france");
    const gb = rows.find((r) => r.id === "gb");
    expect(fr?.code).toBe("FRA");
    expect(gb?.code).toBe("GB");
    expect(gb?.days).toBe("3–5 days");
    expect(rows.find((r) => r.id === "ae")?.days).toBe("2–3 days");
  });

  it("skips inactive destinations and prints a single day count without a dash", () => {
    const site = structuredClone(SEED);
    site.destinations[0].active = false;
    site.destinations[1].rates = {
      express: { ...site.destinations[1].rates.express, days: "4" },
      normal: { ...site.destinations[1].rates.normal, days: "7" },
    };
    const rows = routeRows(site, false);
    expect(rows.find((r) => r.id === site.destinations[0].id)).toBeUndefined();
    expect(rows.find((r) => r.id === site.destinations[1].id)?.days).toBe("4 days");
  });
});

describe("boardRows", () => {
  const onAddons = (site: SiteData) =>
    addonsList(site.settings)
      .filter((a) => a.on)
      .reduce((t, a) => t + a.amount, 0);

  for (const [name, site] of [
    ["SEED (slab)", SEED],
    ["gridSeed (grid)", gridSeed()],
  ] as const) {
    it(`keeps the admin order and prices every service at the calculator's 1 kg total plus the default add-ons on ${name}`, () => {
      const rows = boardRows(site, false);
      const active = site.destinations.filter((d) => d.active);
      expect(rows.map((r) => r.id)).toEqual(active.map((d) => d.id));
      expect(onAddons(site)).toBeGreaterThan(0);
      for (const row of rows) {
        expect(row.cells.map((c) => c.serviceId)).toEqual(site.services.map((s) => s.id));
        const r = priceAll(site, { destId: row.id, type: "pkg", rows: [{ kg: 1, qty: 1 }] });
        expect(r.ok).toBe(true);
        if (!r.ok) continue;
        for (const cell of row.cells) {
          expect(cell.price).toBe((r.prices[cell.serviceId]?.total ?? NaN) + onAddons(site));
          expect(cell.days).toMatch(/^\d+(–\d+)?$/);
        }
        // The cheapest cell is the tile's from-price: one number per fact.
        const cheapest = Math.min(...row.cells.map((c) => c.price ?? Infinity));
        expect(cheapest).toBe(fromPrice(site, row.id)?.total);
        expect(row.doc).not.toBeNull();
      }
    });

    it(`carries no price under hold but keeps the days on ${name}`, () => {
      for (const row of boardRows(site, true)) {
        expect(row.doc).toBeNull();
        for (const cell of row.cells) {
          expect(cell.price).toBeNull();
          expect(cell.days).not.toBeNull();
        }
      }
    });
  }

  it("prices the document column at the cheapest flat rate plus add-ons, and drops it where no document rate exists", () => {
    const site = gridSeed();
    const gb = site.destinations[0];
    const doc = Math.min(gb.rates.express?.doc ?? Infinity, gb.rates.normal?.doc ?? Infinity) + onAddons(site);
    expect(boardRows(site, false)[0].doc).toBe(doc);
    gb.rates.express = { ...gb.rates.express, doc: null };
    gb.rates.normal = { ...gb.rates.normal, doc: null };
    expect(boardRows(site, false)[0].doc).toBeNull();
  });

  it("skips inactive destinations and leaves an unoffered service's cell null", () => {
    const site = structuredClone(SEED);
    site.destinations[0].active = false;
    site.destinations[1].rates = { express: site.destinations[1].rates.express };
    const rows = boardRows(site, false);
    expect(rows.find((r) => r.id === site.destinations[0].id)).toBeUndefined();
    const row = rows.find((r) => r.id === site.destinations[1].id);
    expect(row?.cells.find((c) => c.serviceId === "normal")).toEqual({ serviceId: "normal", price: null, days: null });
    expect(row?.cells.find((c) => c.serviceId === "express")?.price).toBeGreaterThan(0);
  });
});
