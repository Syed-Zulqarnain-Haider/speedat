import type { Metadata } from "next";
import Link from "next/link";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { RatesBoard } from "@/components/site/home/RatesBoard";
import { ServiceList } from "@/components/site/pages/ServiceList";
import { fmtHour } from "@/lib/pricing/format";
import { getLiveHold, getLiveSite } from "@/lib/site/live";
import { originCities } from "@/lib/site/text";

export const metadata: Metadata = { title: "Services" };

/**
 * Services (brief v3 §3): the h1 and its line, every `content.services`
 * line as a row of a plain list, one paragraph of facts the page computes
 * from the settings (cities, cutoff, the document and parcel limits), then
 * the rates board in the same 720px column as the list (`.page-board`,
 * site.css PAGES: at the full wrap width a country sat ~900px from its
 * prices), then the contact line. No cards, no icons, nothing that moves.
 */
export default async function ServicesPage() {
  const [site, hold] = await Promise.all([getLiveSite(), getLiveHold()]);
  const c = site.content;
  const co = site.company;
  const s = site.settings;
  const cities = originCities(co);
  const cutoff = s.cutoffHour != null ? fmtHour(s.cutoffHour) : "";
  const hasFacts = cities.length > 0 || Boolean(cutoff) || s.docMaxKg > 0 || s.maxKg > 0;
  return (
    <>
      <section className="page">
        <PageHead title="Services" lede={c.servicesLede} />
        <ServiceList services={c.services} />
        {hasFacts ? (
          <p className="facts">
            {cities.length ? <>Pickup in {cities.join(" and ")}. </> : null}
            {cutoff ? <>Book before {cutoff} for same-day pickup. </> : null}
            {s.docMaxKg > 0 ? <>Documents up to {s.docMaxKg} kg. </> : null}
            {s.maxKg > 0 ? (
              <>
                Parcels up to {s.maxKg} kg priced on the <Link href="/">home page</Link>; heavier by quote.
              </>
            ) : null}
          </p>
        ) : null}
      </section>
      <div className="page-board">
        <RatesBoard site={site} holdOn={hold.on} />
      </div>
      <CtaBand whatsapp={co.whatsapp} phone={co.phone} hours={c.hours} title={c.ctaTitle} sub={c.ctaSub} />
    </>
  );
}
