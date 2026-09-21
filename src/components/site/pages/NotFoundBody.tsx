import Link from "next/link";
import type { ReactNode } from "react";
import { UI } from "@/components/Icons";
import { PageHead } from "@/components/site/PageHead";

/** The words on the 404, shared by the site's not-found page and the root one so the two never drift. */
export const NOT_FOUND = {
  title: "That page is not *here*",
  lede: "The link may be old. Get your price on the home page, or ask us on WhatsApp.",
} as const;

interface Props {
  /** The big orange figure above the heading, e.g. "404"; leave out on the error page. */
  code?: string;
  /** The h1; accepts one `*word*` for the italic accent. */
  title: string;
  lede?: string;
  /** Digits-only WhatsApp number; adds the green "WhatsApp us" button when given. */
  whatsapp?: string;
  /** Replaces the default "Get a price" link (the error page passes its "Try again" button). */
  children?: ReactNode;
}

/**
 * The body of the 404 and error pages: a big code, the page head, and two
 * big buttons — one back to the calculator, one to WhatsApp. No hooks, so
 * both the server-rendered not-found pages and the client error page can
 * render it.
 */
export function NotFoundBody({ code, title, lede, whatsapp, children }: Props) {
  return (
    <section className="page nf">
      {code ? <p className="nf-code">{code}</p> : null}
      <PageHead title={title} lede={lede} />
      <div className="nf-actions">
        {children ?? (
          <Link className="btn primary big" href="/">
            Get a price
          </Link>
        )}
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
