import { describe, expect, it } from "vitest";
import { computeWeights, gridPrice, gridWeights, priceAll, slabToGrid } from "./engine";
import { autoMap, buildImport, weightColumns, weightHeader } from "@/lib/import/parse";
import { diffSite, validateSite, warnSite } from "@/lib/site/diff";
import { SEED } from "@/lib/site/seed";
import type { SiteData } from "@/lib/site/types";

function gridSite(): SiteData {
  const s = structuredClone(SEED);
  s.settings.pricingMode = "grid";
  s.settings.maxKg = 25;
  for (const d of s.destinations) for (const svc of Object.keys(d.rates)) d.rates[svc]!.grid = slabToGrid(s.settings, d.rates[svc]!);
  return s;
}

describe("grid weights and lookup", () => {
  it("lists whole kilograms up to the cargo threshold", () => {
    expect(gridWeights({ maxKg: 25 })).toHaveLength(25);
    expect(gridWeights({ maxKg: 25 })[24]).toBe(25);
    expect(gridWeights({ maxKg: 0 })).toHaveLength(30);
  });
  it("charges the next priced kilogram up", () => {
    const g = { "1": 100, "2": 200, "5": 500 };
    expect(gridPrice(g, 0.4, 25)).toEqual({ kg: 1, price: 100 });
    expect(gridPrice(g, 1, 25)).toEqual({ kg: 1, price: 100 });
    expect(gridPrice(g, 1.2, 25)).toEqual({ kg: 2, price: 200 });
    expect(gridPrice(g, 3, 25)).toEqual({ kg: 5, price: 500 }); // 3 and 4 kg have no price → 5 kg
    expect(gridPrice(g, 6, 25)).toBeNull();
    expect(gridPrice(undefined, 1, 25)).toBeNull();
  });
});

describe("grid pricing end to end", () => {
  const s = gridSite();
  it("converts slab prices to the same per-kg totals", () => {
    // UK express slab: 4500 first 0.5 kg + 1100 per 0.5 kg → 2 kg = 4500 + 3×1100 = 7800
    expect(s.destinations[0]!.rates.express!.grid!["2"]).toBe(7800);
    expect(s.destinations[0]!.rates.express!.grid!["25"]).toBe(4500 + 49 * 1100);
  });
  it("bills whole kilograms and applies tax and rounding", () => {
    expect(computeWeights(s.settings, [{ kg: 1.2 }]).billableG).toBe(2000);
    const res = priceAll(s, { destId: "gb", rows: [{ kg: 1.2 }] });
    if (!res.ok) throw new Error(res.reason);
    expect(res.prices.express!.total).toBe(7800);
    expect(res.prices.express!.gridKg).toBe(2);
    expect(priceAll(s, { destId: "gb", rows: [{ kg: 25.5 }] })).toMatchObject({ ok: false, reason: "overmax" });
  });
  it("keeps the document rate and reports unavailable services", () => {
    const res = priceAll(s, { destId: "gb", type: "doc", rows: [{ kg: 0.3 }] });
    if (!res.ok) throw new Error(res.reason);
    expect(res.prices.express!.docRate).toBe(true);
    const t = structuredClone(s);
    t.destinations[0]!.rates.express = { grid: {} };
    const r2 = priceAll(t, { destId: "gb", rows: [{ kg: 1 }] });
    if (!r2.ok) throw new Error(r2.reason);
    expect(r2.prices.express).toBeNull();
  });
  it("validates, warns and diffs grid prices", () => {
    expect(validateSite(s)).toEqual([]);
    const b = structuredClone(s);
    b.destinations[0]!.rates.express!.grid!["3"] = 0;
    expect(validateSite(b)).toContain("United Kingdom · Express 3 kg: price must be greater than 0 or left blank.");
    const c = structuredClone(s);
    c.destinations[0]!.rates.express!.grid!["4"] = 100; // cheaper than 3 kg
    expect(warnSite(c).some((w) => w.includes("4 kg (PKR 100) is cheaper than 3 kg"))).toBe(true);
    const d = diffSite(s, c);
    expect(d.count).toBe(1);
    expect(d.lines[0]).toMatchObject({ label: "United Kingdom · Express 4 kg", old: "PKR 12,200", new: "PKR 100" });
    expect(d.lines[0]!.flag).toMatch(/-99%/);
  });
  it("warns that weights above the last priced kg get no price, and only gaps fall to the next heavier kg", () => {
    // A sheet whose columns stop at 3 kg: 4–25 kg have no heavier priced kg to fall to, so the engine prices nothing.
    const t = structuredClone(s);
    t.destinations[0]!.rates.express!.grid = { "1": 4000, "2": 6000, "3": 8000 };
    expect(priceAll(t, { destId: "gb", rows: [{ kg: 5 }] })).toMatchObject({ ok: true, prices: { express: null } });
    const w = warnSite(t);
    expect(w).toContain("United Kingdom · Express: no price above 3 kg — parcels of 4–25 kg get no price on the site and are sent to WhatsApp.");
    expect(w.some((x) => x.startsWith("United Kingdom · Express:") && x.includes("next heavier priced kg"))).toBe(false);
    // A gap below the last priced kg is billed at the next heavier priced kg; the top-only case names one weight.
    const u = structuredClone(s);
    u.destinations[0]!.rates.express!.grid = { "1": 4000, "2": 6000, "5": 9000, "24": 20000 };
    expect(priceAll(u, { destId: "gb", rows: [{ kg: 3 }] })).toMatchObject({ ok: true, prices: { express: { gridKg: 5 } } });
    const w2 = warnSite(u);
    expect(w2).toContain("United Kingdom · Express: no price for 20 weights (3, 4, 6, 7, 8, 9… kg) — those parcels are charged at the next heavier priced kg.");
    expect(w2).toContain("United Kingdom · Express: no price above 24 kg — parcels of 25 kg get no price on the site and are sent to WhatsApp.");
  });
});

