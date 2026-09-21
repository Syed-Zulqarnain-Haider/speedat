import Link from "next/link";
import { CARD_ICON_ORDER, CardIcons, isCardIcon } from "@/components/Icons";
import { Accent } from "@/components/site/Accent";
import { ServiceCard } from "@/components/site/ServiceCard";
import { lines, parts } from "@/lib/pricing/engine";
import type { Content } from "@/lib/site/types";

/** 04 — services teaser: the first three `content.services` lines, parsed exactly as /services does. Server component. */
export function Teaser({ content }: { content: Pick<Content, "servicesTitle" | "services"> }) {
  const items = lines(content.services).slice(0, 3);
  if (!items.length) return null;
  return (
    <section className="teaser" aria-labelledby="teaser-title">
      <div className="teaser-head">
        <p className="eyebrow">04 — Services</p>
        <span className="rule" aria-hidden="true" />
        <h2 id="teaser-title">
          <Accent text={content.servicesTitle} />
        </h2>
      </div>
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
              <span className="rule hot" aria-hidden="true" />
              <h3>{title}</h3>
              <p>{desc}</p>
            </ServiceCard>
          );
        })}
      </div>
      <p className="teaser-more">
        <Link className="link" href="/services">
          All services →
        </Link>
      </p>
    </section>
  );
}
