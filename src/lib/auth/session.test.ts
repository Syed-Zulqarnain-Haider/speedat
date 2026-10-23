import { describe, expect, it, vi } from "vitest";

// session.ts opens the database client at import; these tests never touch it.
vi.mock("@/lib/db", () => ({ db: {}, schema: {} }));
vi.mock("next/headers", () => ({ cookies: async () => ({ get: () => undefined }) }));
vi.mock("next/navigation", () => ({ redirect: vi.fn() }));
vi.mock("./firebase-admin", () => ({ adminAuth: vi.fn(), firebaseConfigured: () => false }));

const { devBypassAllowed, devBypassEmail } = await import("./session");

describe("dev admin bypass", () => {
  it("works only in local development", () => {
    expect(devBypassEmail({ NODE_ENV: "development", DEV_ADMIN_EMAIL: " Owner@Example.com " })).toBe("owner@example.com");
    expect(devBypassEmail({ NODE_ENV: "development" })).toBeNull();
  });
  it("never activates in a production-like environment", () => {
    expect(devBypassAllowed({ NODE_ENV: "production" })).toBe(false);
    expect(devBypassAllowed({ NODE_ENV: "test" })).toBe(false);
    expect(devBypassAllowed({ NODE_ENV: "development", VERCEL_ENV: "production" })).toBe(false);
    expect(devBypassAllowed({ NODE_ENV: "development", VERCEL_ENV: "preview" })).toBe(false);
    expect(devBypassAllowed({ NODE_ENV: "development", APP_ENV: "Production" })).toBe(false);
    expect(devBypassAllowed({ NODE_ENV: "development", ENVIRONMENT: "staging" })).toBe(false);
    expect(devBypassEmail({ NODE_ENV: "development", VERCEL_ENV: "production", DEV_ADMIN_EMAIL: "a@b.c" })).toBeNull();
  });
  it("allows `vercel dev`", () => {
    expect(devBypassAllowed({ NODE_ENV: "development", VERCEL_ENV: "development" })).toBe(true);
  });
});
