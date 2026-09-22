import { describe, expect, it } from "vitest";
import { importStatusText, type ImportStatusInput } from "./importStatus";

const base: ImportStatusInput = { status: "applied", appliedVersion: null, publishedAuto: false, rows: 3, errors: 0, error: null };

describe("recent sheet status line", () => {
  it("says a sheet is waiting in the editor until a publish takes it live", () => {
    expect(importStatusText(base)).toBe("in the editor — publish to go live · 3 rows");
  });

  it("names the version an admin's publish took it out in", () => {
    expect(importStatusText({ ...base, status: "published", appliedVersion: 20 })).toBe("published (version 20) · 3 rows");
  });

  it("keeps the wording for a sheet the email intake published on its own", () => {
    expect(importStatusText({ ...base, status: "published", appliedVersion: 5, publishedAuto: true, rows: 11 })).toBe("published automatically (version 5) · 11 rows");
  });

  it("shows skipped rows, the failure reason and unknown statuses as they are", () => {
    expect(importStatusText({ ...base, status: "needs_mapping", rows: 0, errors: 2 })).toBe("needs mapping · 2 skipped");
    expect(importStatusText({ ...base, status: "failed", rows: 0, error: "The file could not be read as a spreadsheet" })).toBe(
      "could not be read · The file could not be read as a spreadsheet",
    );
    expect(importStatusText({ ...base, status: "rejected", rows: 0 })).toBe("rejected");
    expect(importStatusText({ ...base, status: "ready", rows: 0 })).toBe("ready");
  });
});
