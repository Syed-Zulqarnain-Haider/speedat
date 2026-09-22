import { describe, expect, it } from "vitest";
import { addonsList, priceAll } from "@/lib/pricing/engine";
import { fmtMoney } from "@/lib/pricing/format";
import { SEED, gridSeed } from "@/lib/site/seed";
import type { SiteData } from "@/lib/site/types";
import { boardNote, countryList, daySpan, daysText, docPrice, fromPrice, hasDocRate, heroSubAuto, heroTitleAuto } from "./copy";

/**
 * The dev database's shape (brief v3 ground truths): four destinations in admin order, grid pricing, one add-on
 * on by default; 1 kg Normal 4,500 (France, Germany) / 5,220 (Canada) / 5,350 (Australia), Express 6,210–7,460,
 * Express days 3–5 / 4–6, Normal 6–9 / 7–10, document rates 3,080–5,210.
 */
function devLike(): SiteData {
  const s = gridSeed();
  const dest = (id: string, name: string, ex1: number, exDays: string, exDoc: number, no1: number, noDays: string, noDoc: number) => ({
    id,
    name,
    active: true,
    rates: {
      express: { days: exDays, doc: exDoc, grid: { "1": ex1, "2": ex1 + 1150 } },
      normal: { days: noDays, doc: noDoc, grid: { "1": no1, "2": no1 + 880 } },
    },
  });
  s.destinations = [
    dest("au", "Australia", 7460, "4–6", 5210, 5350, "7–10", 3600),
    dest("ca", "Canada", 7200, "4–6", 5000, 5220, "7–10", 3500),
    dest("france", "France", 6210, "3–5", 4100, 4500, "6–9", 3080),
    dest("de", "Germany", 6210, "3–5", 4100, 4500, "6–9", 3080),
  ];
  s.company.originCities = "Lahore";
  return s;
}

const onAddons = (site: SiteData) =>
  addonsList(site.settings)
    .filter((a) => a.on)
    .reduce((t, a) => t + a.amount, 0);

describe("countryList", () => {
  it("joins one to four names in words and folds the rest into a count", () => {
    expect(countryList([])).toBe("");
    expect(countryList(["Canada"])).toBe("Canada");
    expect(countryList(["Canada", "Germany"])).toBe("Canada and Germany");
    expect(countryList(["Australia", "Canada", "France", "Germany"])).toBe("Australia, Canada, France and Germany");
    expect(countryList(["A", "B", "C", "D", "E"])).toBe("A, B, C and 2 more countries");
    expect(countryList(["A", "B", "C", "D", "E", "F", "G", "H", "I", "J"])).toBe("A, B, C and 7 more countries");
  });
});

describe("daysText", () => {
  it("prints a range with an en dash, a single value alone, nothing when there is no number", () => {
    expect(daysText("3–5")).toBe("3–5");
    expect(daysText("3-5")).toBe("3–5");
    expect(daysText("3 to 5 days")).toBe("3–5");
    expect(daysText("4")).toBe("4");
    expect(daysText("")).toBe("");
    expect(daysText(undefined)).toBe("");
  });
});

describe("fromPrice", () => {
  for (const [name, site] of [
    ["SEED (slab)", SEED],
    ["gridSeed (grid)", gridSeed()],
    ["dev-like", devLike()],
  ] as const) {
    it(`is the calculator's cheapest 1 kg total plus the default add-ons on ${name}`, () => {
      for (const d of site.destinations.filter((x) => x.active)) {
        const r = priceAll(site, { destId: d.id, type: "pkg", rows: [{ kg: 1, qty: 1 }] });
        expect(r.ok).toBe(true);
        if (!r.ok) continue;
        const totals = site.services.map((s) => [s.id, r.prices[s.id]?.total] as const).filter((t): t is readonly [string, number] => typeof t[1] === "number");
        const cheapest = totals.reduce((b, t) => (t[1] < b[1] ? t : b));
        const f = fromPrice(site, d.id);
        expect(f).not.toBeNull();
        expect(f?.total).toBe(cheapest[1] + onAddons(site));
        expect(f?.serviceId).toBe(cheapest[0]);
        expect(f?.days).toBe(daysText(d.rates[cheapest[0]]?.days));
      }
    });
  }

  it("matches the brief's dev numbers: 1 kg Normal 4,500 + 500 pickup to France", () => {
    const f = fromPrice(devLike(), "france");
    expect(f).toEqual({ total: 5000, days: "6–9", serviceId: "normal" });
  });

  it("is null for an unknown or inactive destination", () => {
    const site = structuredClone(SEED);
    site.destinations[0].active = false;
    expect(fromPrice(site, site.destinations[0].id)).toBeNull();
    expect(fromPrice(site, "nowhere")).toBeNull();
  });
});

