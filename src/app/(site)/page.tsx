import type { Metadata } from "next";
import { Calculator } from "@/components/calculator/Calculator";
import { parseDaysRange } from "@/lib/pricing/dates";
import { lines, parts } from "@/lib/pricing/engine";
import { getLiveSite } from "@/lib/site/live";
import { originCities } from "@/lib/site/text";
import type { PublishedVersion } from "@/lib/site/types";

export const metadata: Metadata = { title: "Get a quote" };

function Stats({ site }: { site: PublishedVersion }) {
  const custom = lines(site.content.stats);
  if (custom.length)
    return (
      <>
        {custom.map((ln, i) => {
          const p = parts(ln, 2);
          return (
            <li key={i}>
              <strong>{p[1]}</strong>
              <span>{p[0]}</span>
            </li>
          );
        })}
      </>
    );
  const active = site.destinations.filter((x) => x.active);
  const cities = originCities(site.company);
  const first = site.services[0]?.id;
  const fastest = active
    .map((x) => (first ? parseDaysRange(x.rates[first]?.days) : null))
    .filter((r): r is [number, number] => r != null)
    .map((r) => r[0]);
  return (
    <>
      <li>
        <strong>{active.length}</strong>
        <span>destination{active.length === 1 ? "" : "s"}</span>
      </li>
      {fastest.length ? (
        <li>
          <strong>{Math.min(...fastest)}+ days</strong>
          <span>fastest delivery</span>
        </li>
      ) : null}
      {cities.length ? (
        <li>
          <strong>{cities.length}</strong>
          <span>pickup {cities.length === 1 ? "city" : "cities"}</span>
        </li>
      ) : null}
    </>
  );
}

export default async function QuotePage() {
  const site = await getLiveSite();
  return (
    <section className="calc">
      {site.live ? null : <p className="sample">Sample rates shown for demonstration. Prices will be confirmed on WhatsApp.</p>}
      <div className="hero">
        <h1>{site.content.heroTitle}</h1>
        <p className="lede">{site.content.heroSub}</p>
        <ul className="stats">
          <Stats site={site} />
        </ul>
      </div>
      <Calculator site={site} />
    </section>
  );
}
