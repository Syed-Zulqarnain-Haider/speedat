import { describe, expect, it } from "vitest";
import { phoneSegments, phoneSegmentsExcept, samePhone } from "./phones";

describe("samePhone", () => {
  it("matches the same number in international, local and digits-only spellings", () => {
    expect(samePhone("+92 333 766 7076", "923337667076")).toBe(true);
    expect(samePhone("0333 766 7076", "923337667076")).toBe(true);
    expect(samePhone("0092 333 7667076", "+92 333 766 7076")).toBe(true);
    expect(samePhone("(042) 3521-9098", "+92 42 3521 9098")).toBe(true);
  });

  it("does not match a different number, a prefix or a short run", () => {
    expect(samePhone("+92 334 77 33 328", "923337667076")).toBe(false);
    expect(samePhone("92333", "923337667076")).toBe(false);
    expect(samePhone("+92 333 766", "923337667076")).toBe(false);
    expect(samePhone("", "923337667076")).toBe(false);
  });
});

describe("phoneSegmentsExcept", () => {
  const WA = "923337667076";
  const LAND = "+92 42 3521 9098";

  it("drops the item that repeats the WhatsApp number, label and all, and keeps the other number", () => {
    expect(phoneSegmentsExcept("Mobile / WhatsApp: +92 333 766 7076 · +92 334 77 33 328", [WA, LAND])).toEqual([
      { text: "+92 334 77 33 328", tel: "tel:+923347733328" },
    ]);
  });

  it("returns the line exactly as phoneSegments reads it when nothing repeats", () => {
    const line = "Mobile: +92 334 77 33 328 · Office 2: 042 1234567";
    expect(phoneSegmentsExcept(line, [WA, LAND])).toEqual(phoneSegments(line));
  });

  it("comes back empty when every number is already stated", () => {
    expect(phoneSegmentsExcept("WhatsApp +92 333 766 7076, landline 042 3521 9098", [WA, LAND])).toEqual([]);
    expect(phoneSegmentsExcept("+92 333 766 7076", [WA])).toEqual([]);
  });

  it("keeps each item's own label and joins the survivors with a middle dot", () => {
    expect(phoneSegmentsExcept("Landline: 042 3521 9098, Karachi office: 021 1234567 | Ahmed: 0300 1234567", [WA, LAND])).toEqual([
      { text: "Karachi office: " },
      { text: "021 1234567", tel: "tel:0211234567" },
      { text: " · " },
      { text: "Ahmed: " },
      { text: "0300 1234567", tel: "tel:03001234567" },
    ]);
  });

  it("drops only the stated number and its joining word inside one item", () => {
    expect(phoneSegmentsExcept("Mobile: +92 333 766 7076 or +92 334 77 33 328", [WA])).toEqual([
      { text: "Mobile: " },
      { text: "+92 334 77 33 328", tel: "tel:+923347733328" },
    ]);
    expect(phoneSegmentsExcept("+92 334 77 33 328 / +92 333 766 7076", [WA])).toEqual([{ text: "+92 334 77 33 328", tel: "tel:+923347733328" }]);
  });

  it("keeps a text-only item and ignores blank or short known numbers", () => {
    expect(phoneSegmentsExcept("Ask for Ahmed · +92 333 766 7076 · +92 334 77 33 328", [WA, "", "123"])).toEqual([
      { text: "Ask for Ahmed" },
      { text: " · " },
      { text: "+92 334 77 33 328", tel: "tel:+923347733328" },
    ]);
  });

  it("handles an empty field", () => {
    expect(phoneSegmentsExcept("", [WA])).toEqual([]);
  });
});

describe("phoneSegments", () => {
  it("links every number in a labelled, dot-separated line and keeps the words as typed", () => {
    expect(phoneSegments("Mobile / WhatsApp: +92 333 766 7076 · +92 334 77 33 328")).toEqual([
      { text: "Mobile / WhatsApp: " },
      { text: "+92 333 766 7076", tel: "tel:+923337667076" },
      { text: " · " },
      { text: "+92 334 77 33 328", tel: "tel:+923347733328" },
    ]);
  });

  it("returns one linked segment for a bare number", () => {
    expect(phoneSegments("+92 334 77 33 328")).toEqual([{ text: "+92 334 77 33 328", tel: "tel:+923347733328" }]);
  });

  it("accepts dashes, dots and parentheses and strips them from the href", () => {
    expect(phoneSegments("(042) 3521-9098")).toEqual([{ text: "(042) 3521-9098", tel: "tel:04235219098" }]);
    expect(phoneSegments("0300.1234567")).toEqual([{ text: "0300.1234567", tel: "tel:03001234567" }]);
  });

  it("leaves short digit runs as text", () => {
    expect(phoneSegments("Monday to Saturday, 9 am – 7 pm")).toEqual([{ text: "Monday to Saturday, 9 am – 7 pm" }]);
    expect(phoneSegments("Shop 4, Block C")).toEqual([{ text: "Shop 4, Block C" }]);
  });

  it("handles an empty or blank field", () => {
    expect(phoneSegments("")).toEqual([]);
    expect(phoneSegments("   ")).toEqual([{ text: "   " }]);
  });

  it("keeps trailing text after the last number", () => {
    expect(phoneSegments("+92 300 0000000 (office)")).toEqual([
      { text: "+92 300 0000000", tel: "tel:+923000000000" },
      { text: " (office)" },
    ]);
  });
});
