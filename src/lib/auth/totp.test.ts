/**
 * Integration test for the TOTP store. Runs against TEST_DATABASE_URL (a
 * scratch database that is migrated and emptied here); skipped when the
 * variable is not set so `pnpm test` still passes on a machine without Postgres.
 */
import { eq, sql } from "drizzle-orm";
import { migrate } from "drizzle-orm/postgres-js/migrator";
import * as OTPAuth from "otpauth";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

const url = process.env.TEST_DATABASE_URL;
const suite = url ? describe : describe.skip;
const EMAIL = "totp-test@example.com";

function codeFor(secret: string): string {
  return new OTPAuth.TOTP({ algorithm: "SHA1", digits: 6, period: 30, secret: OTPAuth.Secret.fromBase32(secret) }).generate();
}

suite("totp enrolment", () => {
  let totp: typeof import("./totp");
  let db: typeof import("@/lib/db").db;
  let schema: typeof import("@/lib/db").schema;

  async function row() {
    const [r] = await db.select({ secret: schema.admins.totpSecret, enabled: schema.admins.totpEnabled }).from(schema.admins).where(eq(schema.admins.email, EMAIL)).limit(1);
    return r!;
  }

  beforeAll(async () => {
    process.env.DATABASE_URL = url;
    process.env.TOTP_ENCRYPTION_KEY ??= "totp-test-key";
    ({ db, schema } = await import("@/lib/db"));
    totp = await import("./totp");
    await migrate(db, { migrationsFolder: "./drizzle" });
    await db.execute(sql`TRUNCATE admins`);
    await db.insert(schema.admins).values({ email: EMAIL, name: "t", role: "owner" });
  });

  afterAll(async () => {
    await db.execute(sql`TRUNCATE admins`);
  });

  it("enrols, verifies a code and turns two-factor on", async () => {
    const r = await totp.beginEnrolment(EMAIL);
    expect(r.uri).toContain("otpauth://totp/");
    expect(r.qrDataUrl.startsWith("data:image/png;base64,")).toBe(true);
    expect(await totp.totpStatus(EMAIL)).toEqual({ enabled: false, pending: true });
    expect(await totp.verifyCode(EMAIL, "000000")).toBe(false);
    expect(await totp.verifyCode(EMAIL, codeFor(r.secret))).toBe(true);
    await totp.enableTotp(EMAIL);
    expect(await totp.totpStatus(EMAIL)).toEqual({ enabled: true, pending: false });
  });

  it("refuses to start a new enrolment while two-factor is on, leaving the secret and the flag untouched", async () => {
    const before = await row();
    expect(before.enabled).toBe(true);
    await expect(totp.beginEnrolment(EMAIL)).rejects.toBeInstanceOf(totp.TotpAlreadyEnabled);
    const after = await row();
    expect(after.enabled).toBe(true);
    expect(after.secret).toBe(before.secret);
    expect(await totp.totpStatus(EMAIL)).toEqual({ enabled: true, pending: false });
  });

  it("allows a fresh enrolment again once two-factor has been turned off", async () => {
    await totp.disableTotp(EMAIL);
    expect(await totp.totpStatus(EMAIL)).toEqual({ enabled: false, pending: false });
    const r = await totp.beginEnrolment(EMAIL);
    expect(r.secret).toHaveLength(32);
    expect(await totp.totpStatus(EMAIL)).toEqual({ enabled: false, pending: true });
  });
});
