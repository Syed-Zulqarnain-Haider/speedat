import { Accent } from "@/components/site/Accent";
import { Reveal } from "@/components/site/Reveal";
import type { PublishedVersion } from "@/lib/site/types";
import { RouteRow } from "./RouteRow";
import { routeRows } from "./routes";

interface Props {
  site: PublishedVersion;
  holdOn: boolean;
  /** Overrides the "02 — Routes" eyebrow (e.g. "Where we deliver" on /services). */
  eyebrow?: string;
  /** When given, rows link here instead of selecting in place (e.g. "/#quote-instrument"). */
  href?: string;
}

/**
 * 02 — the route board: every active destination, its fastest days and the
 * cheapest 1 kg price, on navy. Server component; under hold the rows carry
 * no price at all, so nothing rate-shaped reaches the browser.
 */
export function RouteBoard({ site, holdOn, eyebrow = "02 — Routes", href }: Props) {
  const rows = routeRows(site, holdOn);
  if (!rows.length) return null;
  const { docMaxKg, maxKg } = site.settings;
  const chips = docMaxKg > 0 || maxKg > 0;
  return (
    <section className="routes bleed on-navy" aria-labelledby="routes-title">
      <div className="wrap">
        <div className="routes-head">
          <div className="routes-title">
            <p className="eyebrow">{eyebrow}</p>
            <span className="rule" aria-hidden="true" />
            <h2 id="routes-title">
              <Accent text={site.content.routesTitle} />
            </h2>
          </div>
          {site.content.routesNote || chips ? (
            <div className="routes-meta">
              {site.content.routesNote ? <p className="route-note">{site.content.routesNote}</p> : null}
              {chips ? (
                <p className="route-chips">
                  {docMaxKg > 0 ? <span className="tag">Documents up to {docMaxKg} kg</span> : null}
                  {maxKg > 0 ? <span className="tag">Cargo over {maxKg} kg on request</span> : null}
                </p>
              ) : null}
            </div>
          ) : null}
        </div>
        <ul className="route-list">
          {rows.map((r, i) => (
            <li key={r.id}>
              <Reveal index={i} stagger={0.04} distance={24}>
                <RouteRow destId={r.id} href={href}>
                  <span className="route-code">{r.code}</span>
                  <span className="route-name">{r.name}</span>
                  {r.days ? <span className="route-days">{r.days}</span> : null}
                  {r.price ? <span className="route-price">1 kg from {r.price}</span> : null}
                </RouteRow>
              </Reveal>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
