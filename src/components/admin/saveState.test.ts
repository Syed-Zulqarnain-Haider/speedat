import { describe, expect, it } from "vitest";
import { afterReplace } from "./saveState";

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
