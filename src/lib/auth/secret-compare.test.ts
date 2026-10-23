import { describe, expect, it } from "vitest";
import { bearerMatches, safeEqual } from "./secret-compare";

describe("safeEqual", () => {
  it("matches only the exact secret", () => {
    expect(safeEqual("s3cret", "s3cret")).toBe(true);
    expect(safeEqual("s3cret ", "s3cret")).toBe(false);
    expect(safeEqual("s3cre", "s3cret")).toBe(false);
    expect(safeEqual("S3cret", "s3cret")).toBe(false);
  });
  it("never matches an unset secret", () => {
    expect(safeEqual("", "")).toBe(false);
    expect(safeEqual("x", undefined)).toBe(false);
    expect(safeEqual(null, "x")).toBe(false);
  });
});

describe("bearerMatches", () => {
  it("accepts exactly `Bearer <secret>`", () => {
    expect(bearerMatches("Bearer abc123", "abc123")).toBe(true);
    expect(bearerMatches("Bearer abc1234", "abc123")).toBe(false);
    expect(bearerMatches("bearer abc123", "abc123")).toBe(false);
    expect(bearerMatches("abc123", "abc123")).toBe(false);
    expect(bearerMatches(null, "abc123")).toBe(false);
  });
  it("refuses everything when the secret is unset", () => {
    expect(bearerMatches("Bearer ", "")).toBe(false);
    expect(bearerMatches("Bearer undefined", undefined)).toBe(false);
  });
});
