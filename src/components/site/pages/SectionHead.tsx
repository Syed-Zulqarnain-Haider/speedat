import type { ReactNode } from "react";

interface Props {
  /** The h2, printed exactly as given. */
  title: string;
  /** Optional line under the heading, in the lede style. */
  lede?: string;
  /** `id` for the h2 so a block can be `aria-labelledby` it. */
  id?: string;
  className?: string;
  children?: ReactNode;
  /** Kept for callers from the first round; no eyebrow line renders any more. */
  eyebrow?: string;
}

/**
 * A section of an inner page: a hairline, 32px, the plain h2, then whatever
 * follows (brief v3 §3 — sections are separated by a rule, never a band).
 * Server component.
 */
export function SectionHead({ title, lede, id, className, children }: Props) {
  return (
    <div className={className ? `page-sec ${className}` : "page-sec"}>
      <h2 id={id}>{title}</h2>
      {lede ? <p className="lede">{lede}</p> : null}
      {children}
    </div>
  );
}
