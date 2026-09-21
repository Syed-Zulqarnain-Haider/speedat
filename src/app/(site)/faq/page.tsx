import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { Reveal } from "@/components/site/Reveal";
import { FaqItem } from "@/components/site/pages/FaqItem";
import { lines, parts } from "@/lib/pricing/engine";
import { getLiveSite } from "@/lib/site/live";

export const metadata: Metadata = { title: "FAQ" };

/** 05 — FAQ: `content.faq` (`Question? | Answer` per line) as a numbered editorial index of native details. */
export default async function FaqPage() {
  const site = await getLiveSite();
  const c = site.content;
  const items = lines(c.faq).map((ln) => parts(ln, 2));
  return (
    <>
      <section className="page">
        <PageHead no="05" name="FAQ" title="Questions people *ask*" lede={c.faqLede} />
        {items.length ? (
          <div className="faq">
            {items.map(([q, a], i) => (
              <Reveal key={i} index={i} stagger={0.05} cap={6} distance={20}>
                <FaqItem no={String(i + 1).padStart(2, "0")} question={q}>
                  <p>{a}</p>
                </FaqItem>
              </Reveal>
            ))}
          </div>
        ) : null}
      </section>
      <CtaBand whatsapp={site.company.whatsapp} title={c.ctaTitle} sub={c.ctaSub} eyebrow="Book" />
    </>
  );
}
