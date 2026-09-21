import { existsSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { COUNTRY_CODES, flagCode, normalizeCountry } from "./countries";

describe("normalizeCountry", () => {
  it("strips accents, case, punctuation and a leading 'the'", () => {
    expect(normalizeCountry("Türkiye")).toBe("turkiye");
    expect(normalizeCountry("U.K.")).toBe("u k");
    expect(normalizeCountry("  The Netherlands ")).toBe("netherlands");
    expect(normalizeCountry("Bosnia & Herzegovina")).toBe("bosnia herzegovina");
  });
});

describe("flagCode", () => {
  it("matches full names and their aliases", () => {
    expect(flagCode("United Kingdom")).toBe("gb");
    expect(flagCode("UK")).toBe("gb");
    expect(flagCode("U.K.")).toBe("gb");
    expect(flagCode("Britain")).toBe("gb");
    expect(flagCode("Türkiye")).toBe("tr");
    expect(flagCode("Turkey")).toBe("tr");
    expect(flagCode("Dubai")).toBe("ae");
    expect(flagCode("United Arab Emirates")).toBe("ae");
    expect(flagCode("USA")).toBe("us");
    expect(flagCode("America")).toBe("us");
    expect(flagCode("KSA")).toBe("sa");
    expect(flagCode("Holland")).toBe("nl");
  });

  it("falls back to a two-letter id only when it is a known code", () => {
    expect(flagCode("Atlantis")).toBeNull();
    expect(flagCode("Somewhere", "fr")).toBe("fr");
    expect(flagCode("Somewhere", "FR")).toBe("fr");
    expect(flagCode("Somewhere", "france")).toBeNull();
    expect(flagCode("Somewhere", "zz")).toBeNull();
    expect(flagCode("France", "france")).toBe("fr");
  });

  it("prefers the name over the id", () => {
    expect(flagCode("Germany", "au")).toBe("de");
  });
});

describe("the flag files", () => {
  it("exist for every mapped code", () => {
    const dir = join(__dirname, "..", "..", "..", "public", "flags");
    const missing = [...new Set(Object.values(COUNTRY_CODES))].filter((code) => !existsSync(join(dir, `${code}.svg`)));
    expect(missing).toEqual([]);
  });
});
