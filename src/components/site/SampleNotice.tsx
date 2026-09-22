interface Props {
  /** `site.live`: false until the owner ticks "Rates are live" on Review and publish. */
  live: boolean;
  /** Under hold no price reaches the browser, so there is nothing to call a sample. */
  holdOn: boolean;
}

/**
 * "Sample prices for now." — the one muted line under every printed price
 * until the owner ticks "Rates are live" (the checkbox promises the notice
 * leaves the website, not just the home page). Printed under the home
 * calculator and under the rates board on /services, the two places a price
 * is printed; null once the rates are live or while the hold has taken the
 * prices off. Server component; `.sample` in calculator.css.
 */
export function SampleNotice({ live, holdOn }: Props) {
  if (live || holdOn) return null;
  return <p className="sample">Sample prices for now. We confirm the real price on WhatsApp.</p>;
}
