import { describe, expect, it } from "vitest";
import { computeWeights, priceAll, priceService } from "./engine";
import { bookedTotal, leadSummary, midSentence, quoteText, resolveAddons, weightSentence, type Quote } from "./quote";
import type { RateCard, Settings } from "./types";
import { SEED, gridSeed } from "@/lib/site/seed";

/** Canada · 5 kg · Express, priced from the seed rates the way the site prices it. */
function canadaExpress5kg(): Quote {
  const res = priceAll(SEED, { destId: "ca", rows: [{ kg: 5, qty: 1 }] });
  if (!res.ok) throw new Error(`expected ok, got ${res.reason}`);
  const p = res.prices.express;
  if (!p) throw new Error("express not priced");
  return {
    id: "SP-260922-TEST",
    from: "",
    dest: "Canada",
    destId: "ca",
    type: "pkg",
    service: "Express",
    serviceId: "express",
    days: p.days,
    eta: "",
    pickup: "",
    pieces: 1,
    piecesText: "1 × 5 kg",
    billableG: res.weights.billableG,
    chargeG: res.weights.chargeG,
    total: p.total,
    docRate: false,
    version: 1,
  };
}

describe("add-ons on a booked quote", () => {
  it("resolves the labels a booking names against the settings, never trusting the browser for amounts", () => {
    const settings = { addons: "Pickup and service charges | 500 | on\nInsurance | 250 | off" };
    expect(resolveAddons(settings, ["Insurance", "Pickup and service charges", "Gift wrap"])).toEqual([
      { id: "a0", label: "Pickup and service charges", amount: 500, on: true },
      { id: "a1", label: "Insurance", amount: 250, on: false },
    ]);
    expect(resolveAddons(settings, [])).toEqual([]);
    expect(resolveAddons(settings, undefined)).toEqual([]);
    expect(resolveAddons({ addons: "" }, ["Insurance"])).toEqual([]);
  });

  it("books shipping plus every add-on that was on", () => {
    expect(bookedTotal(19_120, [])).toBe(19_120);
    expect(bookedTotal(19_120, [{ amount: 500 }, { amount: 250 }])).toBe(19_870);
  });

  it("reads an add-on label on inside a sentence without touching an acronym", () => {
    expect(midSentence("Pickup and service charges")).toBe("pickup and service charges");
    expect(midSentence("COD fee")).toBe("COD fee");
  });
});

describe("the customer's WhatsApp Total and the owner's inbox line agree", () => {
  const q = canadaExpress5kg();
  const addons = resolveAddons(SEED.settings, ["Pickup and service charges"]);
  const currency = SEED.settings.currency;

  it("carries the same booked total in the message, the inbox summary and the stored number", () => {
    const text = quoteText(q, { companyName: "Speedat", currency, addons });
    const summary = leadSummary({ service: "Express", dest: "Canada", billableG: q.billableG, shipping: q.total, addons, currency, piecesText: q.piecesText });
    const total = bookedTotal(q.total, addons);
    expect(total).toBe(q.total + 500);
    expect(text).toContain(`Shipping: ${currency} ${q.total.toLocaleString("en-US")}`);
    expect(text).toContain(`Pickup and service charges: ${currency} 500`);
    expect(text).toMatch(new RegExp(`^Total: ${currency} ${total.toLocaleString("en-US")}$`, "m"));
    expect(summary).toBe(`Express to Canada · 5 kg · ${currency} ${total.toLocaleString("en-US")} (includes ${currency} 500 pickup and service charges) · 1 × 5 kg`);
    // The shipping-only number must not be the one the owner reads as the price.
    expect(summary).not.toContain(`· ${currency} ${q.total.toLocaleString("en-US")} ·`);
  });

  it("prints a plain price when no add-on was on, in both places", () => {
    const text = quoteText(q, { companyName: "Speedat", currency, addons: [] });
    expect(text).toMatch(new RegExp(`^Price: ${currency} ${q.total.toLocaleString("en-US")}$`, "m"));
    expect(text).not.toContain("Total:");
    expect(leadSummary({ service: "Express", dest: "Canada", billableG: q.billableG, shipping: q.total, addons: [], currency })).toBe(
      `Express to Canada · 5 kg · ${currency} ${q.total.toLocaleString("en-US")}`,
    );
  });
});

