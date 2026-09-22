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
    expect(d.lines[1]).toMatchObject({ old: "", new: "New title" });
  });
});

describe("diffSite text fields", () => {
  const faqLines = () => SEED.content.faq.split("\n");

  it("shows the changed line of a multi-line field, not its first 90 characters", () => {
    const b = clone();
    const ls = faqLines();
    ls[2] = `${ls[2]} ZZTEST`;
    b.content.faq = ls.join("\n");
    const d = diffSite(SEED, b);
    expect(d.count).toBe(1);
    expect(d.lines[0]).toEqual({ kind: "field", label: "Website text · faq (line 3)", old: faqLines()[2], new: `${faqLines()[2]} ZZTEST` });
  });

  it("windows a long line around the change so old and new never read the same", () => {
    const b = clone();
    const ls = faqLines();
    expect(ls[0]!.length).toBeGreaterThan(120);
    ls[0] = ls[0]!.replace("shows the working.", "shows the ZZTEST working.");
    b.content.faq = ls.join("\n");
    const d = diffSite(SEED, b);
    const l = d.lines[0]!;
    expect(l.label).toBe("Website text · faq (line 1)");
    expect(l.old).not.toBe(l.new);
    expect(l.new).toContain("ZZTEST");
    expect(l.old).toContain("shows the working.");
    expect(l.old!.startsWith("…")).toBe(true);
    expect(l.new!.length).toBeLessThanOrEqual(92);
    // A short line that grows past the window is cut from the front only as far as needed.
    const g = clone();
    const gs = faqLines();
    gs[2] = `${gs[2]} ZZTEST-LINE3`;
    g.content.faq = gs.join("\n");
    const t = diffSite(SEED, g).lines[0]!;
    expect(t.label).toBe("Website text · faq (line 3)");
    expect(t.old).toBe("…ays duties at the destination? | The receiver, when the country charges them.");
    expect(t.new).toBe("…ays duties at the destination? | The receiver, when the country charges them. ZZTEST-LINE3");
    expect(t.new!.length).toBe(91); // "…" + a full 90-character window
    // The same edit on a single-line field (no line marker) is windowed the same way.
    const c = clone();
    c.settings.disclaimer = SEED.settings.disclaimer.replace("customs delays.", "ZZTEST customs delays.");
    const s = diffSite(SEED, c).lines[0]!;
    expect(s.label).toBe("disclaimer");
    expect(s.new).toContain("ZZTEST");
    expect(s.old).not.toBe(s.new);
  });

  it("names added, removed and multi-line changes", () => {
    const b = clone();
    b.settings.holidays = "2026-12-25 | Quaid-e-Azam Day\n2027-03-23";
    expect(diffSite(SEED, b).lines[0]).toMatchObject({ label: "holidays (lines 1–2 added)", old: "", new: "2026-12-25 | Quaid-e-Azam Day ⏎ 2027-03-23" });
    const c = clone();
    c.content.faq = faqLines().filter((_, i) => i !== 2).join("\n");
    expect(diffSite(SEED, c).lines[0]).toMatchObject({ label: "Website text · faq (line 3 removed)", old: faqLines()[2], new: "" });
    const e = clone();
    const ls = faqLines();
    ls.splice(2, 0, "Extra question? | Extra answer.");
    e.content.faq = ls.join("\n");
    expect(diffSite(SEED, e).lines[0]).toMatchObject({ label: "Website text · faq (line 3 added)", old: "", new: "Extra question? | Extra answer." });
    const f = clone();
    const ms = faqLines();
    ms[1] = "Q2? | A2.";
    ms[3] = "Q4? | A4.";
    f.content.faq = ms.join("\n");
    expect(diffSite(SEED, f).lines[0]).toMatchObject({ label: "Website text · faq (lines 2–4)" });
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

describe("holiday validation", () => {
  it("rejects malformed holiday lines and accepts dated ones", () => {
    const b = clone();
    b.settings.holidays = "2026-12-25 | Quaid-e-Azam Day\n25/12/2026\n";
    const errs = validateSite(b);
    expect(errs).toHaveLength(1);
    expect(errs[0]).toMatch(/25\/12\/2026/);
    b.settings.holidays = "2026-12-25 | Quaid-e-Azam Day\n2027-03-23";
    expect(validateSite(b)).toEqual([]);
  });
});
