import { describe, expect, it } from "vitest";
import { gridSeed, SEED } from "@/lib/site/seed";
import { adjustPrices, fieldsFor } from "./BulkAdjust";

describe("adjustPrices", () => {
  it("moves the slab and document prices under slab pricing", () => {
    const d = structuredClone(SEED);
    const before = d.destinations[0]!.rates.express!;
    const first = before.first!;
    const n = adjustPrices(d, { services: ["express"], fields: ["first", "addl", "doc"], mode: "pct", value: 10, round: 1 });
    expect(n).toBeGreaterThan(0);
    expect(d.destinations[0]!.rates.express!.first).toBe(Math.round(first * 1.1));
    expect(d.destinations[0]!.rates.normal).toEqual(SEED.destinations[0]!.rates.normal);
  });

  it("moves every kilogram box under grid pricing, and leaves the unused slab prices alone", () => {
    // "+10%" used to change only first/addl/doc, none of which a grid-priced site reads.
    const d = gridSeed();
    const x = d.destinations.find((y) => Object.keys(y.rates.express?.grid ?? {}).length)!;
    const g0 = { ...x.rates.express!.grid! };
    const slab = { first: x.rates.express!.first, addl: x.rates.express!.addl };
    const n = adjustPrices(d, { services: ["express"], fields: ["first", "addl", "doc", "grid"], mode: "amt", value: 100, round: 1 });
    const g1 = x.rates.express!.grid!;
    for (const kg of Object.keys(g0)) expect(g1[kg]).toBe(g0[kg]! + 100);
    expect({ first: x.rates.express!.first, addl: x.rates.express!.addl }).toEqual(slab);
    expect(n).toBeGreaterThanOrEqual(Object.keys(g0).length);
  });

  it("never goes below zero and rounds to the step", () => {
    const d = gridSeed();
    adjustPrices(d, { services: ["express", "normal"], fields: ["grid"], mode: "pct", value: -200, round: 10 });
    for (const x of d.destinations) for (const r of Object.values(x.rates)) for (const v of Object.values(r?.grid ?? {})) expect(v).toBe(0);
  });

  it("names the fields each mode prices with", () => {
    expect(fieldsFor(true)).toEqual(["doc", "grid"]);
    expect(fieldsFor(false)).toEqual(["doc", "first", "addl"]);
  });
});
