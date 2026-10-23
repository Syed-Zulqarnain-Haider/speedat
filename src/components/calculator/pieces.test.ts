import { describe, expect, it } from "vitest";
import { computeWeights, priceAll } from "@/lib/pricing/engine";
import { gridSeed, SEED } from "@/lib/site/seed";
import { entry, hasDims, metricValue, newRow, shown, toRows, type PieceRow } from "./pieces";

/** Whole-kilogram price boxes up to the 25 kg cargo threshold, as the site ships. */
const GRID = gridSeed();

/** 3.2 kg, 40 × 30 × 20 cm, typed in metric. */
const metricRow = (): PieceRow => ({ key: 1, kg: entry("3.2"), qty: "1", L: entry("40"), W: entry("30"), H: entry("20") });

describe("units toggle round-trip", () => {
  it("shows a metric entry in inches / pounds to two decimals and hands the typed value back untouched", () => {
    const r = metricRow();
    expect([shown(r.kg, "imperial", "kg"), shown(r.L, "imperial", "L"), shown(r.W, "imperial", "W"), shown(r.H, "imperial", "H")]).toEqual(["7.05", "15.75", "11.81", "7.87"]);
    // The toggle used to convert in place and round each way: 40 × 30 × 20 came back as 40.01 × 30 × 19.99.
    expect([shown(r.kg, "metric", "kg"), shown(r.L, "metric", "L"), shown(r.W, "metric", "W"), shown(r.H, "metric", "H")]).toEqual(["3.2", "40", "30", "20"]);
  });

  it("keeps an imperial entry as typed through metric and back", () => {
    const lb = entry("10", "imperial");
    expect(shown(lb, "metric", "kg")).toBe("4.54");
    expect(shown(lb, "imperial", "kg")).toBe("10"); // was 10.01 after a trip through 4.54 kg
    const inch = entry("12", "imperial");
    expect(shown(inch, "metric", "L")).toBe("30.48");
    expect(shown(inch, "imperial", "L")).toBe("12");
  });

  it("leaves empty and non-positive text alone in either unit", () => {
    expect(shown(entry(""), "imperial", "kg")).toBe("");
    expect(shown(entry("0"), "imperial", "kg")).toBe("0");
    expect(shown(entry("abc", "imperial"), "metric", "L")).toBe("abc");
  });
});

describe("pricing from typed values", () => {
  it("prices a metric entry from the typed number whatever units are showing", () => {
    // 4 kg shown as 8.82 lb used to be priced from 8.82 lb = 4.0007 kg and billed as 5 kg; 25 kg (55.12 lb) tipped into cargo.
    const rows = toRows([newRow(1, "4")]);
    expect(rows[0]!.kg).toBe(4);
    expect(computeWeights(GRID.settings, rows).billableG).toBe(4000);
    const at25 = priceAll(GRID, { destId: "ca", type: "pkg", rows: toRows([newRow(1, "25")]) });
    expect(at25.ok).toBe(true);
    // The same weight typed in pounds is priced from those pounds: 8.82 lb really is over 4 kg.
    const lb = toRows([{ ...newRow(1), kg: entry("8.82", "imperial") }]);
    expect(computeWeights(GRID.settings, lb).billableG).toBe(5000);
  });

  it("converts an imperial entry to metric for the engine at full precision", () => {
    expect(metricValue(entry("10", "imperial"), "kg")).toBeCloseTo(4.5359237, 7);
    expect(metricValue(entry("12", "imperial"), "L")).toBeCloseTo(30.48, 7);
    expect(metricValue(entry("40"), "L")).toBe(40);
    expect(metricValue(entry(""), "kg")).toBeNull();
  });

  it("keeps sizes for every shipment type and defaults the quantity", () => {
    const r: PieceRow = { ...metricRow(), qty: "" };
    expect(toRows([r])[0]).toEqual({ kg: 3.2, qty: 1, L: 40, W: 30, H: 20 });
    expect(hasDims(r)).toBe(true);
    expect(hasDims(newRow(1, "2"))).toBe(false);
  });
});

describe("documents are priced on their size too", () => {
  const box = (): PieceRow => ({ key: 1, kg: entry("5"), qty: "1", L: entry("60"), W: entry("60"), H: entry("60") });

  it("prices a 5 kg 60×60×60 box ticked Documents exactly like the package (volumetric 43.2 kg → 43.5 kg)", () => {
    const doc = priceAll(SEED, { destId: "gb", type: "doc", rows: toRows([box()]) });
    const pkg = priceAll(SEED, { destId: "gb", type: "pkg", rows: toRows([box()]) });
    if (!doc.ok || !pkg.ok) throw new Error("expected ok");
    expect(doc.weights.billableG).toBe(43500);
    expect(doc.prices.express!.docRate).toBe(false);
    expect(doc.prices.express!.total).toBe(pkg.prices.express!.total);
  });

  it("sends the same box to the cargo message when it is over the limit", () => {
    const res = priceAll(GRID, { destId: "gb", type: "doc", rows: toRows([box()]) });
    expect(res).toMatchObject({ ok: false, reason: "overmax" });
  });

  it("keeps the document rate for an envelope with a size inside the limit", () => {
    const env: PieceRow = { key: 1, kg: entry("0.3"), qty: "1", L: entry("35"), W: entry("25"), H: entry("1") };
    const res = priceAll(SEED, { destId: "gb", type: "doc", rows: toRows([env]) });
    if (!res.ok) throw new Error(res.reason);
    expect(res.prices.express!.docRate).toBe(true);
  });
});
