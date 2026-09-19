import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import { autoMap, buildImport, detectHeaderRow, headerNames } from "./parse";
import { fileKind, largestSheet, readWorkbook } from "./workbook";
import { SEED } from "@/lib/site/seed";

const fixture = (name: string) => new Uint8Array(readFileSync(`tests/fixtures/${name}`));

describe("readWorkbook", () => {
  it("reads a real carrier workbook: two tabs, title rows, numbers as text", () => {
    const wb = readWorkbook("airline-rates.xlsx", fixture("airline-rates.xlsx"));
    expect(wb.names).toEqual(["Notes", "Rates"]);
    expect(largestSheet(wb)).toBe("Rates");
    const rows = wb.sheets.Rates!;
    const hdr = detectHeaderRow(rows);
    expect(headerNames(rows[hdr]!)[0]).toBe("Country");
    const map = autoMap(headerNames(rows[hdr]!), SEED.services);
    const im = buildImport({ rows, headerRow: hdr, map, opts: { addNew: true, hideMissing: false, cost: true, margin: 15, mround: 10 }, draft: SEED, fileName: "airline-rates.xlsx" });
    expect(im.rows).toHaveLength(11);
    expect(im.errors).toEqual([]);
    expect(im.rows.find((r) => r.name === "UAE")?.existing).toBe("ae");
    expect(im.rows.find((r) => r.name === "United Kingdom")?.rates.express).toMatchObject({ costFirst: 4000, first: 4600, days: "3–5", doc: 4020 });
  });

  it("rejects unsupported and oversized files", () => {
    expect(fileKind("rates.pdf")).toBeNull();
    expect(fileKind("RATES.XLSX")).toBe("xlsx");
    expect(() => readWorkbook("x.pdf", new Uint8Array(10))).toThrow(/Only .xlsx/);
    expect(() => readWorkbook("x.xlsx", new Uint8Array(9 * 1024 * 1024))).toThrow(/larger/);
    expect(() => readWorkbook("x.xlsx", new Uint8Array([1, 2, 3]))).toThrow(/could not be read/);
  });

  it("reads CSV through the same door", () => {
    const wb = readWorkbook("r.csv", new TextEncoder().encode("Country,Express First,Express Add\nUK,4500,1100\n"));
    expect(wb.sheets.Sheet1).toEqual([
      ["Country", "Express First", "Express Add"],
      ["UK", "4500", "1100"],
    ]);
  });
});
