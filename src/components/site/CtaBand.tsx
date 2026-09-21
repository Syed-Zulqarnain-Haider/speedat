import Link from "next/link";
import { UI } from "@/components/Icons";
import { SEED } from "@/lib/site/seed";
import { Accent } from "./Accent";
import { Pull } from "./fx/Pull";

interface Props {
  /** Digits only with country code. */
  whatsapp: string;
  /** `company.phone` as the owner typed it; adds the "Call …" button when set. */
  phone?: string;
  /** `content.ctaTitle`; accepts one `*word*` for the italic accent. Falls back to the seed default. */
  title?: string;
  /** `content.ctaSub`. Falls back to the seed default. */
  sub?: string;
  /** Adds "Get a price" (→ the calculator) as a third button. Inner pages leave it on; the home page passes false. */
  quoteLink?: boolean;
  /** Kept for callers from the first round; no eyebrow line renders any more. */
  eyebrow?: string;
}

/**
 * The WhatsApp / Call band that closes every page: navy, full bleed, a
 * short heading, one line, and giant buttons — green WhatsApp first, then
 * Call and (on inner pages) Get a price. Pages pass `content.ctaTitle` /
 * `content.ctaSub`. Server component.
 */
export function CtaBand({ whatsapp, phone, title, sub, quoteLink }: Props) {
  const h = title?.trim() || SEED.content.ctaTitle;
  const s = sub?.trim() || SEED.content.ctaSub;
  const tel = phone ? `tel:${phone.replace(/[^0-9+]/g, "")}` : "";
  return (
    <section className="cta-band bleed on-navy" aria-labelledby="cta-title">
      <div className="wrap cta-grid">
        <div className="cta-copy">
          <h2 id="cta-title">
            <Accent text={h} />
          </h2>
          {s ? <p>{s}</p> : null}
        </div>
        <div className="cta-actions">
          <Pull>
            <a className="btn wa giant" href={`https://wa.me/${whatsapp}`} target="_blank" rel="noopener">
              <UI.wa />
              WhatsApp us
            </a>
          </Pull>
          {tel ? (
            <a className="btn giant paper" href={tel}>
              <UI.phone />
              Call {phone}
            </a>
          ) : null}
          {quoteLink !== false ? (
            <Link className="btn giant paper" href="/#quote-instrument">
              Get a price
            </Link>
          ) : null}
        </div>
      </div>
    </section>
  );
}
