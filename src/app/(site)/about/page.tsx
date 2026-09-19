import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
import { lines, parts } from "@/lib/pricing/engine";
import { getLiveSite } from "@/lib/site/live";

export const metadata: Metadata = { title: "About us" };

export default async function AboutPage() {
  const site = await getLiveSite();
  const c = site.content;
  const vals = lines(c.values);
  return (
    <section className="page">
      <h1>About {site.company.name}</h1>
      <p className="lede">{site.company.tagline}</p>
      <div className="prose">
        {lines(c.story).map((x, i) => (
          <p key={i}>{x}</p>
        ))}
      </div>
      <div className="two" style={{ marginTop: 22 }}>
        <div className="card accent">
          <h3>Our mission</h3>
          <p>{c.mission}</p>
        </div>
        <div className="card accent blue">
          <h3>Our vision</h3>
          <p>{c.vision}</p>
        </div>
      </div>
      {vals.length ? (
        <>
          <h2>What we stand for</h2>
          <ul className="values">
            {vals.map((ln, i) => {
              const p = parts(ln, 2);
              return (
                <li key={i}>
                  <strong>{p[0]}</strong>
                  <span>{p[1]}</span>
                </li>
              );
            })}
          </ul>
        </>
      ) : null}
      <CtaBand whatsapp={site.company.whatsapp} />
    </section>
  );
}