describe("grid sheets", () => {
  it("recognises weight headers", () => {
    expect(weightHeader("1 kg")).toBe(1);
    expect(weightHeader("2KG")).toBe(2);
    expect(weightHeader("Express 3 Kgs")).toBe(3);
    expect(weightHeader("2.5 kg")).toBeNull();
    expect(weightHeader("Express Add 0.5")).toBeNull();
    expect(weightHeader("Express 0.5 KG")).toBeNull();
    expect(weightHeader("Country")).toBeNull();
    expect(weightHeader("Docs")).toBeNull();
  });
  it("assigns weight columns to services by header words, group row, or run order", () => {
    const three = { "1": 1, "2": 2, "3": 3 };
    const threeB = { "1": 4, "2": 5, "3": 6 };
    const byWords = weightColumns(["Country", "Express 1 kg", "Express 2 kg", "Express 3 kg", "Normal 1 kg", "Normal 2 kg", "Normal 3 kg"], SEED.services);
    expect(byWords).toEqual({ express: three, normal: threeB });
    const byGroup = weightColumns(["Country", "1", "2", "3", "1", "2", "3"], SEED.services, ["", "Express", "", "", "Economy", "", ""]);
    expect(byGroup).toEqual({ express: three, normal: threeB });
    const byRun = weightColumns(["Country", "1 kg", "2 kg", "3 kg", "1 kg", "2 kg", "3 kg"], SEED.services);
    expect(byRun).toEqual({ express: three, normal: threeB });
    // Two weight-looking columns are a slab sheet, not a grid.
    expect(weightColumns(["Country", "Express 1 kg", "Express 2 kg"], SEED.services)).toEqual({});
  });
  it("imports a grid sheet with margin and applies it", () => {
    const rows = [
      ["Country", "Express", "", "", "Normal", "", "", "Days"],
      ["", "1 kg", "2 kg", "3 kg", "1 kg", "2 kg", "3 kg", "Express Days"],
      ["United Kingdom", "4,000", "6,000", "8,000", "3000", "4500", "6000", "3-5"],
    ];
    const map = autoMap(rows[1]!, SEED.services, rows[0]);
    expect(map["express.kg.1"]).toBe(1);
    expect(map["normal.kg.3"]).toBe(6);
    const im = buildImport({ rows, headerRow: 1, map, opts: { addNew: true, hideMissing: false, cost: true, margin: 10, mround: 10 }, draft: SEED, fileName: "grid.xlsx" });
    expect(im.errors).toEqual([]);
    expect(im.rows[0]!.rates.express).toMatchObject({ grid: { "1": 4400, "2": 6600, "3": 8800 }, costGrid: { "1": 4000, "2": 6000, "3": 8000 }, days: "3–5" });
    expect(im.rows[0]!.rates.normal!.grid).toEqual({ "1": 3300, "2": 4950, "3": 6600 });
  });
});
