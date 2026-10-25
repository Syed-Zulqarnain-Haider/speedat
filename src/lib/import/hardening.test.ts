/**
 * Regression tests for the rate-sheet import review: pricing-mode awareness,
 * what the auto-publish tolerance measures, group/title rows, the name
 * column, remembered header rows, grid replacement, 0 cells and "did this
 * sheet go live". Pure: the real carrier fixtures go through parse only.
 */
import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import { priceAll, slabToGrid } from "@/lib/pricing/engine";
import { gridSeed, SEED } from "@/lib/site/seed";
import type { SiteData } from "@/lib/site/types";
import { applyImport, autoMap, buildImport, describeMove, headerNames, largestMove, mapFields, mappedKgs, suggestLayout, weightColumns } from "./parse";
import type { ImportOptions } from "./types";
import { largestSheet, readWorkbook } from "./workbook";

const opts: ImportOptions = { addNew: true, hideMissing: false, cost: false, margin: 0, mround: 1 };
const costOpts: ImportOptions = { addNew: true, hideMissing: false, cost: true, margin: 15, mround: 10 };

function fixture(name: string) {
  const wb = readWorkbook(name, new Uint8Array(readFileSync(`tests/fixtures/${name}`)));
  return wb.sheets[largestSheet(wb)]!;
}

function importFixture(name: string, draft: SiteData, o: ImportOptions = costOpts) {
  const rows = fixture(name);
  const l = suggestLayout(rows, draft.importProfiles, draft.services);
  return buildImport({ rows, headerRow: l.headerRow, map: l.map, opts: o, draft, fileName: name });
}

const total = (d: SiteData, destId: string, kg: number) => (priceAll(d, { destId, rows: [{ kg }] }) as { ok: true; prices: Record<string, { total: number } | null> }).prices.express?.total;

describe("bug 1: the import follows the document's pricing mode", () => {
  it("turns a slab sheet into kilogram boxes on a grid document, so quotes actually change", () => {
    const g = gridSeed();
    const im = importFixture("airline-rates-day2.xlsx", g);
    expect(im.errors).toEqual([]);
    expect(im.warnings[0]).toMatch(/1–25 kg prices/);
    const uk = im.rows.find((r) => r.existing === "gb")!.rates.express!;
    expect(uk.grid).toEqual(slabToGrid(g.settings, { first: uk.first, addl: uk.addl }));
    const next = applyImport(g, im);
    expect(total(g, "gb", 5)).toBe(14400);
    expect(total(next, "gb", 5)).not.toBe(14400);
    expect(total(next, "gb", 5)).toBe(next.destinations.find((d) => d.id === "gb")!.rates.express!.grid!["5"]);
  });

  it("measures the move on the boxes the engine prices", () => {
    const g = gridSeed();
    const im = importFixture("airline-rates-day2.xlsx", g, { ...costOpts, addNew: false });
    const m = largestMove(g, im)!;
    expect(m.label).toMatch(/ kg$|doc$/);
    expect(Number.isFinite(m.pct)).toBe(true);
    expect(m.pct).toBeGreaterThan(0);
  });

  it("refuses a per-kilogram sheet on a slab document instead of saving prices nothing reads", () => {
    const rows = [
      ["Country", "1 kg", "2 kg", "3 kg"],
      ["United Kingdom", "100", "200", "300"],
    ];
    const im = buildImport({ rows, headerRow: 0, map: autoMap(rows[0]!, SEED.services), opts, draft: SEED, fileName: null });
    expect(im.rows).toEqual([]);
    expect(im.errors[0]).toMatch(/per-kilogram price columns/);
  });
});

describe("bug 9: largestMove counts what has no percentage", () => {
  const rows = [
    ["Country", "Express First", "Express Add"],
    ["United Kingdom", "4500", "1100"],
    ["Narnia", "100", "100"],
  ];
  const map = autoMap(rows[0]!, SEED.services);

  it("a new destination is unbounded; without additions an unchanged sheet moves nothing", () => {
    const im = buildImport({ rows, headerRow: 0, map, opts, draft: SEED, fileName: null });
    expect(largestMove(SEED, im)).toEqual({ pct: Infinity, label: "Narnia is a new destination" });
    expect(largestMove(SEED, { ...im, opts: { ...opts, addNew: false } })).toBeNull();
  });

  it("a service priced for the first time and a hidden destination are unbounded", () => {
    const d = structuredClone(SEED);
    delete d.destinations.find((x) => x.id === "gb")!.rates.normal;
    const r2 = [
      ["Country", "Normal First", "Normal Add"],
      ["United Kingdom", "3200", "850"],
    ];
    const im = buildImport({ rows: r2, headerRow: 0, map: autoMap(r2[0]!, SEED.services), opts, draft: d, fileName: null });
    expect(describeMove(largestMove(d, im)!)).toBe("United Kingdom normal first is priced for the first time");
    const hid = buildImport({ rows: rows.slice(0, 2), headerRow: 0, map, opts: { ...opts, hideMissing: true }, draft: SEED, fileName: null });
    expect(largestMove(SEED, hid)!.pct).toBe(Infinity);
    expect(largestMove(SEED, hid)!.label).toMatch(/would be hidden/);
  });

  it("the real fixture adds France, so it can never publish unattended", () => {
    expect(largestMove(gridSeed(), importFixture("airline-rates.xlsx", gridSeed()))).toEqual({ pct: Infinity, label: "France is a new destination" });
  });
});

