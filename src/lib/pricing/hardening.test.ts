/**
 * Regression tests for the pricing hardening pass: each block names the bug
 * it pins down, so a later change that reopens one fails here by name.
 */
import { describe, expect, it } from "vitest";
import { billableFor, cargoLimitKg, chargedG, computeWeights, isBillableG, priceAll, priceService, roundTo, slabToGrid } from "./engine";
import { estimateDelivery } from "./dates";
import { QUOTE_ID_RE, quoteId, quoteIdFor } from "./format";
import { weightSentence } from "./quote";
import { businessNow } from "@/lib/time";
import { gridSeed, SEED } from "@/lib/site/seed";
import type { RateCard, Settings } from "./types";

const S: Settings = SEED.settings;
const one = (destId: string, kg: number, type: "pkg" | "doc" = "pkg") => ({ destId, type, rows: [{ kg, qty: 1 }] });
const ok = (r: ReturnType<typeof priceAll>) => {
  if (!r.ok) throw new Error(`expected ok, got ${r.reason}`);
  return r;
};
const withSettings = (card: RateCard, s: Partial<Settings>): RateCard => ({ ...structuredClone(card), settings: { ...card.settings, ...s } });

describe("billed weight is the first slab plus whole steps (slab misalignment)", () => {
  const odd = withSettings(SEED, { firstKg: 0.5, stepKg: 1 });

  it("bills 1.2 kg as 1.5 kg (first + 1 step) and charges exactly that", () => {
    const r = ok(priceAll(odd, one("gb", 1.2)));
    expect(r.weights.billableG).toBe(1500);
    expect(r.prices.express!.steps).toBe(1);
    expect(r.prices.express!.total).toBe(4500 + 1100);
    expect(weightSentence(r.weights, odd.settings)).toBe("Charged on 1.5 kg (1.2 kg, first 0.5 kg, then 1 kg steps).");
  });

  it("keeps every result identical when the first slab is a whole number of steps", () => {
    for (const s of [S, { ...S, firstKg: 1, stepKg: 0.5 }, { ...S, firstKg: 2, stepKg: 1 }]) {
      for (let g = 100; g <= 30000; g += 100) {
        const firstG = s.firstKg * 1000;
        const stepG = s.stepKg * 1000;
        expect(billableFor(s, g)).toBe(Math.max(firstG, Math.ceil(g / stepG) * stepG));
      }
    }
  });

  it("knows which weights are billable", () => {
    expect(isBillableG(S, 500)).toBe(true);
    expect(isBillableG(S, 1500)).toBe(true);
    expect(isBillableG(S, 1200)).toBe(false);
    expect(isBillableG(S, 0)).toBe(false);
    expect(isBillableG(odd.settings, 2500)).toBe(true);
    expect(isBillableG(odd.settings, 2000)).toBe(false);
    expect(isBillableG(gridSeed().settings, 5000)).toBe(true);
    expect(isBillableG(gridSeed().settings, 4500)).toBe(false);
  });
});

describe("the cargo threshold is judged on the chargeable weight", () => {
  const odd = withSettings(SEED, { firstKg: 0.5, stepKg: 1, maxKg: 25 });

  it("prices a 25 kg parcel under a 25 kg limit even though it bills as 25.5 kg", () => {
    const r = ok(priceAll(odd, one("gb", 25)));
    expect(r.weights.billableG).toBe(25500);
    expect(r.prices.express).not.toBeNull();
  });

  it("still sends anything heavier to the cargo message", () => {
    expect(priceAll(odd, one("gb", 25.1)).ok).toBe(false);
  });
});

describe("grid mode without a cargo threshold", () => {
  const g = withSettings(gridSeed(), { maxKg: 0 });
  for (const d of g.destinations) for (const r of Object.values(d.rates)) if (r) r.grid = slabToGrid({ ...g.settings, maxKg: 0 }, { first: r.first, addl: r.addl });

  it("treats the heaviest priced kg as the threshold and sends heavier shipments to the cargo message", () => {
    expect(cargoLimitKg(g)).toBe(30);
    expect(priceAll(g, one("gb", 30)).ok).toBe(true);
    expect(priceAll(g, one("gb", 31))).toMatchObject({ ok: false, reason: "overmax" });
  });

  it("keeps maxKg when it is set and has no limit in slab mode without one", () => {
    expect(cargoLimitKg(gridSeed())).toBe(25);
    expect(cargoLimitKg(withSettings(SEED, { maxKg: 0 }))).toBe(0);
  });
});

describe("a grid gap is charged, shown and stored on the weight whose price was used", () => {
  const g = gridSeed();
  g.destinations[0]!.rates.express!.grid = { "1": 4000, "2": 6000, "5": 9000 };

  it("charges 3 kg at the 5 kg price and says 5 kg", () => {
    const r = ok(priceAll(g, one("gb", 3)));
    const p = r.prices.express!;
    expect([r.weights.billableG, p.gridKg, p.total]).toEqual([3000, 5, 9000]);
    expect(chargedG(p, r.weights)).toBe(5000);
    expect(weightSentence(r.weights, g.settings, chargedG(p, r.weights))).toBe("Charged on 5 kg, the next weight we have a price for (3 kg has none).");
    // The server re-prices the stored weight to the same number.
    expect(priceService(g.settings, g.destinations[0]!, "express", chargedG(p, r.weights), "pkg")!.total).toBe(9000);
    expect(isBillableG(g.settings, chargedG(p, r.weights))).toBe(true);
  });

  it("leaves an exact hit and the document rate alone", () => {
    const r = ok(priceAll(g, one("gb", 2)));
    expect(chargedG(r.prices.express!, r.weights)).toBe(2000);
    const d = ok(priceAll(g, one("gb", 0.3, "doc")));
    expect(chargedG(d.prices.express!, d.weights)).toBe(d.weights.billableG);
  });
});

