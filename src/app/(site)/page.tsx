import type { Metadata } from "next";
import type { ReactNode } from "react";
import { Calculator } from "@/components/calculator/Calculator";
import { CardIcons } from "@/components/Icons";
import { CtaBand } from "@/components/site/CtaBand";
import { Hero, type HeroStat } from "@/components/site/Hero";
import { HoldPanel } from "@/components/site/HoldPanel";
import { RouteBoard } from "@/components/site/home/RouteBoard";
import { Steps } from "@/components/site/home/Steps";
import { Teaser } from "@/components/site/home/Teaser";
import { parseDaysRange } from "@/lib/pricing/dates";
import { lines, parts } from "@/lib/pricing/engine";
import { fmtDate } from "@/lib/pricing/format";
import { getLiveHold, getLiveSite } from "@/lib/site/live";
import { originCities } from "@/lib/site/text";
import type { PublishedVersion } from "@/lib/site/types";

export const metadata: Metadata = { title: "Get a price" };

/**
 * The proof points under the headline: the owner's `content.stats` lines
 * (`Label | Value`) when set, otherwise the three numbers the rate document
 * itself carries — active countries, the fastest first-service lower bound
 * in days, and the pickup cities. A single pickup city is true but no proof
 * point, so with one city the third cell shows the instant-price ceiling
 * (`settings.maxKg`) instead; nothing here is ever invented.
 */
function heroStats(site: PublishedVersion): HeroStat[] {
  const custom = lines(site.content.stats);
  if (custom.length)
    return custom.map((ln) => {
      const p = parts(ln, 2);
      return { text: p[1], label: p[0] };
    });
  const active = site.destinations.filter((x) => x.active);
  const cities = originCities(site.company);
  const first = site.services[0]?.id;
  const fastest = active
    .map((x) => (first ? parseDaysRange(x.rates[first]?.days) : null))
    .filter((r): r is [number, number] => r != null)
    .map((r) => r[0]);
  const out: HeroStat[] = [{ text: String(active.length), label: active.length === 1 ? "country" : "countries" }];
  if (fastest.length) {
    const min = Math.min(...fastest);
    out.push({ text: `${min} ${min === 1 ? "day" : "days"}`, label: "at the fastest" });
  }
  const maxKg = site.settings.maxKg;
  if (cities.length > 1) out.push({ text: String(cities.length), label: "pickup cities" });
  else if (maxKg > 0) out.push({ text: `${maxKg} kg`, label: "priced instantly" });
  else if (cities.length) out.push({ text: "1", label: "pickup city" });
  return out;
}

/**
 * Home: hero + calculator (the whole product), how it works, where we
 * deliver, two services, the WhatsApp / Call band. The h1 is the LCP and
 * every word on the page is server-rendered text.
 *
 * "How it works" is a child of the hero grid: on a phone it is the section
 * right after the calculator; from 1024 the grid places it under the proof
 * points in the copy column, so the three steps teach the calculator that
 * sits beside them and the column never ends in a field of white.
 */
export default async function QuotePage() {
  const [site, hold] = await Promise.all([getLiveSite(), getLiveHold()]);
  const c = site.content;
  const promise = c.promise.trim();
  const updated = site.version ? `Rates updated ${fmtDate(site.publishedAt)}` : "";
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
        {site.live ? null : <p className="sample">Sample prices for now. We confirm the real price on WhatsApp.</p>}
        <Calculator site={site} />
      </>
    );
  }
  return (
    <div className="home">
      <section className="calc" aria-label="Get your price">
        <Hero title={c.heroTitle} sub={c.heroSub} stats={heroStats(site)} />
        <div id="quote-instrument" className="instrument">
          {instrument}
          {promise || updated ? (
            <p className="promise">
              <CardIcons.shield />
              <span>{[promise, updated].filter(Boolean).join(" · ")}</span>
            </p>
          ) : null}
        </div>
        <Steps content={c} />
      </section>
      <RouteBoard site={site} holdOn={hold.on} />
      <Teaser content={c} />
      <CtaBand whatsapp={site.company.whatsapp} phone={site.company.phone} title={c.ctaTitle} sub={c.ctaSub} quoteLink={false} />
    </div>
  );
}
