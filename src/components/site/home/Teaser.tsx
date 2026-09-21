import Link from "next/link";
import { CARD_ICON_ORDER, CardIcons, isCardIcon } from "@/components/Icons";
import { Accent } from "@/components/site/Accent";
import { ServiceCard } from "@/components/site/ServiceCard";
import { lines, parts } from "@/lib/pricing/engine";
import type { Content } from "@/lib/site/types";

/** Two ways to send: the first two `content.services` lines, parsed exactly as /services does, and one button to the rest. Server component. */
export function Teaser({ content }: { content: Pick<Content, "servicesTitle" | "services"> }) {
  const items = lines(content.services).slice(0, 2);
  if (!items.length) return null;
  return (
    <section className="services-home" aria-labelledby="services-title">
      <h2 id="services-title">
        <Accent text={content.servicesTitle} />
      </h2>
      <div className="cards services-two">
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
      <Link className="btn outline big services-all" href="/services">
        All services
      </Link>
    </section>
  );
}
