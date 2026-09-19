import { describe, expect, it } from "vitest";
import { applyImport, autoMap, buildImport, buildPasteImport, detectHeaderRow, headerSignature, largestMove, parseDelimited } from "./parse";
import { SEED } from "@/lib/site/seed";

const services = SEED.services;

describe("parseDelimited", () => {
  it("handles tabs, quotes and blank lines", () => {
    expect(parseDelimited('a\tb\n"c, d"\t"e ""f"""\n\n')).toEqual([
      ["a", "b"],
      ["c, d", 'e "f"'],
    ]);
    expect(parseDelimited("x;y\r\n1;2")).toEqual([
      ["x", "y"],
      ["1", "2"],
    ]);
  });
});

describe("detectHeaderRow / autoMap", () => {
  const rows = [
    ["Speedat rate card", "", ""],
    ["Effective", "20 Sept 2026", ""],
    ["Country", "Express 0.5kg", "Express Add 0.5", "Exp TAT", "Economy First", "Economy Each", "Eco Days", "Docs Express"],
    ["United Kingdom", "4,500", "1,100", "3-5 days", "3200", "850", "6 to 9", "3900"],
  ];
  it("skips title rows", () => {
    expect(detectHeaderRow(rows)).toBe(2);
  });
  it("maps synonyms to services and fields", () => {
    const m = autoMap(rows[2]!, services);
    expect(m).toMatchObject({ "name": 0, "express.first": 1, "express.addl": 2, "express.days": 3, "express.doc": 7, "normal.first": 4, "normal.addl": 5, "normal.days": 6, "normal.doc": -1 });
  });
  it("signatures ignore case and punctuation", () => {
    expect(headerSignature(["Country", "Express (0.5 kg)"])).toBe(headerSignature(["COUNTRY ", "express 0.5 kg"]));
    expect(headerSignature(["Country", "Express 0.5kg"])).not.toBe(headerSignature(["Country", "Express 1kg"]));
  });
});

describe("buildImport", () => {
  const rows = [
    ["Country", "Express First", "Express Add", "Express Days", "Normal First", "Normal Add", "Normal Days"],
    ["United Kingdom", "4,700", "1,150", "3-5", "3300", "880", "6-9"],
    ["France", "5000", "1200", "4", "", "", ""],
    ["Bad Land", "abc", "1", "", "", "", ""],
    ["Half", "1000", "", "", "", "", ""],
    ["Total", "99", "99", "", "", "", ""],
    ["france", "5100", "1250", "4-6", "", "", ""],
  ];
  const map = autoMap(rows[0]!, services);
  const opts = { addNew: true, hideMissing: false, cost: false, margin: 0, mround: 1 };

  it("reads prices, matches existing destinations, reports problems", () => {
    const im = buildImport({ rows, headerRow: 0, map, opts, draft: SEED, fileName: "rates.xlsx" });
    expect(im.rows.map((r) => [r.name, r.existing])).toEqual([
      ["United Kingdom", "gb"],
      ["france", null],
      ["Half", null],
    ]);
    expect(im.rows[0]!.rates.express).toMatchObject({ first: 4700, addl: 1150, days: "3–5" });
    expect(im.errors).toEqual(["Row 4 (Bad Land): Express price is not a number"]);
    expect(im.warnings).toContain("Row 5 (Half): Express has only one of the two package prices");
    expect(im.warnings).toContain("Row 7 (france): appears more than once, the last row wins");
    expect(im.profile?.signature).toBe(headerSignature(rows[0]!));
    expect(im.profile?.name).toBe("rates.xlsx");
  });

  it("applies a margin to carrier costs and keeps the cost for the preview", () => {
    const im = buildImport({ rows, headerRow: 0, map, opts: { ...opts, cost: true, margin: 15, mround: 10 }, draft: SEED, fileName: null });
    const uk = im.rows[0]!.rates.express!;
    expect(uk.costFirst).toBe(4700);
    expect(uk.first).toBe(5410); // 4700 × 1.15 = 5405 → nearest 10
    expect(uk.addl).toBe(1320); // 1322.5 → 1320
  });

  it("lists destinations missing from the sheet when asked to hide them", () => {
    const im = buildImport({ rows, headerRow: 0, map, opts: { ...opts, hideMissing: true }, draft: SEED, fileName: null });
    expect(im.hide).toHaveLength(9);
    expect(im.hide).not.toContain("gb");
  });

  it("refuses without a name column", () => {
    const im = buildImport({ rows, headerRow: 0, map: { ...map, name: -1 }, opts, draft: SEED, fileName: null });
    expect(im.rows).toEqual([]);
    expect(im.errors[0]).toMatch(/destination name/);
  });
});

