import { describe, expect, it } from "vitest";
import { diffSite, summarise, validateSite, warnSite } from "./diff";
import { migrate } from "./migrate";
import { SEED } from "./seed";
import type { SiteData } from "./types";

const clone = (): SiteData => structuredClone(SEED);

describe("diffSite", () => {
  it("reports nothing for identical documents", () => {
    const d = diffSite(SEED, clone());
    expect(d.count).toBe(0);
    expect(d.lines).toEqual([]);
  });

  it("describes rate changes and flags big moves", () => {
    const b = clone();
    b.destinations[0]!.rates.express!.first = 4600; // +2%
    b.destinations[1]!.rates.normal!.addl = 1900; // +100%
    const d = diffSite(SEED, b);
    expect(d.count).toBe(2);
    expect(d.byDest).toEqual({ gb: true, us: true });
    expect(d.lines[0]).toMatchObject({ kind: "rate", label: "United Kingdom · Express first slab", old: "PKR 4,500", new: "PKR 4,600" });
    expect(d.lines[0]!.flag).toBeUndefined();
    expect(d.lines[1]!.flag).toBe("+100% — please double-check");
  });

  it("sees added, removed, renamed and hidden destinations", () => {
    const b = clone();
    b.destinations = b.destinations.filter((x) => x.id !== "my");
    b.destinations[0]!.name = "UK";
    b.destinations[1]!.active = false;
    b.destinations.push({ id: "fr", name: "France", active: true, rates: { express: { first: 1, addl: 1 } } });
    const d = diffSite(SEED, b);
    expect(d.lines.map((l) => l.kind).sort()).toEqual(["added", "removed", "renamed", "visibility"]);
    expect(summarise(d)).toBe("4 changes: 4 destinations");
  });

  it("sees settings, text, live flag and import layouts", () => {
    const b = clone();
    b.settings.taxPct = 5;
    b.content.heroTitle = "New title";
    b.live = true;
    b.importProfiles.push({ signature: "a|b", name: "rates.xlsx", map: {}, savedAt: "2026-01-01", cost: false, margin: 0, mround: 1 });
    const d = diffSite(SEED, b);
    expect(d.lines.map((l) => l.label)).toEqual(["taxPct", "Website text · heroTitle", "Rates are live", "Import layout remembered"]);
  });
});

describe("validateSite", () => {
  it("accepts the seed", () => {
    expect(validateSite(SEED)).toEqual([]);
  });

  it("rejects broken settings and rates", () => {
    const b = clone();
    b.company.whatsapp = "123";
    b.settings.volumetricDivisor = 10;
    b.settings.addons = "Bad line";
    b.settings.cutoffHour = 25;
    b.destinations[0]!.rates.express!.first = 0;
    b.destinations[1]!.rates = {};
    b.destinations[2]!.name = "United Kingdom";
    const errs = validateSite(b);
    expect(errs).toContain("WhatsApp number must be 8–15 digits with the country code, e.g. 923001234567.");
    expect(errs).toContain("Volumetric divisor must be between 1000 and 10000.");
    expect(errs.some((e) => e.startsWith("Optional charge"))).toBe(true);
    expect(errs).toContain("Cutoff hour must be between 0 and 23, or blank.");
    expect(errs).toContain("United Kingdom · Express: first-slab price must be greater than 0.");
    expect(errs).toContain("United States is shown on the site but has no prices.");
    expect(errs).toContain("“United Kingdom” appears twice.");
  });

  it("needs at least one visible destination", () => {
    const b = clone();
    b.destinations.forEach((x) => (x.active = false));
    expect(validateSite(b)).toContain("At least one destination must be shown on the site.");
  });
});

describe("warnSite", () => {
  it("is quiet on the seed", () => {
    expect(warnSite(SEED)).toEqual([]);
  });

  it("points out odd relationships without blocking", () => {
    const b = clone();
    b.destinations[0]!.rates.express!.first = 3000; // cheaper than Normal 3200
    b.destinations[0]!.rates.normal!.addl = 9000; // step > first
    b.destinations[1]!.rates.express!.days = "";
    const w = warnSite(b);
    expect(w).toContain("United Kingdom: Express is cheaper than Normal (PKR 3,000 vs PKR 3,200).");
    expect(w.some((x) => x.includes("per-step price (PKR 9,000) is higher"))).toBe(true);
    expect(w).toContain("United States · Express: no transit days, so no delivery date can be shown.");
    expect(validateSite(b)).toEqual([]);
  });
});

describe("migrate", () => {
  it("fills defaults for old snapshots and normalises the cutoff", () => {
    const old = { company: { name: "X" }, settings: { cutoffHour: "" }, destinations: [] } as unknown as SiteData;
    const m = migrate(old);
    expect(m.company.name).toBe("X");
    expect(m.company.whatsapp).toBe(SEED.company.whatsapp);
    expect(m.settings.cutoffHour).toBeNull();
    expect(m.services.length).toBe(2);
    expect(m.live).toBe(false);
    expect(migrate({ ...SEED, settings: { ...SEED.settings, cutoffHour: 9 } }).settings.cutoffHour).toBe(9);
  });
});
