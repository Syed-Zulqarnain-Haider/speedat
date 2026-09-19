/**
 * Golden numbers from the prototype's own `build.js` checks (handover
 * bundle). If any of these move, the port has diverged from the spec.
 */
import { describe, expect, it } from "vitest";
import { computeWeights, priceAll, roundTo, toNumLoose } from "./engine";
import { estimateDelivery, parseDays, parseDaysRange, workingSet } from "./dates";
import { fmtRange } from "./format";
import { parseDelimited } from "@/lib/import/parse";
import { diffSite, validateSite, warnSite } from "@/lib/site/diff";
import { SEED } from "@/lib/site/seed";
import type { RateCard } from "./types";

const S = SEED.settings;
const one = (kg: number, L?: number | null, W?: number | null, H?: number | null, qty?: number) => [{ kg, L, W, H, qty }];
const clone = (): RateCard & typeof SEED => structuredClone(SEED);
const price = (card: RateCard, input: Parameters<typeof priceAll>[1]) => {
  const r = priceAll(card, input);
  if (!r.ok) throw new Error(`expected ok, got ${r.reason}`);
  return r.prices;
};

describe("golden: weights", () => {
  it("matches build.js", () => {
    expect(computeWeights(S, one(0.3)).billableG).toBe(500);
    expect(computeWeights(S, one(0.5)).billableG).toBe(500);
    expect(computeWeights(S, one(0.51)).billableG).toBe(1000);
    expect(computeWeights(S, one(2.5)).billableG).toBe(2500);
    expect(computeWeights(S, one(2.5, 50, 40, 30)).volG).toBe(12000);
    expect(computeWeights(S, one(2.5, 50, 40, 30)).billableG).toBe(12000);
    expect(computeWeights(S, one(2.5, 50, 40, 0)).volG).toBe(0);
    expect(computeWeights(S, one(2.5, null, null, null, 2)).chargeG).toBe(5000);
    expect(computeWeights(S, one(1, 30, 20, 10, 3)).chargeG).toBe(3600);
    expect(computeWeights(S, one(1, 30, 20, 10, 3)).billableG).toBe(4000);
    expect(computeWeights(S, one(1, null, null, null, 0)).pieces).toBe(1);
    const mixed = computeWeights(S, [
      { kg: 2, qty: 2 },
      { kg: 1, qty: 1, L: 50, W: 40, H: 30 },
    ]);
    expect([mixed.pieces, mixed.actualG, mixed.chargeG, mixed.billableG, mixed.volumetricWins]).toEqual([3, 5000, 16000, 16000, true]);
    expect(computeWeights(S, [{ kg: 0 }, { kg: 1 }]).pieces).toBe(1);
  });
});

describe("golden: prices", () => {
  it("matches build.js", () => {
    expect(price(SEED, { destId: "gb", rows: one(0.5) }).express!.total).toBe(4500);
    const p25 = price(SEED, { destId: "gb", rows: one(2.5) });
    expect(p25.express!.total).toBe(4500 + 4 * 1100);
    expect(p25.express!.steps).toBe(4);
    expect(p25.normal!.total).toBe(3200 + 4 * 850);
    expect(price(SEED, { destId: "gb", rows: one(2.6) }).express!.total).toBe(4500 + 5 * 1100); // 10,000
    expect(price(SEED, { destId: "gb", rows: one(2.5, null, null, null, 2) }).express!.total).toBe(4500 + 9 * 1100);
    expect(price(SEED, { destId: "gb", rows: [{ kg: 2, qty: 2 }, { kg: 1, qty: 1, L: 50, W: 40, H: 30 }] }).express!.total).toBe(4500 + 31 * 1100);
    expect(priceAll(SEED, { destId: "gb", rows: [{ kg: null }] })).toEqual({ ok: false, reason: "weight" });
    const taxed = clone();
    taxed.settings.taxPct = 16;
    const t = price(taxed, { destId: "gb", rows: one(1) }).express!.total;
    expect(t).toBe(roundTo((4500 + 1100) * 1.16, 10));
    expect(t).toBe(6500);
    expect(priceAll(SEED, { destId: "zz", rows: one(1) }).ok).toBe(false);
    expect(priceAll(SEED, { destId: "gb", rows: one(0) }).ok).toBe(false);
    expect(priceAll(SEED, { destId: "gb", rows: one(71) })).toMatchObject({ ok: false, reason: "overmax" });
    expect(priceAll(SEED, { destId: "gb", rows: one(36, null, null, null, 2) })).toMatchObject({ ok: false, reason: "overmax" });
    const noMax = clone();
    noMax.settings.maxKg = 0;
    expect(priceAll(noMax, { destId: "gb", rows: one(500) }).ok).toBe(true);
    const noNormal = clone();
    delete noNormal.destinations[0]!.rates.normal;
    expect(price(noNormal, { destId: "gb", rows: one(1) }).normal).toBeNull();
  });

  it("documents", () => {
    let q = price(SEED, { destId: "gb", type: "doc", rows: one(0.3) });
    expect([q.express!.total, q.express!.docRate, q.normal!.total]).toEqual([3900, true, 2800]);
    q = price(SEED, { destId: "gb", type: "doc", rows: one(0.8) });
    expect([q.express!.total, q.express!.docRate]).toEqual([4500 + 1100, false]);
    q = price(SEED, { destId: "gb", type: "pkg", rows: one(0.3) });
    expect([q.express!.total, q.express!.docRate]).toEqual([4500, false]);
    const noDoc = clone();
    delete noDoc.destinations[0]!.rates.express!.doc;
    expect(price(noDoc, { destId: "gb", type: "doc", rows: one(0.3) }).express!.total).toBe(4500);
  });
});

