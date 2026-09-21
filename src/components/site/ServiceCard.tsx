import type { ReactNode } from "react";
import { Reveal } from "@/components/site/Reveal";

/**
 * A service card (home teaser and /services): the plain `.card` that slides
 * in as it scrolls into view, staggered by its position. The server, the
 * hydration pass and reduced motion all paint the same markup. No pointer
 * effects — a card is something to read, not to play with.
 */
export function ServiceCard({ index, children }: { index: number; children: ReactNode }) {
  return (
    <Reveal index={index} stagger={0.06} cap={5} distance={24}>
      <div className="card">{children}</div>
    </Reveal>
  );
}
