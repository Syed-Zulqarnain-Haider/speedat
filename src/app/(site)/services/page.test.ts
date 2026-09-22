/**
 * /services prints the same 1 kg prices as the home calculator in its rates
 * board, so it must carry the same "Sample prices for now" caveat until the
 * owner ticks "Rates are live" (QA: a visitor landing here from a search saw
 * sample numbers presented as real). The live document and the hold are
 * mocked; the page itself is rendered as the server would.
 */
import { renderToStaticMarkup } from "react-dom/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { NO_HOLD, type Hold } from "@/lib/site/hold-shared";
import { SEED } from "@/lib/site/seed";
import type { PublishedVersion } from "@/lib/site/types";

const state: { site: PublishedVersion; hold: Hold } = {
  site: { ...SEED, live: false, version: 0, publishedAt: new Date(0).toISOString() },
  hold: NO_HOLD,
};

vi.mock("@/lib/site/live", () => ({
  SITE_TAG: "site",
  getLiveSite: () => Promise.resolve(state.site),
  getLiveHold: () => Promise.resolve(state.hold),
}));

async function renderServices(): Promise<string> {
  const { default: ServicesPage } = await import("./page");
  return renderToStaticMarkup(await ServicesPage());
}

describe("/services sample-prices notice", () => {
  beforeEach(() => {
    state.site = { ...SEED, live: false, version: 0, publishedAt: new Date(0).toISOString() };
    state.hold = NO_HOLD;
  });

  it("prints the notice under the board while the rates are sample", async () => {
    const html = await renderServices();
    expect(html).toContain('class="rates"');
    expect(html).toContain("PKR");
    const board = html.indexOf('class="rates"');
    const notice = html.indexOf("Sample prices for now");
    expect(notice).toBeGreaterThan(board);
  });

  it("drops the notice once the rates are live", async () => {
    state.site = { ...state.site, live: true };
    const html = await renderServices();
    expect(html).toContain('class="rates"');
    expect(html).not.toContain("Sample prices for now");
  });

  it("drops the notice under hold, where the board prints no price", async () => {
    state.hold = { ...NO_HOLD, on: true };
    const html = await renderServices();
    expect(html).not.toContain("PKR");
    expect(html).not.toContain("Sample prices for now");
  });
});