describe("golden: parsing", () => {
  it("matches build.js", () => {
    expect(toNumLoose("PKR 4,500")).toBe(4500);
    expect(toNumLoose("4,500.50")).toBe(4500.5);
    expect(toNumLoose(" - ")).toBeNull();
    expect(toNumLoose("n/a")).toBeNull();
    expect(toNumLoose("abc")).toBeNaN();
    expect(toNumLoose(1234)).toBe(1234);
    expect(parseDays("3 to 5 days")).toBe("3–5");
    expect(parseDays("3-5 working days")).toBe("3–5");
    expect(parseDays("4")).toBe("4");
    expect(parseDelimited('a,b\n"Korea, Republic of",2\n')).toEqual([
      ["a", "b"],
      ["Korea, Republic of", "2"],
    ]);
    expect(parseDelimited("a\tb\r\nc\td")).toEqual([
      ["a", "b"],
      ["c", "d"],
    ]);
    expect(parseDelimited("a;b\nc;d")).toEqual([
      ["a", "b"],
      ["c", "d"],
    ]);
  });
});

describe("golden: delivery estimates", () => {
  const ws = (s: string) => [...workingSet({ workingDays: s })].sort().join("");
  it("matches build.js", () => {
    expect(parseDaysRange("3–5")).toEqual([3, 5]);
    expect(parseDaysRange("3-5")).toEqual([3, 5]);
    expect(parseDaysRange("4")).toEqual([4, 4]);
    expect(parseDaysRange("")).toBeNull();
    expect(ws("Mon, Tue, Wed, Thu, Fri, Sat")).toBe("123456");
    expect(ws("")).toBe("12345");
    expect(ws("Mon–Sat")).toBe("123456");
    expect(ws("Monday to Friday, Sunday")).toBe("012345");
    const WS = { workingDays: "Mon, Tue, Wed, Thu, Fri, Sat", cutoffHour: 15 };
    let est = estimateDelivery("3–5", WS, null, new Date(2026, 8, 19, 10, 0))!;
    expect([est.pickup.getDate(), est.from.getDate(), est.to.getDate(), est.moved]).toEqual([19, 23, 25, false]);
    est = estimateDelivery("3–5", WS, null, new Date(2026, 8, 19, 16, 0))!;
    expect([est.pickup.getDate(), est.from.getDate(), est.moved]).toEqual([21, 24, true]);
    est = estimateDelivery("2", { workingDays: "Mon-Fri", cutoffHour: null }, new Date(2026, 8, 20), new Date(2026, 8, 19, 10, 0))!;
    expect([est.pickup.getDate(), est.from.getDate(), est.to.getDate(), est.moved]).toEqual([21, 23, 23, true]);
    est = estimateDelivery("1", WS, new Date(2026, 8, 10), new Date(2026, 8, 19, 10, 0))!;
    expect(est.pickup.getDate()).toBe(19);
    expect(estimateDelivery("soon", WS, null, new Date())).toBeNull();
    expect(fmtRange({ from: new Date(2026, 8, 23), to: new Date(2026, 8, 25), pickup: new Date(), moved: false, range: [3, 5] })).toBe("Wed 23 Sept – Fri 25 Sept");
  });
});

describe("golden: validation, warnings, diff", () => {
  it("matches build.js", () => {
    const docOnly = clone();
    docOnly.destinations[0]!.rates.express = { doc: 3000 };
    expect(validateSite(docOnly)).toHaveLength(1);
    const badDoc = clone();
    badDoc.destinations[0]!.rates.express!.doc = 9000;
    expect(warnSite(badDoc)).toHaveLength(1);
    const badSet = clone();
    badSet.settings.cutoffHour = 25;
    badSet.settings.workingDays = "xyz";
    expect(validateSite(badSet)).toHaveLength(2);
    expect(validateSite(SEED)).toHaveLength(0);
    const bad = clone();
    bad.destinations[1]!.name = "united kingdom";
    bad.company.whatsapp = "12";
    expect(validateSite(bad)).toHaveLength(2);
    const changed = clone();
    changed.destinations[0]!.rates.express!.first = 6000;
    expect(diffSite(SEED, changed).count).toBe(1);
    expect(warnSite(SEED)).toHaveLength(0);
    const sus = clone();
    sus.destinations[0]!.rates.express!.first = 3000;
    sus.destinations[1]!.rates.normal!.addl = 9999;
    expect(warnSite(sus)).toHaveLength(3);
  });
});
