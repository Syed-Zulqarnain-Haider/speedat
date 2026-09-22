/**
 * Integration test for the version store, and for what a publish does to the
 * rate sheets applied to the draft (this file owns the `imports` table in the
 * scratch database; test files run in parallel, so no other file may empty it).
 * Runs against TEST_DATABASE_URL (a scratch database that is migrated and
 * emptied here); skipped when the variable is not set so `pnpm test` still
 * passes on a machine without Postgres.
 */
import { migrate } from "drizzle-orm/postgres-js/migrator";
import { eq, sql } from "drizzle-orm";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

const url = process.env.TEST_DATABASE_URL;
const suite = url ? describe : describe.skip;

suite("version store", () => {
  let repo: typeof import("./repo");
  let db: typeof import("@/lib/db").db;
  let seed: typeof import("./seed").SEED;

  beforeAll(async () => {
    process.env.DATABASE_URL = url;
    ({ db } = await import("@/lib/db"));
    repo = await import("./repo");
    ({ SEED: seed } = await import("./seed"));
    await migrate(db, { migrationsFolder: "./drizzle" });
    await db.execute(sql`TRUNCATE versions, draft, imports, quotes, audit_log RESTART IDENTITY`);
  });

  afterAll(async () => {
    await db.execute(sql`TRUNCATE versions, draft, imports, quotes, audit_log RESTART IDENTITY`);
  });

  it("starts empty, publishes version 1, branches the draft from it", async () => {
    expect(await repo.getLatestVersion()).toBeNull();
    const v1 = await repo.publishVersion({ data: seed, by: "t", source: "seed", summary: "seed", changeCount: 0 });
    expect(v1.version).toBe(1);
    const d = await repo.getDraft();
    expect(d.baseVersion).toBe(1);
    expect(d.data.destinations).toHaveLength(10);
  });

  it("saves the draft and publishes it as version 2, re-basing the draft", async () => {
    const d = await repo.getDraft();
    const data = structuredClone(d.data);
    data.destinations[0]!.rates.express!.first = 9999;
    await repo.saveDraft(data, "editor@x");
    const again = await repo.getDraft();
    expect(again.data.destinations[0]!.rates.express!.first).toBe(9999);
    expect(again.updatedBy).toBe("editor@x");
    const v2 = await repo.publishVersion({ data, by: "owner@x", source: "admin", summary: "1 change", changeCount: 1, expectedBase: 1 });
    expect(v2.version).toBe(2);
    expect((await repo.getDraft()).baseVersion).toBe(2);
    expect((await repo.getLatestVersion())?.version).toBe(2);
    expect((await repo.getVersion(1))?.destinations[0]!.rates.express!.first).toBe(4500);
  });

  it("refuses to publish on top of a version the caller has not seen", async () => {
    const d = await repo.getDraft();
    await expect(repo.publishVersion({ data: d.data, by: "late@x", source: "admin", summary: "x", changeCount: 1, expectedBase: 1 })).rejects.toBeInstanceOf(
      repo.PublishConflict,
    );
    expect((await repo.getLatestVersion())?.version).toBe(2);
  });

  it("refuses a document the admin's own save would reject, whoever publishes it", async () => {
    const d = await repo.getDraft();
    const bad = structuredClone(d.data);
    bad.content.phone2 = "x".repeat(81);
    await expect(repo.publishVersion({ data: bad, by: "script", source: "convert", summary: "x", changeCount: 1 })).rejects.toThrow(/content\.phone2/);
    expect((await repo.getLatestVersion())?.version).toBe(2);
  });

  it("numbers concurrent publishes consecutively", async () => {
    const d = await repo.getDraft();
    const results = await Promise.all(
      [1, 2, 3].map((i) => repo.publishVersion({ data: d.data, by: `p${i}`, source: "admin", summary: `p${i}`, changeCount: 0 })),
    );
    expect(results.map((r) => r.version).sort()).toEqual([3, 4, 5]);
    expect((await repo.listVersions(10)).map((v) => v.version)).toEqual([5, 4, 3, 2, 1]);
  });

  it("discarding the draft re-branches from the live version", async () => {
    const d = await repo.getDraft();
    const data = structuredClone(d.data);
    data.company.name = "Changed";
    await repo.saveDraft(data, "e");
    await repo.discardDraft();
    const fresh = await repo.getDraft();
    expect(fresh.data.company.name).toBe(seed.company.name);
    expect(fresh.baseVersion).toBe(5);
  });
});

