import { describe, expect, it } from "vitest";
import { computeWeights, priceAll } from "@/lib/pricing/engine";
import { gridSeed } from "@/lib/site/seed";
import { entry, metricValue, newRow, shown, toRows, type PieceRow } from "./pieces";

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
    const rows = toRows([newRow(1, "4")], "pkg");
    expect(rows[0]!.kg).toBe(4);
    expect(computeWeights(GRID.settings, rows).billableG).toBe(4000);
    const at25 = priceAll(GRID, { destId: "ca", type: "pkg", rows: toRows([newRow(1, "25")], "pkg") });
    expect(at25.ok).toBe(true);
    // The same weight typed in pounds is priced from those pounds: 8.82 lb really is over 4 kg.
    const lb = toRows([{ ...newRow(1), kg: entry("8.82", "imperial") }], "pkg");
    expect(computeWeights(GRID.settings, lb).billableG).toBe(5000);
  });

  it("converts an imperial entry to metric for the engine at full precision", () => {
    expect(metricValue(entry("10", "imperial"), "kg")).toBeCloseTo(4.5359237, 7);
    expect(metricValue(entry("12", "imperial"), "L")).toBeCloseTo(30.48, 7);
    expect(metricValue(entry("40"), "L")).toBe(40);
    expect(metricValue(entry(""), "kg")).toBeNull();
  });

  it("drops sizes for documents and defaults the quantity", () => {
    const r: PieceRow = { ...metricRow(), qty: "" };
    expect(toRows([r], "doc")[0]).toEqual({ kg: 3.2, qty: 1, L: null, W: null, H: null });
    expect(toRows([r], "pkg")[0]).toEqual({ kg: 3.2, qty: 1, L: 40, W: 30, H: 20 });
  });
});
