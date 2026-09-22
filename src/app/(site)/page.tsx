import type { Metadata } from "next";
import type { ReactNode } from "react";
import { Calculator } from "@/components/calculator/Calculator";
import { Hero, type HeroStat } from "@/components/site/Hero";
import { HoldPanel } from "@/components/site/HoldPanel";
import { RatesBoard } from "@/components/site/home/RatesBoard";
import { lines, parts } from "@/lib/pricing/engine";
import { heroSubAuto, heroTitleAuto } from "@/lib/site/copy";
import { getLiveHold, getLiveSite } from "@/lib/site/live";
import type { PublishedVersion } from "@/lib/site/types";

export const metadata: Metadata = { title: "Get a price" };

/** The owner's `content.stats` lines (`Label | Value`) as printed points; blank = no line (nothing is invented). */
function heroStats(site: PublishedVersion): HeroStat[] {
  return lines(site.content.stats).map((ln) => {
    const p = parts(ln, 2);
    return { text: p[1], label: p[0] };
  });
}

/**
 * Home (brief v3 §3): one section. The headline names the route and the
 * lowest 1 kg price, the calculator is the page, the rates board is the
 * reference under it — on a phone in that order, from 1024 the headline
 * and the board share the left column beside the calculator. Every word is
 * server-rendered; nothing reveals, counts or scrolls by itself.
 */
export default async function QuotePage() {
  const [site, hold] = await Promise.all([getLiveSite(), getLiveHold()]);
  const c = site.content;
  const title = c.heroTitle.trim() || heroTitleAuto(site);
  const sub = c.heroSub.trim() || heroSubAuto(site, hold.on);
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
        <Calculator site={site} />
        {site.live ? null : <p className="sample">Sample prices for now. We confirm the real price on WhatsApp.</p>}
      </>
    );
  }
  return (
    <div className="home">
      <section className="calc" aria-label="Get your price">
        <Hero title={title} sub={sub} stats={heroStats(site)} />
        <div id="quote-instrument" className="instrument">
          {instrument}
          {promise ? <p className="promise">{promise}</p> : null}
        </div>
        <RatesBoard site={site} holdOn={hold.on} />
      </section>
    </div>
  );
}
