import { describe, expect, it } from "vitest";
import { scrollToClear } from "./reviewLanding";

describe("scrollToClear", () => {
  it("lands a card that fits above the publish bar instead of on the viewport's bottom edge", () => {
    // The QA walk at 1366x768: "Review and publish" left the card at 524–768 with the fixed
    // bar at 681–752 over its Publish/Cancel row (699–743). Scrolling 103px more puts the
    // card at 421–665, 16px above the bar.
    expect(scrollToClear({ top: 524, bottom: 768 }, 681)).toBe(103);
  });

  it("keeps the card under the sticky section tabs on phones", () => {
    // 375px: tabs 45px tall, bar top at 687, card 564–848.
    const delta = scrollToClear({ top: 564, bottom: 848 }, 687, 45);
    expect(delta).toBe(177);
    expect(848 - delta).toBe(687 - 16);
    expect(564 - delta).toBeGreaterThanOrEqual(45 + 16);
  });

  it("does nothing when the card is already fully in view", () => {
    expect(scrollToClear({ top: 100, bottom: 400 }, 681)).toBe(0);
    expect(scrollToClear({ top: 16, bottom: 665 }, 681)).toBe(0);
  });

  it("brings a card that sits above the band back down by its top edge", () => {
    expect(scrollToClear({ top: -200, bottom: 100 }, 681, 45)).toBe(-261);
  });

  it("aligns the top of a card taller than the band so it reads from the start", () => {
    // A 64-line import diff: 1400px of card in a 768px viewport.
    expect(scrollToClear({ top: 524, bottom: 1924 }, 681)).toBe(508);
    // ...even when its top is already visible: showing more of it beats leaving it as is.
    expect(scrollToClear({ top: 300, bottom: 1700 }, 681, 45)).toBe(239);
  });

  it("treats the viewport bottom as the floor when there is no bar", () => {
    expect(scrollToClear({ top: 600, bottom: 900 }, 768)).toBe(148);
  });
});