describe("applyImport", () => {
  it("updates, adds, keeps blanks, hides and remembers the layout", () => {
    const rows = [
      ["Country", "Express First", "Express Add", "Express Days"],
      ["United Kingdom", "4700", "", ""],
      ["France", "5000", "1200", "4"],
    ];
    const map = autoMap(rows[0]!, services);
    const im = buildImport({ rows, headerRow: 0, map, opts: { addNew: true, hideMissing: true, cost: false, margin: 0, mround: 1 }, draft: SEED, fileName: "x.csv" });
    const out = applyImport(SEED, im);
    const uk = out.destinations.find((d) => d.id === "gb")!;
    expect(uk.rates.express).toEqual({ first: 4700, addl: 1100, days: "3–5", doc: 3900 }); // blanks keep old values
    expect(uk.rates.normal).toEqual(SEED.destinations[0]!.rates.normal);
    const fr = out.destinations.find((d) => d.name === "France")!;
    expect(fr.id).toBe("france");
    expect(fr.active).toBe(true);
    expect(fr.rates.express).toEqual({ first: 5000, addl: 1200, days: "4" });
    expect(out.destinations.filter((d) => d.active).map((d) => d.id)).toEqual(["gb", "france"]);
    expect(out.importProfiles).toHaveLength(1);
    expect(SEED.importProfiles).toHaveLength(0); // input untouched
  });

  it("does not add new destinations when addNew is off", () => {
    const rows = [
      ["Country", "Express First", "Express Add"],
      ["Nowhere", "1", "1"],
    ];
    const im = buildImport({ rows, headerRow: 0, map: autoMap(rows[0]!, services), opts: { addNew: false, hideMissing: false, cost: false, margin: 0, mround: 1 }, draft: SEED, fileName: null });
    expect(applyImport(SEED, im).destinations).toHaveLength(10);
  });
});

describe("matchKey", () => {
  it("folds accents and common aliases", () => {
    const rows = [
      ["Country", "Express First", "Express Add"],
      ["UAE", "1", "1"],
      ["Turkiye", "1", "1"],
      ["U.K.", "1", "1"],
      ["USA", "1", "1"],
      ["KSA", "1", "1"],
      ["Nowhere", "1", "1"],
    ];
    const im = buildImport({ rows, headerRow: 0, map: autoMap(rows[0]!, services), opts: { addNew: true, hideMissing: false, cost: false, margin: 0, mround: 1 }, draft: SEED, fileName: null });
    expect(im.rows.map((r) => r.existing)).toEqual(["ae", "tr", "gb", "us", "sa", null]);
  });
});

describe("buildPasteImport / largestMove", () => {
  it("reads pasted rows in the documented column order, skipping a header", () => {
    const im = buildPasteImport("Destination\tExpress first\tadd\tdays\tdocs\nUnited Kingdom\t4600\t1100\t3-5\t3900\t3300\t850\t6-9\t2800", SEED);
    expect(im.rows).toHaveLength(1);
    expect(im.rows[0]!.rates.express).toMatchObject({ first: 4600, addl: 1100, days: "3–5", doc: 3900 });
    expect(im.rows[0]!.rates.normal).toMatchObject({ first: 3300, addl: 850, days: "6–9", doc: 2800 });
    expect(im.profile).toBeNull();
    expect(largestMove(SEED, im)).toEqual({ pct: 3.125, label: "United Kingdom normal first" });
  });
});
