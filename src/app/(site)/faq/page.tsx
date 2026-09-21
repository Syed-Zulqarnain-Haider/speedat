import type { Metadata } from "next";
import { UI } from "@/components/Icons";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { Reveal } from "@/components/site/Reveal";
import { FaqItem } from "@/components/site/pages/FaqItem";
import { lines, parts } from "@/lib/pricing/engine";
import { getLiveSite } from "@/lib/site/live";

export const metadata: Metadata = { title: "FAQ" };

/**
 * FAQ: `content.faq` (`Question? | Answer` per line) as a simple accordion of
 * native details with 56px rows, beside a card that turns `faqLede` ("If
 * yours is not here, ask us on WhatsApp.") into a giant green button; then
 * the WhatsApp / Call band.
 */
export default async function FaqPage() {
  const site = await getLiveSite();
  const c = site.content;
  const co = site.company;
  const items = lines(c.faq).map((ln) => parts(ln, 2));
  return (
    <>
      <section className="page">
        <PageHead title="Questions people *ask*" />
        <div className="faq-grid">
          {items.length ? (
            <div className="faq">
              {items.map(([q, a], i) => (
                <Reveal key={i} index={i} stagger={0.05} cap={6} distance={16}>
                  <FaqItem question={q}>
                    <p>{a}</p>
                  </FaqItem>
                </Reveal>
              ))}
            </div>
          ) : null}
          <aside className="card faq-ask" aria-label="Ask us">
            <span className="card-ico" aria-hidden="true">
              <UI.wa />
            </span>
            {c.faqLede ? <p>{c.faqLede}</p> : null}
            <a className="btn wa giant" href={`https://wa.me/${co.whatsapp}`} target="_blank" rel="noopener">
              <UI.wa />
              Ask on WhatsApp
            </a>
          </aside>
        </div>
      </section>
      <CtaBand whatsapp={co.whatsapp} phone={co.phone} title={c.ctaTitle} sub={c.ctaSub} />
    </>
  );
}
