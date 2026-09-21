import type { ReactNode } from "react";
import { Accent } from "@/components/site/Accent";

interface Props {
  /** The h2; accepts one `*word*` for the italic accent. */
  title: string;
  /** Optional line under the heading, in the lede style. */
  lede?: string;
  /** `id` for the h2 so a section can be `aria-labelledby` it. */
  id?: string;
  className?: string;
  children?: ReactNode;
  /** Kept for callers from the first round; no eyebrow line renders any more. */
  eyebrow?: string;
}

/** The section title block every inner-page h2 gets: the h2 (→ optional lede), nothing to decode. Server component. */
export function SectionHead({ title, lede, id, className, children }: Props) {
  return (
    <div className={className ? `page-sec ${className}` : "page-sec"}>
      <h2 id={id}>
        <Accent text={title} />
      </h2>
      {lede ? <p className="lede">{lede}</p> : null}
      {children}
    </div>
  );
}
