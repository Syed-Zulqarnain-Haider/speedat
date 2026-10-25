/**
 * The intake's publish decisions, with the database and the version store
 * replaced by fakes: what an unattended email sheet publishes (live + sheet,
 * never the draft's edits), that the admin's apply never publishes, that a
 * hold is never lifted, and that a sheet changing nothing is reported.
 */
import { beforeEach, describe, expect, it, vi } from "vitest";
import { gridSeed } from "@/lib/site/seed";
import type { SiteData } from "@/lib/site/types";

const selects: unknown[][] = [];
const sets: Record<string, unknown>[] = [];

/** A drizzle-like chain: every method returns the chain; awaiting it yields `result()`. */
function chain(result: () => unknown, onSet?: (v: Record<string, unknown>) => void): unknown {
  const p: unknown = new Proxy(() => {}, {
    get(_t, k) {
      if (k === "then") return (res: (v: unknown) => void) => res(result());
      return (...a: unknown[]) => {
        if (k === "set" && onSet) onSet(a[0] as Record<string, unknown>);
        return p;
      };
    },
  });
  return p;
}

vi.mock("@/lib/db", async () => ({
  db: {
    select: () => chain(() => selects.shift() ?? []),
    update: () => chain(() => [], (v) => sets.push(v)),
  },
  schema: await import("@/lib/db/schema"),
}));
const repo = vi.hoisted(() => ({
  draft: null as unknown as SiteData,
  live: null as unknown as SiteData & { version: number; publishedAt: string },
  saved: [] as SiteData[],
  /** Saves after this many succeed throw (a DB blip after the publish committed); -1 = never. */
  failSaveAfter: -1,
  published: [] as { data: SiteData; expectedBase?: number; liftHold?: boolean }[],
}));
vi.mock("@/lib/site/repo", () => ({
  PublishConflict: class extends Error {},
  getDraft: async () => ({ baseVersion: 7, data: structuredClone(repo.draft), updatedAt: "", updatedBy: "" }),
  getLatestVersion: async () => structuredClone(repo.live),
  saveDraft: async (d: SiteData) => {
    if (repo.failSaveAfter >= 0 && repo.saved.length >= repo.failSaveAfter) throw new Error("db blip");
    repo.saved.push(structuredClone(d));
  },
  publishVersion: async (a: { data: SiteData; expectedBase?: number; liftHold?: boolean }) => {
    repo.published.push(a);
    return { ...a.data, version: 8, publishedAt: "", hold: null };
  },
}));
const hold = vi.hoisted(() => ({ on: true }));
vi.mock("@/lib/site/live", () => ({ SITE_TAG: "site" }));
vi.mock("@/lib/site/hold", () => ({ getHold: async () => ({ on: hold.on, message: "", by: "", since: null }) }));
const alerts = vi.hoisted(() => [] as { subject: string; body: string }[]);
vi.mock("@/lib/alerts", () => ({ alertNow: async (subject: string, body: string) => void alerts.push({ subject, body }) }));
vi.mock("@/lib/audit", () => ({ audit: async () => {} }));
const revalidateTag = vi.hoisted(() => vi.fn());
vi.mock("next/cache", () => ({ revalidateTag }));

const intake = await import("./intake");

const SHEET = [
  ["Country", "Express First", "Express Add"],
  ["United Kingdom", "4600", "1100"],
];
const MAP = { "name": 0, "express.first": 1, "express.addl": 2 };
const OPTS = { addNew: true, hideMissing: false, cost: false, margin: 0, mround: 1 };

function arrange(rows: string[][]) {
  const live = gridSeed();
  repo.live = { ...live, version: 7, publishedAt: "" };
  // The draft holds an unreviewed manual edit that must not go live with the sheet.
  repo.draft = structuredClone(live);
  repo.draft.destinations.find((d) => d.id === "gb")!.rates.normal!.doc = 1;
  repo.saved = [];
  repo.failSaveAfter = -1;
  repo.published = [];
  selects.length = 0;
  sets.length = 0;
  alerts.length = 0;
  revalidateTag.mockClear();
  selects.push([{ id: 1, source: "email", status: "needs_mapping", fileName: "r.xlsx", fromEmail: "rates@air.example", rows: { Rates: rows }, appliedVersion: null }]);
  selects.push([{ value: { autoPublishPct: 100 } }]);
}

const apply = (unattended: boolean) => intake.applyToDraft({ importId: 1, sheet: "Rates", headerRow: 0, map: MAP, opts: OPTS, actor: "email:rates@air.example", unattended });

