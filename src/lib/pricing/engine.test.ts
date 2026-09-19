import { describe, expect, it } from "vitest";
import { addonsList, computeWeights, priceAll, roundTo, toNumLoose } from "./engine";
import { estimateDelivery, parseDays, parseDaysRange, workingSet } from "./dates";
import { fmtHour, fmtMoney, fmtPhone, fmtRange, quoteId, slug } from "./format";
import { SEED } from "@/lib/site/seed";
import type { RateCard } from "./types";

const card: RateCard = SEED;

function total(destId: string, service: string, kg: number, extra: Partial<Parameters<typeof priceAll>[1]> = {}) {
  const res = priceAll(card, { destId, rows: [{ kg, qty: 1 }], ...extra });
  if (!res.ok) throw new Error(`expected ok, got ${res.reason}`);
  const p = res.prices[service];
  if (!p) throw new Error("service not priced");
  return p;
}

describe("computeWeights", () => {
  it("bills at least the first slab and rounds up to the step", () => {
    const w = computeWeights(card.settings, [{ kg: 0.2 }]);
    expect(w.billableG).toBe(500);
    expect(computeWeights(card.settings, [{ kg: 2.3 }]).billableG).toBe(2500);
    expect(computeWeights(card.settings, [{ kg: 2.5 }]).billableG).toBe(2500);
    expect(computeWeights(card.settings, [{ kg: 2.51 }]).billableG).toBe(3000);
  });

  it("uses volumetric weight when it is higher", () => {
    const w = computeWeights(card.settings, [{ kg: 1, L: 50, W: 40, H: 30 }]);
    expect(w.volG).toBe(12000); // 60000 / 5000
    expect(w.chargeG).toBe(12000);
    expect(w.volumetricWins).toBe(true);
    expect(w.lines[0]?.volumetricWins).toBe(true);
  });

  it("ignores dimensions unless all three are given", () => {
    const w = computeWeights(card.settings, [{ kg: 1, L: 50, W: 40 }]);
    expect(w.volG).toBe(0);
    expect(w.chargeG).toBe(1000);
  });

  it("multiplies by quantity and sums pieces", () => {
    const w = computeWeights(card.settings, [
      { kg: 1, qty: 2 },
      { kg: 0.5, qty: 1 },
    ]);
    expect(w.pieces).toBe(3);
    expect(w.actualG).toBe(2500);
    expect(w.billableG).toBe(2500);
  });

  it("skips rows without a positive weight", () => {
    const w = computeWeights(card.settings, [{ kg: 0 }, { kg: null }, { kg: 1 }]);
    expect(w.pieces).toBe(1);
  });
});

describe("priceAll with the seed rates", () => {
  it("prices the first slab", () => {
    expect(total("gb", "express", 0.5).total).toBe(4500);
    expect(total("gb", "normal", 0.5).total).toBe(3200);
  });

  it("adds one step per additional 0.5 kg", () => {
    const p = total("gb", "express", 1);
    expect(p.steps).toBe(1);
    expect(p.total).toBe(5600);
    expect(total("gb", "express", 2.3).total).toBe(8900); // 4500 + 4 × 1100
  });

  it("applies the document rate only up to docMaxKg", () => {
    const doc = total("gb", "express", 0.4, { type: "doc" });
    expect(doc.docRate).toBe(true);
    expect(doc.total).toBe(3900);
    const heavy = total("gb", "express", 0.6, { type: "doc" });
    expect(heavy.docRate).toBe(false);
    expect(heavy.total).toBe(5600);
  });

  it("applies tax then rounds to the nearest roundTo", () => {
    const taxed: RateCard = { ...card, settings: { ...card.settings, taxPct: 3 } };
    const res = priceAll(taxed, { destId: "gb", rows: [{ kg: 2 }] });
    if (!res.ok) throw new Error(res.reason);
    const p = res.prices["express"]!;
    expect(p.base).toBe(7800);
    expect(p.tax).toBeCloseTo(234);
    expect(p.total).toBe(8030); // 8034 → nearest 10
  });

  it("refuses unknown, inactive or weightless input", () => {
    expect(priceAll(card, { destId: "xx", rows: [{ kg: 1 }] })).toEqual({ ok: false, reason: "destination" });
    const hidden: RateCard = { ...card, destinations: card.destinations.map((x) => ({ ...x, active: false })) };
    expect(priceAll(hidden, { destId: "gb", rows: [{ kg: 1 }] })).toEqual({ ok: false, reason: "destination" });
    expect(priceAll(card, { destId: "gb", rows: [{ kg: 0 }] })).toEqual({ ok: false, reason: "weight" });
  });

  it("flags shipments above the cargo threshold", () => {
    const res = priceAll(card, { destId: "gb", rows: [{ kg: 70.5 }] });
    expect(res.ok).toBe(false);
    if (res.ok || res.reason !== "overmax") throw new Error("expected overmax");
    expect(res.weights.billableG).toBe(70500);
  });

  it("returns null for a service the destination does not offer", () => {
    const partial: RateCard = {
      ...card,
      destinations: [{ id: "x", name: "X", active: true, rates: { express: { first: 1000, addl: 100 } } }],
    };
    const res = priceAll(partial, { destId: "x", rows: [{ kg: 1 }] });
    if (!res.ok) throw new Error(res.reason);
    expect(res.prices["express"]?.total).toBe(1100);
    expect(res.prices["normal"]).toBeNull();
  });
});

