import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
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
            <details key={i}>
              <summary>{p[0]}</summary>
              <p>{p[1]}</p>
            </details>
          );
        })}
      </div>
      <CtaBand whatsapp={site.company.whatsapp} />
    </section>
  );
}