describe("slabToGrid reproduces the slab totals with tax and rounding", () => {
  const s: Settings = { ...S, firstKg: 1, stepKg: 1, taxPct: 10, roundTo: 100, maxKg: 5 };
  const rate = { first: 4060, addl: 500 };
  const slab = withSettings(SEED, s);
  slab.destinations[0]!.rates.express = { ...rate };
  const grid = withSettings(slab, { pricingMode: "grid" });
  grid.destinations[0]!.rates.express = { grid: slabToGrid(s, rate) };

  it("gives the same total at every kg (was 5,000 vs 5,100 at 2 kg)", () => {
    for (const kg of [1, 2, 3, 4, 5]) {
      expect(ok(priceAll(grid, one("gb", kg))).prices.express!.total).toBe(ok(priceAll(slab, one("gb", kg))).prices.express!.total);
    }
    expect(ok(priceAll(grid, one("gb", 2))).prices.express!.total).toBe(5000);
  });

  it("still rounds the boxes when there is no tax", () => {
    expect(slabToGrid({ ...s, taxPct: 0, roundTo: 100 }, rate)!["1"]).toBe(4100);
  });
});

describe("a document limit of 0 means no document rate", () => {
  for (const [name, base] of [["slab", SEED], ["grid", gridSeed()]] as const) {
    it(`charges a 0.3 kg document like a package on ${name}`, () => {
      const c = withSettings(base, { docMaxKg: 0 });
      const d = ok(priceAll(c, one("gb", 0.3, "doc")));
      const p = ok(priceAll(c, one("gb", 0.3)));
      expect(d.prices.express!.docRate).toBe(false);
      expect(d.prices.express!.total).toBe(p.prices.express!.total);
    });
  }
});

describe("roundTo and the weight sentence edge cases", () => {
  it("treats a zero, negative or junk step as no rounding", () => {
    expect(roundTo(105, -10)).toBe(105);
    expect(roundTo(105, 0)).toBe(105);
    expect(roundTo(105, Number.NaN)).toBe(105);
    expect(roundTo(105, 10)).toBe(110);
  });

  it("does not call a mixed chargeable weight volumetric", () => {
    const w = computeWeights(S, [
      { kg: 5, qty: 1 },
      { kg: 1, qty: 1, L: 50, W: 40, H: 30 },
    ]);
    expect([w.actualG, w.volG, w.chargeG]).toEqual([6000, 12000, 17000]);
    expect(weightSentence(w, S)).toBe("Charged on 17 kg for 2 pieces — chargeable weight 17 kg counts the volumetric weight of the large pieces (actual 6 kg).");
    const all = computeWeights(S, [{ kg: 1, qty: 1, L: 50, W: 40, H: 30 }]);
    expect(weightSentence(all, S)).toBe("Charged on 12 kg — volumetric weight (12 kg) is higher than actual (1 kg).");
  });
});

describe("quote ids", () => {
  it("are dated, eight characters long, and accepted with the old four-character tail", () => {
    const id = quoteId(new Date(2026, 8, 20));
    expect(id).toMatch(/^SP-260920-[A-Z0-9]{8}$/);
    expect(QUOTE_ID_RE.test(id)).toBe(true);
    expect(QUOTE_ID_RE.test("SP-260920-K7QX")).toBe(true);
    expect(QUOTE_ID_RE.test("SP-260920-K7QX2")).toBe(false);
    expect(quoteId(new Date(2026, 8, 20), () => 0)).toBe("SP-260920-00000000");
  });

  it("derive a stable eight-character tail from the seed, with no collisions over 50,000 seeds", () => {
    const at = new Date(2026, 8, 20);
    expect(quoteIdFor("salt|gb|5|express", at)).toBe(quoteIdFor("salt|gb|5|express", at));
    expect(quoteIdFor("salt|gb|5|express", at)).toMatch(QUOTE_ID_RE);
    const seen = new Set<string>();
    for (let i = 0; i < 50_000; i++) seen.add(quoteIdFor(`tab${i}|gb|5|express`, at));
    expect(seen.size).toBe(50_000);
  });
});

describe("delivery dates run on the office's clock", () => {
  it("applies the 3 pm cutoff in Karachi to a visitor anywhere (15:30 PKT is 10:30 UTC)", () => {
    const at = new Date("2026-10-23T10:30:00Z"); // Friday 15:30 in Asia/Karachi
    const now = businessNow(at, "Asia/Karachi");
    expect([now.getDate(), now.getHours(), now.getMinutes()]).toEqual([23, 15, 30]);
    const est = estimateDelivery("3", { workingDays: "Mon-Fri", cutoffHour: 15 }, null, now)!;
    expect(est.moved).toBe(true);
    expect(est.pickup.getDate()).toBe(26); // Monday
  });

  it("defaults to the business clock when no `now` is given", () => {
    const est = estimateDelivery("3", { workingDays: "Mon-Sun", cutoffHour: null }, null)!;
    const b = businessNow();
    expect(est.pickup.getDate()).toBe(b.getDate());
  });
});
