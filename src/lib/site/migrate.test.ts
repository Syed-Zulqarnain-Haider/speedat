import { describe, expect, it } from "vitest";
import { RETIRED_DEFAULTS, migrate, upgradeContent } from "./migrate";
import { SEED } from "./seed";
import type { Content } from "./types";

describe("upgradeContent", () => {
  it("replaces text that still reads as an earlier round's default with the current default", () => {
    const stored: Content = {
      ...SEED.content,
      heroTitle: "Send anything *abroad*. See your price now.",
      heroSub: "Tap your country and the weight. Your price appears — then book on WhatsApp.",
      routesTitle: "Where we deliver",
      ctaTitle: "Ready to send something?",
      stepsTitle: "Three taps, one price",
    };
    const up = upgradeContent(stored);
    expect(up.heroTitle).toBe("");
    expect(up.heroSub).toBe("");
    expect(up.routesTitle).toBe("Rates");
    expect(up.ctaTitle).toBe("");
    expect(up.stepsTitle).toBe("");
  });

  it("keeps every word the owner typed himself", () => {
    const stored: Content = { ...SEED.content, heroTitle: "Lahore to the world.", faq: "Do you ship on Sunday? | No.", routesNote: "Prices per kilo" };
    const up = upgradeContent(stored);
    expect(up.heroTitle).toBe("Lahore to the world.");
    expect(up.faq).toBe("Do you ship on Sunday? | No.");
    expect(up.routesNote).toBe("Prices per kilo");
  });

  it("never lists a current default as retired, so the upgrade is idempotent", () => {
    for (const key of Object.keys(RETIRED_DEFAULTS) as (keyof Content)[]) {
      const cur = SEED.content[key];
      if (cur) expect(RETIRED_DEFAULTS[key]).not.toContain(cur);
    }
    expect(upgradeContent(SEED.content)).toEqual(SEED.content);
  });
});

describe("migrate", () => {
  it("fills missing content keys from the seed and upgrades retired defaults in one pass", () => {
    const out = migrate({ content: { heroTitle: "Where we fly from Lahore" } as Partial<Content> as Content, destinations: [] });
    expect(out.content.heroTitle).toBe("Where we fly from Lahore");
    expect(out.content.promise).toBe(SEED.content.promise);
    const v2 = migrate({ content: { ...SEED.content, routesTitle: "Where we deliver", servicesTitle: "Two ways to send" } });
    expect(v2.content.routesTitle).toBe("Rates");
    expect(v2.content.servicesTitle).toBe("");
  });

  it("brings a whole document published from an earlier seed onto the current copy, asterisks and all", () => {
    // Every retired field at its last (v2) default, as the dev host stored it before v3; the rest as the owner keeps them.
    const old: Content = { ...SEED.content, address: "Shop 4, Main Market", hours: "9 to 5", phone2: "+92 300 0000000" };
    for (const key of Object.keys(RETIRED_DEFAULTS) as (keyof Content)[]) old[key] = RETIRED_DEFAULTS[key]!.at(-1)!;
    const out = migrate({ live: true, content: old, destinations: [] });
    for (const key of Object.keys(RETIRED_DEFAULTS) as (keyof Content)[]) expect(out.content[key], key).toBe(SEED.content[key]);
    expect(out.content.address).toBe("Shop 4, Main Market");
    expect(out.content.hours).toBe("9 to 5");
    expect(out.content.phone2).toBe("+92 300 0000000");
    expect(Object.values(out.content).some((v) => /\*[^*]+\*/.test(v))).toBe(false);
  });
});