suite("rate sheets across a publish", () => {
  let repo: typeof import("./repo");
  let intake: typeof import("@/lib/import/intake");
  let db: typeof import("@/lib/db").db;
  let schema: typeof import("@/lib/db").schema;
  let seed: typeof import("./seed").SEED;

  const sheet = (fileName: string, status: string, source = "upload") =>
    db
      .insert(schema.imports)
      .values({ source, fileName, fromEmail: source === "email" ? "rates@x" : null, status, decidedBy: status === "applied" ? "who@x" : null, decidedAt: status === "applied" ? new Date() : null })
      .returning({ id: schema.imports.id })
      .then((r) => r[0]!.id);
  const byName = async (fileName: string) => (await intake.listImports(20)).find((i) => i.fileName === fileName)!;

  beforeAll(async () => {
    process.env.DATABASE_URL = url;
    ({ db, schema } = await import("@/lib/db"));
    repo = await import("./repo");
    intake = await import("@/lib/import/intake");
    ({ SEED: seed } = await import("./seed"));
    await migrate(db, { migrationsFolder: "./drizzle" });
    await db.execute(sql`TRUNCATE versions, draft, imports, quotes, audit_log RESTART IDENTITY`);
  });

  afterAll(async () => {
    await db.execute(sql`TRUNCATE versions, draft, imports, quotes, audit_log RESTART IDENTITY`);
  });

  it("an admin's publish takes every applied sheet live under that version, by hand", async () => {
    const a = await sheet("a.xlsx", "applied");
    const b = await sheet("b.xlsx", "applied", "email");
    await sheet("c.xlsx", "needs_mapping", "email");
    await sheet("d.xlsx", "rejected");
    const v1 = await repo.publishVersion({ data: seed, by: "owner@x", source: "admin", summary: "seed", changeCount: 0 });
    expect((await intake.markImportsPublished(v1.version)).sort()).toEqual([a, b]);
    expect(await byName("a.xlsx")).toMatchObject({ status: "published", appliedVersion: 1, publishedAuto: false });
    expect(await byName("b.xlsx")).toMatchObject({ status: "published", appliedVersion: 1, publishedAuto: false });
    expect(await byName("c.xlsx")).toMatchObject({ status: "needs_mapping", appliedVersion: null, publishedAuto: false });
    expect(await byName("d.xlsx")).toMatchObject({ status: "rejected", appliedVersion: null });
    // Nothing left to take live: the next publish marks nothing and moves nothing.
    expect(await intake.markImportsPublished(99)).toEqual([]);
    expect(await byName("a.xlsx")).toMatchObject({ appliedVersion: 1 });
  });

  it("a sheet the email intake published on its own is reported as automatic", async () => {
    const e = await sheet("e.xlsx", "applied", "email");
    const v2 = await repo.publishVersion({ data: seed, by: "email:rates@x", source: "email:rates@x", summary: "auto", changeCount: 1 });
    expect(await intake.markImportsPublished(v2.version)).toEqual([e]);
    expect(await byName("e.xlsx")).toMatchObject({ status: "published", appliedVersion: 2, publishedAuto: true });
    expect(intake.isAutoPublished("email:rates@x")).toBe(true);
    expect(intake.isAutoPublished("admin")).toBe(false);
    expect(intake.isAutoPublished(null)).toBe(false);
  });

  it("discarding or restoring the draft puts applied sheets back to waiting, and leaves published ones alone", async () => {
    const f = await sheet("f.xlsx", "applied");
    expect(await intake.releaseAppliedImports()).toEqual([f]);
    const [row] = await db.select().from(schema.imports).where(eq(schema.imports.id, f)).limit(1);
    expect(row).toMatchObject({ status: "needs_mapping", appliedVersion: null, decidedBy: null, decidedAt: null });
    expect(await byName("a.xlsx")).toMatchObject({ status: "published", appliedVersion: 1 });
    expect(await byName("e.xlsx")).toMatchObject({ status: "published", appliedVersion: 2 });
    expect(await intake.releaseAppliedImports()).toEqual([]);
    // A later publish must not claim the released sheet went live.
    expect(await intake.markImportsPublished(3)).toEqual([]);
    expect(await byName("f.xlsx")).toMatchObject({ status: "needs_mapping", appliedVersion: null });
  });
});

suite("rate limiter", () => {
  it("counts hits per window and resets", async () => {
    process.env.DATABASE_URL = url;
    const { rateLimit } = await import("@/lib/limits");
    const ip = `203.0.113.${Math.floor(Math.random() * 200)}`;
    const results = [];
    for (let i = 0; i < 4; i++) results.push((await rateLimit("test", ip, 3, 60)).ok);
    expect(results).toEqual([true, true, true, false]);
    expect((await rateLimit("other-bucket", ip, 3, 60)).ok).toBe(true);
  });
});
