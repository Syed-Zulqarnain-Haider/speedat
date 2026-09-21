import { CARD_ICON_ORDER, CardIcons, isCardIcon } from "@/components/Icons";
import { ServiceCard } from "@/components/site/ServiceCard";
import { lines, parts } from "@/lib/pricing/engine";

/**
 * Every `content.services` line (`icon | Title | Text`; a line without a known
 * icon name reads as `Title | Text` and takes the next icon in order) as a
 * card with its index in the corner. Server component; /services renders all
 * of them, the home teaser (HOME) renders the first three the same way.
 */
export function ServiceGrid({ services }: { services: string }) {
  const items = lines(services);
  if (!items.length) return null;
  return (
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
            <span className="card-no" aria-hidden="true">
              {String(i + 1).padStart(2, "0")}
            </span>
            <Icon />
            <span className="rule hot" aria-hidden="true" />
            <h3>{title}</h3>
            {desc ? <p>{desc}</p> : null}
          </ServiceCard>
        );
      })}
    </div>
  );
}
