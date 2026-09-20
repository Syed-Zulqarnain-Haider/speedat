import type { Metadata } from "next";
import { CARD_ICON_ORDER, CardIcons, isCardIcon } from "@/components/Icons";
import { CtaBand } from "@/components/site/CtaBand";
import { ServiceCard } from "@/components/site/ServiceCard";
import { lines, parts } from "@/lib/pricing/engine";
import { getLiveSite } from "@/lib/site/live";

export const metadata: Metadata = { title: "Services" };

export default async function ServicesPage() {
  const site = await getLiveSite();
  const items = lines(site.content.services);
  const where = site.destinations
    .filter((x) => x.active)
    .map((x) => x.name)
    .sort((a, b) => a.localeCompare(b));
  return (
    <section className="page">
      <h1>Services</h1>
      <p className="lede">
        Every service is door to door: we collect from you and deliver to the receiver. Prices are on the quote page.
      </p>
      <div className="cards three">
        {items.map((ln, i) => {
          const p = parts(ln, 3);
          const key = p[0].toLowerCase();
          const named = isCardIcon(key);
          const iconName = named ? key : CARD_ICON_ORDER[i % CARD_ICON_ORDER.length];
          const title = named ? p[1] : p[0];
          const desc = named ? p[2] : p[1] || p[2];
          const Icon = CardIcons[iconName];
          return (
            <ServiceCard key={i} index={i}>
              <Icon />
              <h3>{title}</h3>
              <p>{desc}</p>
            </ServiceCard>
          );
        })}
      </div>
      <h2>Where we deliver</h2>
      <p className="prose">{where.join(" · ")}</p>
      <CtaBand whatsapp={site.company.whatsapp} />
    </section>
  );
}
