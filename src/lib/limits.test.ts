import { describe, expect, it, vi } from "vitest";

// limits.ts opens the database client at import; these tests never touch it.
vi.mock("@/lib/db", () => ({ db: {}, schema: {} }));
vi.mock("next/headers", () => ({ headers: async () => new Headers() }));

const { pickClientIp } = await import("./limits");

const h = (init: Record<string, string>) => new Headers(init);

describe("pickClientIp", () => {
  it("prefers Vercel's own header over anything the client can send", () => {
    expect(pickClientIp(h({ "x-vercel-forwarded-for": "203.0.113.7", "x-real-ip": "198.51.100.1", "x-forwarded-for": "1.2.3.4, 203.0.113.7" }))).toBe("203.0.113.7");
  });
  it("falls back to x-real-ip", () => {
    expect(pickClientIp(h({ "x-real-ip": "198.51.100.1", "x-forwarded-for": "1.2.3.4" }))).toBe("198.51.100.1");
  });
  it("uses the last x-forwarded-for hop, never the spoofable first", () => {
    expect(pickClientIp(h({ "x-forwarded-for": "6.6.6.6, 10.0.0.1,  203.0.113.9 " }))).toBe("203.0.113.9");
    expect(pickClientIp(h({ "x-forwarded-for": "203.0.113.9" }))).toBe("203.0.113.9");
  });
  it("says unknown when nothing usable is present", () => {
    expect(pickClientIp(h({}))).toBe("unknown");
    expect(pickClientIp(h({ "x-forwarded-for": " , " }))).toBe("unknown");
  });
});
