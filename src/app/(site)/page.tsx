import type { Metadata } from "next";
import type { ReactNode } from "react";
import { Calculator } from "@/components/calculator/Calculator";
import { CardIcons } from "@/components/Icons";
import { CtaBand } from "@/components/site/CtaBand";
import { Hero, type HeroStat } from "@/components/site/Hero";
import { HoldPanel } from "@/components/site/HoldPanel";
import { heroEyebrow } from "@/components/site/home/eyebrow";
import { RouteBoard } from "@/components/site/home/RouteBoard";
import { Steps } from "@/components/site/home/Steps";
import { Teaser } from "@/components/site/home/Teaser";
import { parseDaysRange } from "@/lib/pricing/dates";
import { lines, parts } from "@/lib/pricing/engine";
import { fmtDate } from "@/lib/pricing/format";
import { getLiveHold, getLiveSite } from "@/lib/site/live";
import { originCities } from "@/lib/site/text";
import type { PublishedVersion } from "@/lib/site/types";

export const metadata: Metadata = { title: "Get a quote" };

/**
 * The manifest strip under the headline: the owner's `content.stats` lines
 * (`Label | Value`) when set, otherwise the three numbers the rate document
 * itself carries — active destinations, the fastest first-service lower
 * bound in days, and the pickup cities. A single pickup city is true but no
 * proof point, so with one city the third cell shows the instant-price
 * ceiling (`settings.maxKg`) instead; nothing here is ever invented. `text`
 * is the whole value as typed; `value` + `suffix` split it so the number can
 * count up on its own.
 */
function heroStats(site: PublishedVersion): HeroStat[] {
  const custom = lines(site.content.stats);
  if (custom.length)
    return custom.map((ln) => {
      const p = parts(ln, 2);
      const m = p[1].match(/^(\d+)(.*)$/);
      return { value: m ? Number(m[1]) : null, text: p[1], suffix: m ? m[2] : "", label: p[0] };
    });
  const active = site.destinations.filter((x) => x.active);
  const cities = originCities(site.company);
  const first = site.services[0]?.id;
  const fastest = active
    .map((x) => (first ? parseDaysRange(x.rates[first]?.days) : null))
    .filter((r): r is [number, number] => r != null)
    .map((r) => r[0]);
  const out: HeroStat[] = [{ value: active.length, text: String(active.length), label: `destination${active.length === 1 ? "" : "s"}` }];
  if (fastest.length) {
    const min = Math.min(...fastest);
    out.push({ value: min, text: `${min}+ days`, suffix: "+ days", label: "fastest delivery" });
  }
  const maxKg = site.settings.maxKg;
  if (cities.length > 1) out.push({ value: cities.length, text: String(cities.length), label: "pickup cities" });
  else if (maxKg > 0) out.push({ value: maxKg, text: `${maxKg} kg`, suffix: " kg", label: "priced instantly" });
  else if (cities.length) out.push({ value: 1, text: "1", label: "pickup city" });
  return out;
}

export default async function QuotePage() {
  const [site, hold] = await Promise.all([getLiveSite(), getLiveHold()]);
  const c = site.content;
  const promise = c.promise.trim();
  let instrument: ReactNode;
  if (hold.on) {
    // On hold: the rate document stays on the server; the browser gets names, the message and a WhatsApp form.
    const destinations = site.destinations
      .filter((x) => x.active)
      .map((x) => ({ id: x.id, name: x.name }))
      .sort((a, b) => a.name.localeCompare(b.name));
    instrument = <HoldPanel companyName={site.company.name} whatsapp={site.company.whatsapp} message={hold.message} destinations={destinations} maxKg={site.settings.maxKg} />;
  } else {
    instrument = (
      <>
        {site.live ? null : <p className="sample">Sample rates shown for demonstration. Prices will be confirmed on WhatsApp.</p>}
        <Calculator site={site} />
      </>
    );
  }
  return (
    <div className="home">
      <section className="calc" aria-label="Get a quote">
        <Hero eyebrow={heroEyebrow(site)} title={c.heroTitle} sub={c.heroSub} stats={heroStats(site)} />
        <div id="quote-instrument" className="instrument">
          <div className="instrument-head">
            <p className="eyebrow">01 — Rate</p>
            {site.version ? <span className="meta">Rates updated {fmtDate(site.publishedAt)}</span> : null}
          </div>
          {instrument}
          {promise ? (
            <p className="promise">
              <CardIcons.shield />
              <span>{promise}</span>
            </p>
          ) : null}
        </div>
      </section>
      <RouteBoard site={site} holdOn={hold.on} />
      <Steps content={c} />
      <Teaser content={c} />
      <CtaBand whatsapp={site.company.whatsapp} title={c.ctaTitle} sub={c.ctaSub} />
    </div>
  );
}
