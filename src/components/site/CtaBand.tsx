import Link from "next/link";
import { UI } from "@/components/Icons";
import { SEED } from "@/lib/site/seed";
import { Accent } from "./Accent";
import { Pull } from "./fx/Pull";

interface Props {
  whatsapp: string;
  /** `content.ctaTitle`; accepts one `*word*` for the italic accent. Falls back to the seed default. */
  title?: string;
  /** `content.ctaSub`. Falls back to the seed default. */
  sub?: string;
  /** The mono eyebrow over the heading: "05 — Book" on the home page (its fifth section), "Book" on inner pages. */
  eyebrow?: string;
}

/** 05 — the stamp band: navy, full bleed, the two ways to start. Pages pass `content.ctaTitle` / `content.ctaSub`. */
export function CtaBand({ whatsapp, title, sub, eyebrow = "05 — Book" }: Props) {
  const h = title?.trim() || SEED.content.ctaTitle;
  const s = sub?.trim() || SEED.content.ctaSub;
  return (
    <section className="cta-band bleed on-navy" aria-labelledby="cta-title">
      <div className="wrap cta-grid">
        <div className="cta-copy">
          <p className="eyebrow">{eyebrow}</p>
          <span className="rule" aria-hidden="true" />
          <h2 id="cta-title">
            <Accent text={h} />
          </h2>
          {s ? <p>{s}</p> : null}
        </div>
        <div className="cta-actions">
          <Link className="btn big paper" href="/#quote-instrument">
            Get a quote
          </Link>
          <Pull>
            <a className="btn wa big" href={`https://wa.me/${whatsapp}`} target="_blank" rel="noopener">
              <UI.wa />
              WhatsApp us
            </a>
          </Pull>
        </div>
        <span className="mark stamp-mark" aria-hidden="true" />
      </div>
    </section>
  );
}
