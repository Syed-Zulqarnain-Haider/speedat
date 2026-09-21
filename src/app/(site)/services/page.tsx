import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { RouteBoard } from "@/components/site/home/RouteBoard";
import { ServiceGrid } from "@/components/site/pages/ServiceGrid";
import { getLiveHold, getLiveSite } from "@/lib/site/live";

export const metadata: Metadata = { title: "Services" };

/** Services: every `content.services` card, the flag grid of where we deliver (each tile leads back to the calculator), the WhatsApp / Call band. */
export default async function ServicesPage() {
  const [site, hold] = await Promise.all([getLiveSite(), getLiveHold()]);
  const c = site.content;
  const co = site.company;
  return (
    <>
      <section className="page">
        <PageHead title="Services" lede={c.servicesLede} />
        <ServiceGrid services={c.services} />
        <RouteBoard site={site} holdOn={hold.on} href="/#quote-instrument" />
      </section>
      <CtaBand whatsapp={co.whatsapp} phone={co.phone} title={c.ctaTitle} sub={c.ctaSub} />
    </>
  );
}
