import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { SampleNotice } from "./SampleNotice";

const render = (live: boolean, holdOn: boolean) => renderToStaticMarkup(createElement(SampleNotice, { live, holdOn }));

describe("SampleNotice", () => {
  it("prints the caveat while the rates are not live", () => {
    const html = render(false, false);
    expect(html).toContain('class="sample"');
    expect(html).toContain("Sample prices for now");
  });

  it("prints nothing once the owner has ticked “Rates are live”", () => {
    expect(render(true, false)).toBe("");
  });

  it("prints nothing under hold: no price is on the page to call a sample", () => {
    expect(render(false, true)).toBe("");
    expect(render(true, true)).toBe("");
  });
});
