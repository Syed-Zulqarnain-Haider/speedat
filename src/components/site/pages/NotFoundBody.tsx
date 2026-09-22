import Link from "next/link";
import type { ReactNode } from "react";
import { UI } from "@/components/Icons";
import { PageHead } from "@/components/site/PageHead";

/** The words on the 404, shared by the site's not-found page and the root one so the two never drift. */
export const NOT_FOUND = {
  title: "Page not found",
  line: "Nothing is at this address.",
} as const;

interface Props {
  /** The h1, printed exactly as given. */
  title: string;
  /** The one line under it. */
  line?: string;
  /** Digits-only WhatsApp number; adds the green "WhatsApp us" button when given. */
  whatsapp?: string;
  /** An action that goes before "Get a price" (the error page passes its "Try again" button), which then steps down to an outline. */
  children?: ReactNode;
}

/**
 * The body of the 404 and error pages (brief v3 §3): the heading, one
 * line, two big buttons — the calculator and WhatsApp — and nothing to
 * decode: no code figure, no accent, nothing centred. No hooks, so both the
 * server-rendered not-found page and the client error page can render it.
 */
export function NotFoundBody({ title, line, whatsapp, children }: Props) {
  return (
    <section className="page nf">
      <PageHead title={title} lede={line} />
      <div className="nf-actions">
        {children}
        <Link className={children ? "btn outline big" : "btn primary big"} href="/">
          Get a price
        </Link>
        {whatsapp ? (
          <a className="btn wa big" href={`https://wa.me/${whatsapp}`} target="_blank" rel="noopener">
            <UI.wa />
            WhatsApp us
          </a>
        ) : null}
      </div>
    </section>
  );
}
