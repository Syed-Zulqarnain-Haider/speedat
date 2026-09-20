import type { Metadata } from "next";
import { Calculator } from "@/components/calculator/Calculator";
import { Hero, type HeroStat } from "@/components/site/Hero";
import { HoldPanel } from "@/components/site/HoldPanel";
import { parseDaysRange } from "@/lib/pricing/dates";
import { lines, parts } from "@/lib/pricing/engine";
import { getLiveHold, getLiveSite } from "@/lib/site/live";
import { originCities } from "@/lib/site/text";
import type { PublishedVersion } from "@/lib/site/types";

export const metadata: Metadata = { title: "Get a quote" };

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
  if (cities.length) out.push({ value: cities.length, text: String(cities.length), label: `pickup ${cities.length === 1 ? "city" : "cities"}` });
  return out;
}

export default async function QuotePage() {
  const [site, hold] = await Promise.all([getLiveSite(), getLiveHold()]);
  if (hold.on) {
    // On hold: the rate document stays on the server; the browser gets names, the message and a WhatsApp form.
    const destinations = site.destinations
      .filter((x) => x.active)
      .map((x) => ({ id: x.id, name: x.name }))
      .sort((a, b) => a.name.localeCompare(b.name));
    return (
      <section className="calc">
        <Hero title={site.content.heroTitle} sub={site.content.heroSub} stats={heroStats(site)} />
        <HoldPanel companyName={site.company.name} whatsapp={site.company.whatsapp} message={hold.message} destinations={destinations} maxKg={site.settings.maxKg} />
      </section>
    );
  }
  return (
    <section className="calc">
      {site.live ? null : <p className="sample">Sample rates shown for demonstration. Prices will be confirmed on WhatsApp.</p>}
      <Hero title={site.content.heroTitle} sub={site.content.heroSub} stats={heroStats(site)} />
      <Calculator site={site} />
    </section>
  );
}
