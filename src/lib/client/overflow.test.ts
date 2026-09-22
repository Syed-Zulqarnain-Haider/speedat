import { describe, expect, it } from "vitest";
import { hiddenEdges } from "./overflow";

describe("hiddenEdges", () => {
  it("is empty when the content fits", () => {
    expect(hiddenEdges(0, 1002, 1002)).toBe("");
    expect(hiddenEdges(0, 1002, 900)).toBe("");
  });

  it("ignores a single pixel of rounding", () => {
    expect(hiddenEdges(0, 1002, 1003)).toBe("");
    expect(hiddenEdges(0.5, 1002, 1247)).toBe("right");
    expect(hiddenEdges(244.6, 1002, 1247)).toBe("left");
  });

  it("names the side that still hides columns", () => {
    // The rates table at 1366px: 1247px of table in a 1002px box, not yet scrolled.
    expect(hiddenEdges(0, 1002, 1247)).toBe("right");
    expect(hiddenEdges(100, 1002, 1247)).toBe("left right");
    expect(hiddenEdges(245, 1002, 1247)).toBe("left");
  });
});
