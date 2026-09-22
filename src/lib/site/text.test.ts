import { describe, expect, it } from "vitest";
import { estimateDelivery } from "@/lib/pricing/dates";
import { cutoffFact, originCities } from "./text";

describe("originCities", () => {
  it("splits on commas, semicolons and newlines and drops blanks", () => {
    expect(originCities({ originCities: "Lahore, Faisalabad;Multan\n\n Karachi " })).toEqual(["Lahore", "Faisalabad", "Multan", "Karachi"]);
    expect(originCities({ originCities: "" })).toEqual([]);
  });
});

describe("cutoffFact", () => {
  it("promises same-day pickup before a real cutoff hour", () => {
    expect(cutoffFact(15)).toBe("Book before 3 pm for same-day pickup");
    expect(cutoffFact(23)).toBe("Book before 11 pm for same-day pickup");
    expect(cutoffFact(1)).toBe("Book before 1 am for same-day pickup");
  });

  it("prints nothing when there is no cutoff", () => {
    expect(cutoffFact(null)).toBe("");
    expect(cutoffFact(undefined)).toBe("");
    expect(cutoffFact(Number.NaN)).toBe("");
  });

  it("never promises same-day pickup at a cutoff of 0, which the calculator can never honour", () => {
    // The premise: at cutoff 0 `now.getHours() >= 0` holds at every hour, so pickup always moves.
    const sets = { workingDays: "Mon, Tue, Wed, Thu, Fri, Sat", cutoffHour: 0 };
    for (const hour of [0, 9, 13, 23]) {
      const est = estimateDelivery("3–5", sets, null, new Date(2026, 8, 22, hour, 0))!; // Tuesday
      expect(est.moved).toBe(true);
      expect(est.pickup.getDate()).toBe(23);
    }
    const sentence = cutoffFact(0);
    expect(sentence).toBe("No same-day pickup; we collect on the next working day");
    expect(sentence).not.toMatch(/12 am|same-day pickup$/);
  });
});
