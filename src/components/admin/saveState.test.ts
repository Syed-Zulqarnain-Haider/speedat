import { describe, expect, it } from "vitest";
import { afterReplace, replaceBlocked } from "./saveState";

describe("afterReplace", () => {
  it("marks a server-stored replacement (discard / restore / import / publish) saved as of the server's time", () => {
    // The pubbar used to keep the previous autosave's time here, so "Draft saved 06:13"
    // survived a restore and a publish that the server stored at 06:16.
    expect(afterReplace("2026-09-22T01:16:00.000Z")).toEqual({ save: "saved", savedAt: "2026-09-22T01:16:00.000Z" });
  });

  it("marks a local replacement (pasted rows) dirty so it autosaves, keeping the last save time until then", () => {
    expect(afterReplace(undefined)).toEqual({ save: "dirty" });
  });
});

describe("replaceBlocked", () => {
  it("holds discard and restore only while an autosave is on the wire", () => {
    // A save landing after a discard used to resurrect the discarded edits on the server.
    expect(replaceBlocked("saving")).toBe(true);
    expect(replaceBlocked("dirty")).toBe(false);
    expect(replaceBlocked("saved")).toBe(false);
    expect(replaceBlocked("error")).toBe(false);
  });
});
