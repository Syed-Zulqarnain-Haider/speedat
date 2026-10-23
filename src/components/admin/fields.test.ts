import { describe, expect, it } from "vitest";
import { numOrNull } from "./fields";

describe("numOrNull", () => {
  it("reads prices written with separators or a currency label", () => {
    // Chrome turned "4,500" in a number box into "", which used to delete the price.
    expect(numOrNull("4,500")).toBe(4500);
    expect(numOrNull("4 500")).toBe(4500);
    expect(numOrNull("1,234.5")).toBe(1234.5);
    expect(numOrNull("Rs 4,500")).toBe(4500);
    expect(numOrNull("PKR4500")).toBe(4500);
    expect(numOrNull(" 12 ")).toBe(12);
  });

  it("keeps a half-typed decimal", () => {
    expect(numOrNull("4.")).toBe(4);
    expect(numOrNull(".5")).toBe(0.5);
  });

  it("reads only an emptied box as a deletion", () => {
    expect(numOrNull("")).toBeNull();
    expect(numOrNull("   ")).toBeNull();
  });

  it("never reads junk as a deletion or as a different number", () => {
    for (const junk of ["abc", "12abc", "4..5", "-", "Rs", "1e9x", "4,5,0.0.0"]) expect(numOrNull(junk)).toBeUndefined();
  });
});
