/**
 * The price hold's store and message hygiene. The database part runs against
 * TEST_DATABASE_URL like the version store test and is skipped without it.
 */
import { migrate } from "drizzle-orm/postgres-js/migrator";
import { sql } from "drizzle-orm";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
// Pure helpers come from the shared module: importing "./hold" here would open the
// database client against DATABASE_URL before beforeAll points it at the test database.
import { cleanHoldMessage, DEFAULT_HOLD_MESSAGE, HOLD_MESSAGE_MAX } from "./hold-shared";

describe("hold message", () => {
  it("falls back to the default and caps the length", () => {
    expect(cleanHoldMessage("")).toBe(DEFAULT_HOLD_MESSAGE);
    expect(cleanHoldMessage(undefined)).toBe(DEFAULT_HOLD_MESSAGE);
    expect(cleanHoldMessage("  New   rates\n\nsoon  ")).toBe("New rates soon");
    expect(cleanHoldMessage("x".repeat(HOLD_MESSAGE_MAX + 50))).toHaveLength(HOLD_MESSAGE_MAX);
    expect(cleanHoldMessage(42)).toBe("42");
  });
});

const url = process.env.TEST_DATABASE_URL;
const suite = url ? describe : describe.skip;

suite("hold store", () => {
  let hold: typeof import("./hold");
  let db: typeof import("@/lib/db").db;

  beforeAll(async () => {
    process.env.DATABASE_URL = url;
    ({ db } = await import("@/lib/db"));
    hold = await import("./hold");
    await migrate(db, { migrationsFolder: "./drizzle" });
    await db.execute(sql`DELETE FROM app_settings WHERE key = 'hold'`);
  });

  afterAll(async () => {
    await db.execute(sql`DELETE FROM app_settings WHERE key = 'hold'`);
  });

  it("is off until switched, then remembers who and when", async () => {
    expect(await hold.getHold()).toEqual({ on: false, message: DEFAULT_HOLD_MESSAGE, by: "", since: null });
    const on = await hold.setHold(true, "owner@x.com", "Back in an hour");
    expect(on).toMatchObject({ on: true, message: "Back in an hour", by: "owner@x.com" });
    expect(on.since).toMatch(/^\d{4}-\d{2}-\d{2}T/);
    expect(await hold.getHold()).toEqual(on);
  });

  it("keeps the message when switched off without one, and survives junk in the row", async () => {
    const off = await hold.setHold(false, "owner@x.com");
    expect(off).toMatchObject({ on: false, message: "Back in an hour" });
    await db.execute(sql`UPDATE app_settings SET value = '{"on": "yes", "message": 7, "by": null}'::jsonb WHERE key = 'hold'`);
    expect(await hold.getHold()).toEqual({ on: false, message: "7", by: "", since: null });
  });
});