describe("a booked document quote is re-priced on the server from the weights the browser priced on", () => {
  /**
   * What POST /api/quotes does with a quote body: the engine again, from the billed and the chargeable weight
   * the browser sent. The customer's number and the stored one must be the same number.
   */
  const reprice = (card: RateCard, destId: string, kg: number) => {
    const shown = priceAll(card, { destId, type: "doc", rows: [{ kg, qty: 1 }] });
    if (!shown.ok) throw new Error(`expected ok, got ${shown.reason}`);
    const dest = card.destinations.find((d) => d.id === destId)!;
    const stored = priceService(card.settings, dest, "express", shown.weights.billableG, "doc", shown.weights.chargeG);
    if (!stored) throw new Error("express not priced on the server");
    return { shown: shown.prices.express!, weights: shown.weights, stored };
  };

  it("keeps the document rate when whole kilograms are priced and a 0.3 kg letter is billed as 1 kg", () => {
    const site = gridSeed();
    const { shown, weights, stored } = reprice(site, "gb", 0.3);
    expect([weights.chargeG, weights.billableG]).toEqual([300, 1000]);
    expect(shown.docRate).toBe(true);
    expect(stored.docRate).toBe(true);
    expect(stored.total).toBe(shown.total);
    const addons = resolveAddons(site.settings, ["Pickup and service charges"]);
    expect(bookedTotal(stored.total, addons)).toBe(bookedTotal(shown.total, addons));
    // The billed kilogram alone would have made it a 1 kg parcel — the trap this test guards.
    expect(priceService(site.settings, site.destinations.find((d) => d.id === "gb")!, "express", weights.billableG, "doc")?.docRate).toBe(false);
  });

  it("keeps the document rate when the first slab is heavier than the document limit", () => {
    const card: RateCard = { ...SEED, settings: { ...SEED.settings, firstKg: 1, stepKg: 1 } };
    const { shown, weights, stored } = reprice(card, "gb", 0.3);
    expect([weights.chargeG, weights.billableG]).toEqual([300, 1000]);
    expect(shown.docRate).toBe(true);
    expect(stored.docRate).toBe(true);
    expect(stored.total).toBe(shown.total);
  });

  it("still charges a document over the limit as a parcel, on both sides", () => {
    const { shown, stored } = reprice(gridSeed(), "gb", 0.6);
    expect(shown.docRate).toBe(false);
    expect(stored.docRate).toBe(false);
    expect(stored.total).toBe(shown.total);
  });
});

describe("the status line names the rounding the engine actually did", () => {
  const sentence = (settings: Settings, kg: number) => weightSentence(computeWeights(settings, [{ kg, qty: 1 }]), settings);

  it("says whole kilograms in grid mode, whatever the slab step is set to", () => {
    const s = gridSeed().settings;
    expect(s.stepKg).toBe(0.5);
    // A 0.5 kg envelope billed as 1 kg was never "rounded up to the next 0.5 kg".
    expect(sentence(s, 0.5)).toBe("Charged on 1 kg (0.5 kg, rounded up to the next 1 kg).");
    expect(sentence(s, 1.2)).toBe("Charged on 2 kg (1.2 kg, rounded up to the next 1 kg).");
    expect(sentence(s, 2)).toBe("Charged on 2 kg.");
  });

  it("keeps the slab step in slab mode", () => {
    const s = SEED.settings;
    expect(s.pricingMode).toBe("slab");
    expect(sentence(s, 0.3)).toBe("Charged on 0.5 kg (0.3 kg, rounded up to the next 0.5 kg).");
    expect(sentence(s, 1.2)).toBe("Charged on 1.5 kg (1.2 kg, rounded up to the next 0.5 kg).");
  });

  it("names the first slab when that, not the step, decided the billed weight", () => {
    const s: Settings = { ...SEED.settings, firstKg: 1, stepKg: 0.5 };
    expect(sentence(s, 0.3)).toBe("Charged on 1 kg (0.3 kg, minimum 1 kg).");
    expect(sentence(s, 0.7)).toBe("Charged on 1 kg (0.7 kg, rounded up to the next 0.5 kg).");
    expect(sentence(s, 1.2)).toBe("Charged on 1.5 kg (1.2 kg, rounded up to the next 0.5 kg).");
  });

  it("still reports volumetric weight winning and several pieces", () => {
    const s = gridSeed().settings;
    const w = computeWeights(s, [{ kg: 1, qty: 2, L: 50, W: 40, H: 30 }]);
    expect(w.volumetricWins).toBe(true);
    expect(weightSentence(w, s)).toBe("Charged on 24 kg for 2 pieces — volumetric weight (24 kg) is higher than actual (2 kg).");
  });
});
