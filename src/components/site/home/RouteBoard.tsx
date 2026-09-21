import { Accent } from "@/components/site/Accent";
import { Flag } from "@/components/site/Flag";
import { Reveal } from "@/components/site/Reveal";
import { flagCode } from "@/lib/site/countries";
import type { PublishedVersion } from "@/lib/site/types";
import { RouteRow } from "./RouteRow";
import { routeRows } from "./routes";

interface Props {
  site: PublishedVersion;
  holdOn: boolean;
  /** Kept for callers from the first round; no eyebrow line renders any more. */
  eyebrow?: string;
  /** When given, tiles link here instead of selecting in place (e.g. "/#quote-instrument" on /services). */
  href?: string;
}

/**
 * Where we deliver: every active destination as a flag tile with its name,
 * the cheapest 1 kg price and the fastest days, on a warm band. Server
 * component; under hold the tiles carry no price at all, so nothing
 * rate-shaped reaches the browser. Also rendered by /services.
 */
export function RouteBoard({ site, holdOn, href }: Props) {
  const rows = routeRows(site, holdOn);
  if (!rows.length) return null;
  const { docMaxKg, maxKg } = site.settings;
  return (
    <section className="deliver bleed" aria-labelledby="deliver-title">
      <div className="wrap">
        <h2 id="deliver-title">
          <Accent text={site.content.routesTitle} />
        </h2>
        {site.content.routesNote ? <p className="deliver-note">{site.content.routesNote}</p> : null}
        <ul className="dest-grid">
          {rows.map((r, i) => (
            <li key={r.id}>
              <Reveal index={i} stagger={0.04} distance={16}>
                <RouteRow destId={r.id} href={href}>
                  <Flag code={flagCode(r.name, r.id)} name={r.name} size={40} lazy />
                  <span className="dest-text">
                    <span className="dest-name">{r.name}</span>
                    {r.price ? <span className="dest-price">from {r.price}</span> : null}
                    {r.days ? <span className="dest-days">{r.days}</span> : null}
                  </span>
                </RouteRow>
              </Reveal>
            </li>
          ))}
        </ul>
        {docMaxKg > 0 ? <p className="deliver-more">Documents up to {docMaxKg} kg have their own rate.</p> : null}
        {maxKg > 0 ? <p className="deliver-more">Over {maxKg} kg? Ask for a cargo rate on WhatsApp.</p> : null}
      </div>
    </section>
  );
}
