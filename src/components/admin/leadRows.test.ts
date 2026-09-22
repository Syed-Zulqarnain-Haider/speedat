import { describe, expect, it } from "vitest";
import { applyLeadSave } from "./leadRows";

const untouched = { id: 7, status: "new", notes: "", updatedAt: "2026-09-22T06:00:00.000Z", updatedBy: "" };
const other = { id: 8, status: "new", notes: "", updatedAt: "2026-09-22T06:00:00.000Z", updatedBy: "" };

describe("applyLeadSave", () => {
  it("carries who touched the lead and when into the row, so the footer shows on the save (not the next reload)", () => {
    // The action used to return status/notes/updatedAt only; the row kept updatedBy '' and the
    // "Last touched by" footer stayed hidden until the page was reloaded.
    const rows = applyLeadSave([untouched, other], 7, { status: "contacted", notes: "", updatedAt: "2026-09-22T06:48:00.000Z", updatedBy: "owner@example.com" });
    expect(rows[0]).toEqual({ id: 7, status: "contacted", notes: "", updatedAt: "2026-09-22T06:48:00.000Z", updatedBy: "owner@example.com" });
    expect(rows[0]?.updatedBy).toBeTruthy();
  });

  it("leaves every other row alone and keeps the saved row in the list", () => {
    const rows = applyLeadSave([untouched, other], 7, { status: "contacted", notes: "pickup Tuesday", updatedAt: "2026-09-22T06:48:00.000Z", updatedBy: "owner@example.com" });
    expect(rows).toHaveLength(2);
    expect(rows[1]).toBe(other);
    expect(rows[0]?.notes).toBe("pickup Tuesday");
  });
});