describe("roundTo / toNumLoose / addons", () => {
  it("rounds to the nearest multiple, treating 0 as 1", () => {
    expect(roundTo(8034, 10)).toBe(8030);
    expect(roundTo(8035, 10)).toBe(8040);
    expect(roundTo(8034, 0)).toBe(8034);
  });

  it("parses spreadsheet cells leniently", () => {
    expect(toNumLoose("Rs 4,500/-")).toBe(4500);
    expect(toNumLoose(" 12.5 ")).toBe(12.5);
    expect(toNumLoose("-")).toBeNull();
    expect(toNumLoose("N/A")).toBeNull();
    expect(toNumLoose("")).toBeNull();
    expect(toNumLoose(null)).toBeNull();
    expect(toNumLoose("abc")).toBeNaN();
    expect(toNumLoose(7)).toBe(7);
  });

  it("parses optional charges and drops malformed lines", () => {
    expect(addonsList({ addons: "Pickup and service charges | 500 | on\nBroken line\nInsurance | 250 | off" })).toEqual([
      { id: "a0", label: "Pickup and service charges", amount: 500, on: true },
      { id: "a2", label: "Insurance", amount: 250, on: false },
    ]);
  });
});

describe("transit days and delivery estimates", () => {
  it("parses ranges in any spelling", () => {
    expect(parseDaysRange("3–5")).toEqual([3, 5]);
    expect(parseDaysRange("5-3")).toEqual([3, 5]);
    expect(parseDaysRange("4")).toEqual([4, 4]);
    expect(parseDaysRange("")).toBeNull();
    expect(parseDays("3 to 5 working days")).toBe("3–5");
    expect(parseDays(" 4 days ")).toBe("4");
  });

  it("reads working days, ranges and the Mon–Fri default", () => {
    expect([...workingSet({ workingDays: "Mon, Tue, Wed, Thu, Fri, Sat" })].sort()).toEqual([1, 2, 3, 4, 5, 6]);
    expect([...workingSet({ workingDays: "Monday to Friday" })].sort()).toEqual([1, 2, 3, 4, 5]);
    expect([...workingSet({ workingDays: "Sat-Mon" })].sort()).toEqual([0, 1, 6]);
    expect([...workingSet({ workingDays: "" })].sort()).toEqual([1, 2, 3, 4, 5]);
  });

  it("counts working days from pickup and honours the cutoff", () => {
    const sets = { workingDays: "Mon, Tue, Wed, Thu, Fri, Sat", cutoffHour: 15 };
    const satMorning = new Date(2026, 8, 19, 10, 0); // Saturday
    const est = estimateDelivery("3–5", sets, null, satMorning)!;
    expect(est.moved).toBe(false);
    expect(est.pickup.getDate()).toBe(19);
    expect(est.from.getDate()).toBe(23); // Mon 21, Tue 22, Wed 23
    expect(est.to.getDate()).toBe(25);
    expect(fmtRange(est)).toMatch(/^Wed 23 Sept? – Fri 25 Sept?$/); // ICU spells en-GB September "Sept"

    const satEvening = new Date(2026, 8, 19, 16, 0);
    const late = estimateDelivery("3–5", sets, null, satEvening)!;
    expect(late.moved).toBe(true);
    expect(late.pickup.getDate()).toBe(21); // Sunday skipped
  });

  it("never schedules pickup in the past", () => {
    const now = new Date(2026, 8, 21, 9, 0);
    const est = estimateDelivery("2", { workingDays: "Mon-Fri", cutoffHour: null }, new Date(2026, 8, 1), now)!;
    expect(est.pickup.getDate()).toBe(21);
  });
});

describe("formatting", () => {
  it("formats money, phones and hours", () => {
    expect(fmtMoney(5600, "PKR")).toBe("PKR 5,600");
    expect(fmtPhone("923157667076")).toBe("+92 315 766 7076");
    expect(fmtPhone("4412345")).toBe("+4412345");
    expect(fmtHour(15)).toBe("3 pm");
    expect(fmtHour(0)).toBe("12 am");
    expect(fmtHour(null)).toBe("");
  });

  it("makes date-stamped quote ids and safe slugs", () => {
    expect(quoteId(new Date(2026, 8, 20), () => 0.123456)).toMatch(/^SP-260920-[A-Z0-9]{4}$/);
    expect(slug("Türkiye")).toBe("turkiye");
    expect(slug("United Arab Emirates")).toBe("united-arab-emirates");
    expect(slug("!!!")).toBe("dest");
  });
});

describe("holidays", () => {
  it("skips holidays for pickup and transit counting", () => {
    const sets = { workingDays: "Mon, Tue, Wed, Thu, Fri, Sat", cutoffHour: 15, holidays: "2026-09-21 | Test holiday\n2026-09-23\nnot a date" };
    // Sat 19 Sep 10:00; Mon 21 is a holiday, Wed 23 is a holiday.
    const est = estimateDelivery("3", sets, null, new Date(2026, 8, 19, 10, 0))!;
    expect(est.pickup.getDate()).toBe(19);
    expect(est.from.getDate()).toBe(25); // Tue 22, Thu 24, Fri 25
    const onHoliday = estimateDelivery("1", sets, new Date(2026, 8, 21), new Date(2026, 8, 19, 10, 0))!;
    expect(onHoliday.pickup.getDate()).toBe(22);
    expect(onHoliday.moved).toBe(true);
  });
});
