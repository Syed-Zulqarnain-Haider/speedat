import { UI } from "@/components/Icons";
import { fmtPhone } from "@/lib/pricing/format";

interface Props {
  /** Digits only with country code. */
  whatsapp: string;
  /** `company.phone` as the owner typed it; adds the Call link and button when set. */
  phone?: string;
  /** `content.hours`; joins the line when set. */
  hours?: string;
  /** `content.ctaTitle`; an h2 above the line only when the owner set one. */
  title?: string;
  /** `content.ctaSub`; a sentence above the line only when the owner set one. */
  sub?: string;
  /**
   * Whether the numbers line renders (default true). /contact passes false:
   * it states each number once above the form, so under it the buttons go
   * alone (QA round 3 — the same number four times in two screens read as a
   * template).
   */
  showLine?: boolean;
  /** Kept for callers from earlier rounds; no third button renders any more. */
  quoteLink?: boolean;
  /** Kept for callers from earlier rounds; no eyebrow line renders any more. */
  eyebrow?: string;
}

/**
 * The contact line that closes every inner page (brief v3 §3): the two
 * numbers as plain links and the hours on one line, then a WhatsApp button
 * and, when there is a landline, a Call button. A hairline above, no band,
 * nothing centred. The file keeps its old name so the pages' imports hold.
 * Server component.
 */
export function CtaBand({ whatsapp, phone, hours, title, sub, showLine = true }: Props) {
  const h = title?.trim();
  const s = sub?.trim();
  const tel = phone ? `tel:${phone.replace(/[^0-9+]/g, "")}` : "";
  const wa = `https://wa.me/${whatsapp}`;
  return (
    <div className="reach">
      {h ? <h2>{h}</h2> : null}
      {s ? <p className="reach-sub">{s}</p> : null}
      {showLine ? (
        <p className="reach-line">
          WhatsApp{" "}
          <a href={wa} target="_blank" rel="noopener">
            {fmtPhone(whatsapp)}
          </a>
          {tel ? (
            <>
              {" · "}Call <a href={tel}>{phone}</a>
            </>
          ) : null}
          {hours ? ` · ${hours}` : null}
        </p>
      ) : null}
      <div className="reach-actions">
        <a className="btn wa big" href={wa} target="_blank" rel="noopener">
          <UI.wa />
          WhatsApp us
        </a>
        {tel ? (
          <a className="btn outline big" href={tel}>
            <UI.phone />
            Call
          </a>
        ) : null}
      </div>
    </div>
  );
}
