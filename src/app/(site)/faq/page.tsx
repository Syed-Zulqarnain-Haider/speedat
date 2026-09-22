import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { lines, parts } from "@/lib/pricing/engine";
import { getLiveSite } from "@/lib/site/live";

export const metadata: Metadata = { title: "Questions" };

/**
 * Questions (brief v3 §3): `content.faq` (`Question? | Answer` per line)
 * as one list with every answer open — nothing to tap to read, nothing
 * that folds — a hairline after each pair, then the contact line.
 */
export default async function FaqPage() {
  const site = await getLiveSite();
  const c = site.content;
  const co = site.company;
  const items = lines(c.faq).map((ln) => parts(ln, 2));
  return (
    <>
      <section className="page">
        <PageHead title="Questions" lede={c.faqLede} />
        {items.length ? (
          <dl className="faq-list">
            {items.map(([q, a], i) => (
              <div key={i}>
                <dt>{q}</dt>
                <dd>{a}</dd>
              </div>
            ))}
          </dl>
        ) : null}
      </section>
      <CtaBand whatsapp={co.whatsapp} phone={co.phone} hours={c.hours} title={c.ctaTitle} sub={c.ctaSub} />
    </>
  );
}
