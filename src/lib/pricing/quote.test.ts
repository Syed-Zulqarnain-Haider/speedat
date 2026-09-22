import { describe, expect, it } from "vitest";
import { priceAll } from "./engine";
import { bookedTotal, leadSummary, midSentence, quoteText, resolveAddons, type Quote } from "./quote";
import { SEED } from "@/lib/site/seed";

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