describe("bug 5: a title row is not a service group row", () => {
  const headers = ["Country", "1 kg", "2 kg", "3 kg", "1 kg", "2 kg", "3 kg"];
  it("ignores a title above the header and assigns runs in service order", () => {
    expect(weightColumns(headers, SEED.services, ["Express and Economy rates 2026", "", "", "", "", "", ""])).toEqual({
      express: { "1": 1, "2": 2, "3": 3 },
      normal: { "1": 4, "2": 5, "3": 6 },
    });
    expect(weightColumns(headers, SEED.services, ["Rate card", "Effective 1 Oct", "", "", "", "", ""])).toEqual({
      express: { "1": 1, "2": 2, "3": 3 },
      normal: { "1": 4, "2": 5, "3": 6 },
    });
  });
  it("still honours a real group row", () => {
    expect(weightColumns(headers, SEED.services, ["", "Economy", "", "", "Express", "", ""])).toEqual({
      normal: { "1": 1, "2": 2, "3": 3 },
      express: { "1": 4, "2": 5, "3": 6 },
    });
  });
  it("offers the weight columns in the mapper", () => {
    const map = autoMap(headers, SEED.services);
    expect(mappedKgs(map)).toEqual([1, 2, 3]);
    const keys = mapFields(SEED.services, mappedKgs(map)).map((f) => f.key);
    expect(keys).toEqual(expect.arrayContaining(["express.kg.1", "normal.kg.3"]));
  });
});

describe("bug 6: the destination column", () => {
  it("prefers Country over a zone column", () => {
    expect(autoMap(["Zone", "Country", "Express First", "Express Add"], SEED.services).name).toBe(1);
    expect(autoMap(["Region", "Destination", "Express First"], SEED.services).name).toBe(1);
    expect(autoMap(["Zone", "Express First", "Express Add"], SEED.services).name).toBe(0);
  });
});

describe("bug 8: remembered header rows", () => {
  // Three filled cells in the title row fool detectHeaderRow into taking it as the header.
  const rows = [
    ["Speedat", "rates", "October"],
    ["Country", "Express First", "Express Add"],
    ["United Kingdom", "4600", "1100"],
  ];
  it("stores the header row in the profile and finds it again on the next copy", () => {
    const im = buildImport({ rows, headerRow: 1, map: autoMap(headerNames(rows[1]!), SEED.services), opts, draft: SEED, fileName: "x.xlsx" });
    expect(im.profile?.headerRow).toBe(1);
    const withProfile = applyImport(SEED, im);
    expect(suggestLayout(rows, SEED.importProfiles, SEED.services).headerRow).toBe(0);
    const l = suggestLayout(rows, withProfile.importProfiles, SEED.services);
    expect(l).toMatchObject({ headerRow: 1, profile: { signature: im.profile!.signature } });
    expect(l.map["express.first"]).toBe(1);
  });
  it("re-maps when the header row is chosen by hand", () => {
    const l = suggestLayout(rows, [], SEED.services, 1);
    expect(l.headerRow).toBe(1);
    expect(l.map).toMatchObject({ "name": 0, "express.first": 1, "express.addl": 2 });
  });
});

describe("bug 10: grids, zeros", () => {
  it("replaces the grid of a service the sheet covers (no stale heavier boxes)", () => {
    const g = gridSeed();
    const rows = [
      ["Country", "1 kg", "2 kg", "3 kg"],
      ["United Kingdom", "1000", "2000", "3000"],
    ];
    const im = buildImport({ rows, headerRow: 0, map: autoMap(rows[0]!, g.services), opts, draft: g, fileName: null });
    const next = applyImport(g, im);
    expect(next.destinations.find((d) => d.id === "gb")!.rates.express!.grid).toEqual({ "1": 1000, "2": 2000, "3": 3000 });
    // The removed 4–25 kg boxes are a change a person must see.
    expect(largestMove(g, im)!.pct).toBe(Infinity);
  });
  it("reads a 0 cell as blank (the current price stays), never as a free price", () => {
    const rows = [
      ["Country", "Express First", "Express Add", "Express Docs"],
      ["United Kingdom", "0", "0", "3900"],
    ];
    const im = buildImport({ rows, headerRow: 0, map: autoMap(rows[0]!, SEED.services), opts, draft: SEED, fileName: null });
    expect(im.rows[0]!.rates.express).toMatchObject({ first: null, addl: null, doc: 3900 });
    expect(im.warnings.join(" ")).toMatch(/2 price cells read 0/);
    expect(total(applyImport(SEED, im), "gb", 5)).toBe(total(SEED, "gb", 5));
  });
});

