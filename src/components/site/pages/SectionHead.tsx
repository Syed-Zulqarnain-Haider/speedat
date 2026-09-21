import type { ReactNode } from "react";
import { Accent } from "@/components/site/Accent";

interface Props {
  /** The mono eyebrow above the rule, e.g. "Visit us" or "05 — Book". */
  eyebrow: string;
  /** The h2; accepts one `*word*` for the italic accent. */
  title: string;
  /** Optional line under the heading, in the lede style. */
  lede?: string;
  /** `id` for the h2 so a section can be `aria-labelledby` it. */
  id?: string;
  className?: string;
  children?: ReactNode;
}

/** The section title block every inner-page h2 gets: eyebrow → rule → h2 (→ lede). Server component. */
export function SectionHead({ eyebrow, title, lede, id, className, children }: Props) {
  return (
    <div className={className ? `page-sec ${className}` : "page-sec"}>
      <p className="eyebrow">{eyebrow}</p>
      <span className="rule" aria-hidden="true" />
      <h2 id={id}>
        <Accent text={title} />
      </h2>
      {lede ? <p className="lede">{lede}</p> : null}
      {children}
    </div>
  );
}