describe("docPrice", () => {
  it("is the flat document rate plus the default add-ons, only where the engine applies it", () => {
    const site = gridSeed();
    const gb = site.destinations[0];
    expect(docPrice(site, gb.id, "express")).toBe((gb.rates.express?.doc ?? 0) + onAddons(site));
    expect(docPrice(site, gb.id, "normal")).toBe((gb.rates.normal?.doc ?? 0) + onAddons(site));
    gb.rates.express = { ...gb.rates.express, doc: null };
    expect(docPrice(site, gb.id, "express")).toBeNull();
    expect(docPrice(site, "nowhere", "express")).toBeNull();
  });
});

describe("daySpan and hasDocRate", () => {
  it("spans the shortest lower bound to the longest upper bound over active destinations", () => {
    expect(daySpan(devLike())).toEqual([3, 10]);
    expect(daySpan(SEED)).toEqual([2, 10]);
    const site = structuredClone(SEED);
    for (const d of site.destinations) for (const r of Object.values(d.rates)) if (r) r.days = "";
    expect(daySpan(site)).toBeNull();
  });

  it("finds a document rate only when one exists and the limit is set", () => {
    expect(hasDocRate(SEED)).toBe(true);
    const site = structuredClone(SEED);
    for (const d of site.destinations) for (const r of Object.values(d.rates)) if (r) r.doc = null;
    expect(hasDocRate(site)).toBe(false);
    const noLimit = structuredClone(SEED);
    noLimit.settings.docMaxKg = 0;
    expect(hasDocRate(noLimit)).toBe(false);
  });
});

describe("heroTitleAuto", () => {
  it("names the first pickup city and the live destinations in admin order", () => {
    expect(heroTitleAuto(devLike())).toBe("Lahore to Australia, Canada, France and Germany.");
    expect(heroTitleAuto(SEED)).toBe("Lahore to United Kingdom, United States, Canada and 7 more countries.");
  });

  it("falls back to the origin without pickup cities and to the company name without destinations", () => {
    const site = structuredClone(SEED);
    site.company.originCities = "";
    expect(heroTitleAuto(site)).toMatch(/^Pakistan to /);
    for (const d of site.destinations) d.active = false;
    expect(heroTitleAuto(site)).toBe(site.company.name);
  });
});

describe("heroSubAuto", () => {
  it("prints the lowest 1 kg price (add-ons included) and the day span when priced", () => {
    expect(heroSubAuto(devLike(), false)).toBe("1 kg from PKR 5,000 · 3 to 10 days");
  });

  it("stays inside the phone budget: one line, ten words at most (brief v3 §7 F6)", () => {
    for (const site of [devLike(), SEED, gridSeed()]) {
      for (const hold of [false, true]) {
        const sub = heroSubAuto(site, hold);
        expect(sub.split(/\s+/).length).toBeLessThanOrEqual(10);
        expect(sub).not.toMatch(/[.!]$/);
      }
    }
  });

  it("names the pickup cities instead of a price on hold", () => {
    expect(heroSubAuto(devLike(), true)).toBe("Pickup in Lahore · 3 to 10 days");
    expect(heroSubAuto(SEED, true)).toBe("Pickup in Lahore and Faisalabad · 2 to 10 days");
  });

  it("prints the bare 1 kg total when no add-on is on, and drops the days when none exist", () => {
    const site = devLike();
    site.settings.addons = "Pickup and service charges | 500 | off";
    expect(heroSubAuto(site, false)).toBe(`1 kg from ${fmtMoney(4500, "PKR")} · 3 to 10 days`);
    for (const d of site.destinations) for (const r of Object.values(d.rates)) if (r) r.days = "";
    expect(heroSubAuto(site, false)).toBe("1 kg from PKR 4,500");
    expect(heroSubAuto(site, true)).toBe("Pickup in Lahore");
  });

  it("prints a single day count in the singular or plural", () => {
    const site = devLike();
    for (const d of site.destinations) for (const r of Object.values(d.rates)) if (r) r.days = "4";
    expect(heroSubAuto(site, false)).toBe("1 kg from PKR 5,000 · 4 days");
    for (const d of site.destinations) for (const r of Object.values(d.rates)) if (r) r.days = "1";
    expect(heroSubAuto(site, false)).toBe("1 kg from PKR 5,000 · 1 day");
  });
});

describe("boardNote", () => {
  // Read through a regular space so the assertions stay legible; the hard spaces are checked separately.
  const plain = (s: string) => s.replace(/ /g, " ");

  it("states the parcel, the document limit and the included pickup, each only when it applies", () => {
    expect(plain(boardNote(devLike()))).toBe("1 kg parcel · documents up to 0.5 kg · pickup PKR 500 included");
    const site = devLike();
    site.settings.addons = "";
    expect(plain(boardNote(site))).toBe("1 kg parcel · documents up to 0.5 kg");
    for (const d of site.destinations) for (const r of Object.values(d.rates)) if (r) r.doc = null;
    expect(plain(boardNote(site))).toBe("1 kg parcel");
  });

  it("keeps every number with its unit or currency on one line (a hard space, never a breaking one)", () => {
    const note = boardNote(devLike());
    expect(note).toContain("1 kg parcel");
    expect(note).toContain("0.5 kg");
    expect(note).toContain("PKR 500");
    expect(note).not.toMatch(/\d kg|PKR \d/);
  });
});