describe("unattended email auto-publish", () => {
  beforeEach(() => {
    hold.on = true;
  });

  it("publishes live + the sheet against the live version, keeps the draft's edits in the draft, and leaves the hold on", async () => {
    arrange(SHEET);
    const r = await apply(true);
    expect(r).toMatchObject({ status: "published", publishedVersion: 8 });
    const pub = repo.published[0]!;
    expect(pub).toMatchObject({ expectedBase: 7, liftHold: false });
    const gb = pub.data.destinations.find((d) => d.id === "gb")!;
    expect(gb.rates.normal!.doc).toBe(2800); // the live value, not the draft's edit
    expect(gb.rates.express!.first).toBe(4600);
    expect(gb.rates.express!.grid!["5"]).not.toBe(repo.live.destinations.find((d) => d.id === "gb")!.rates.express!.grid!["5"]);
    // The draft is written back with its edit and the sheet.
    const lastDraft = repo.saved.at(-1)!.destinations.find((d) => d.id === "gb")!;
    expect(lastDraft.rates.normal!.doc).toBe(1);
    expect(lastDraft.rates.express!.first).toBe(4600);
    expect(revalidateTag).toHaveBeenCalledWith("site", { expire: 0 });
    expect(alerts.at(-1)!.body).toMatch(/Prices remain on hold/);
    expect(sets.at(-1)).toMatchObject({ status: "published", appliedVersion: 8 });
  });

  it("says nothing about a hold when there is none", async () => {
    hold.on = false;
    arrange(SHEET);
    await apply(true);
    expect(alerts.at(-1)!.body).not.toMatch(/hold/);
  });

  it("never publishes from the admin's Apply (unattended unset), even for an emailed sheet", async () => {
    arrange(SHEET);
    const r = await apply(false);
    expect(r.status).toBe("applied");
    expect(repo.published).toEqual([]);
    expect(revalidateTag).not.toHaveBeenCalled();
  });

  it("reports a sheet that matches the live site instead of leaving it silently applied", async () => {
    arrange([
      ["Country", "Express First", "Express Add"],
      ["United Kingdom", "4500", "1100"],
    ]);
    const r = await apply(true);
    expect(r.status).toBe("applied");
    expect(repo.published).toEqual([]);
    expect(sets.at(-1)).toMatchObject({ status: "published", appliedVersion: 7 });
    expect(alerts.at(-1)!.subject).toMatch(/changed nothing/);
  });

  it("holds a sheet with unreadable rows even when the readable ones match live", async () => {
    arrange([
      ["Country", "Express First", "Express Add"],
      ["United Kingdom", "4500", "1100"],
      ["United States", "abc", "1100"],
    ]);
    const r = await apply(true);
    expect(r.status).toBe("applied");
    expect(repo.published).toEqual([]);
    expect(sets.at(-1)).not.toMatchObject({ status: "published" });
    expect(alerts.at(-1)!.subject).toMatch(/waiting for your review/);
  });

  it("still serves and records a committed publish when restoring the draft fails", async () => {
    arrange(SHEET);
    repo.failSaveAfter = 1; // the apply's save succeeds, the write-back after the publish throws
    const r = await apply(true);
    expect(r).toMatchObject({ status: "published", publishedVersion: 8 });
    expect(revalidateTag).toHaveBeenCalledWith("site", { expire: 0 });
    expect(sets.at(-1)).toMatchObject({ status: "published", appliedVersion: 8 });
  });

  it("holds a sheet that adds a destination, whatever the tolerance", async () => {
    arrange([...SHEET, ["Narnia", "100", "100"]]);
    const r = await apply(true);
    expect(r.status).toBe("applied");
    expect(repo.published).toEqual([]);
    expect(alerts.at(-1)!.body).toMatch(/Narnia is a new destination/);
  });
});

describe("holdReason / receivedToday", () => {
  it("explains a hold", () => {
    expect(intake.holdReason({ autoPublishPct: 0, rowErrors: 0, validation: [], worst: null })).toBe("auto-publish is off");
    expect(intake.holdReason({ autoPublishPct: 5, rowErrors: 0, validation: [], worst: { pct: 12.5, label: "UK express 3 kg" } })).toBe("UK express 3 kg moved 12.5%");
    expect(intake.holdReason({ autoPublishPct: 5, rowErrors: 0, validation: [], worst: { pct: 4, label: "x" } })).toBeNull();
  });
  it("judges 'today' on the office's calendar, not the server's", () => {
    // 23:30 UTC on the 22nd is 04:30 on the 23rd in Karachi.
    expect(intake.receivedToday("2026-10-22T23:30:00Z", new Date("2026-10-23T06:00:00Z"))).toBe(true);
    expect(intake.receivedToday("2026-10-22T18:00:00Z", new Date("2026-10-23T06:00:00Z"))).toBe(false);
  });
});
