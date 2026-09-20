import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
import { Reveal } from "@/components/site/Reveal";
import { lines, parts } from "@/lib/pricing/engine";
import { getLiveSite } from "@/lib/site/live";

export const metadata: Metadata = { title: "FAQ" };

export default async function FaqPage() {
  const site = await getLiveSite();
  const items = lines(site.content.faq);
  return (
    <section className="page">
      <h1>Questions people ask</h1>
      <p className="lede">If yours is not here, ask us on WhatsApp.</p>
      <div className="faq">
        {items.map((ln, i) => {
          const p = parts(ln, 2);
          return (
            <Reveal key={i} delay={Math.min(i, 6) * 0.05} distance={20}>
              <details>
                <summary>{p[0]}</summary>
                <p>{p[1]}</p>
              </details>
            </Reveal>
          );
        })}
      </div>
      <CtaBand whatsapp={site.company.whatsapp} />
    </section>
  );
}
