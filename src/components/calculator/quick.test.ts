import { describe, expect, it } from "vitest";
import { gridSeed, SEED } from "@/lib/site/seed";
import { KG_BUTTONS, quickKgs } from "./quick";

describe("quick weight buttons", () => {
  it("offers every button the seed prices", () => {
    expect(quickKgs(SEED)).toEqual(KG_BUTTONS);
    expect(quickKgs(gridSeed())).toEqual(KG_BUTTONS);
  });

  it("drops buttons above the last priced grid kg on every destination", () => {
    const g = gridSeed();
    for (const d of g.destinations) for (const r of Object.values(d.rates)) if (r) r.grid = { "1": 4000, "2": 6000, "3": 8000 };
    expect(quickKgs(g)).toEqual([1, 2, 3]);
  });

  it("keeps a button one destination prices even when another does not", () => {
    const g = gridSeed();
    for (const d of g.destinations.slice(1)) for (const r of Object.values(d.rates)) if (r) r.grid = { "1": 4000 };
    expect(quickKgs(g)).toEqual(KG_BUTTONS);
  });

  it("judges a slab button against the cargo limit on its weight, not its billed weight", () => {
    // 1 kg first slab + 2 kg steps: 25 kg is billed as 25 kg (1 + 12 × 2), within the limit.
    const fits = structuredClone(SEED);
    Object.assign(fits.settings, { firstKg: 1, stepKg: 2, maxKg: 25 });
    expect(quickKgs(fits)).toContain(25);
    // 2 kg first slab + 2 kg steps: 25 kg bills as 26 kg but weighs 25 kg, so it is still priced.
    const at = structuredClone(SEED);
    Object.assign(at.settings, { firstKg: 2, stepKg: 2, maxKg: 25 });
    expect(quickKgs(at)).toContain(25);
    // Under a 24 kg limit the 25 kg button goes.
    const over = structuredClone(SEED);
    Object.assign(over.settings, { firstKg: 2, stepKg: 2, maxKg: 24 });
    expect(quickKgs(over)).not.toContain(25);
    expect(quickKgs(over)).toContain(20);
  });

  it("ignores hidden destinations", () => {
    const g = gridSeed();
    g.destinations.forEach((d, i) => {
      if (i > 0) d.active = false;
      else for (const r of Object.values(d.rates)) if (r) r.grid = { "1": 4000, "5": 9000 };
    });
    expect(quickKgs(g)).toEqual([1, 2, 3, 5]);
  });
});
