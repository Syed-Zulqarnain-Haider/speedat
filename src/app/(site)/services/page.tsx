import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { RouteBoard } from "@/components/site/home/RouteBoard";
import { ServiceGrid } from "@/components/site/pages/ServiceGrid";
import { getLiveHold, getLiveSite } from "@/lib/site/live";

export const metadata: Metadata = { title: "Services" };

/** 02 — Services: every `content.services` card, then the route board (linking back to the instrument), then the stamp band. */
export default async function ServicesPage() {
  const [site, hold] = await Promise.all([getLiveSite(), getLiveHold()]);
  const c = site.content;
  return (
    <>
      <section className="page">
        <PageHead no="02" name="Services" title="Services" lede={c.servicesLede} />
        <ServiceGrid services={c.services} />
        <RouteBoard site={site} holdOn={hold.on} eyebrow="Where we deliver" href="/#quote-instrument" />
      </section>
      <CtaBand whatsapp={site.company.whatsapp} title={c.ctaTitle} sub={c.ctaSub} eyebrow="Book" />
    </>
  );
}
