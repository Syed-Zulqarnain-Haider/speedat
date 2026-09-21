import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { Reveal } from "@/components/site/Reveal";
import { SectionHead } from "@/components/site/pages/SectionHead";
import { lines, parts } from "@/lib/pricing/engine";
import { getLiveSite } from "@/lib/site/live";
import { originCities } from "@/lib/site/text";

export const metadata: Metadata = { title: "About us" };

/** "About Speedat International Courier" with the first word of the name as the italic accent (a name with its own asterisk prints as typed). */
function aboutTitle(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length < 2 || name.includes("*")) return `About ${name.trim()}`;
  return `About *${words[0]}* ${words.slice(1).join(" ")}`;
}

/** About: the story in plain paragraphs beside its heading, mission and vision as two cards, the values as numbered rows, the WhatsApp / Call band. */
export default async function AboutPage() {
  const site = await getLiveSite();
  const c = site.content;
  const co = site.company;
  const story = lines(c.story);
  const vals = lines(c.values).map((ln) => parts(ln, 2));
  const cities = originCities(co);
  const from = cities.length ? `${cities.join(" · ")}${co.origin ? ` — ${co.origin}` : ""}` : co.origin;
  return (
    <>
      <section className="page">
        <PageHead title={aboutTitle(co.name)} lede={co.tagline} />
        {story.length ? (
          <div className="story page-split">
            <div className="page-split-side">
              <h2 id="story-title">Our story</h2>
              {from ? <p className="from">From {from}</p> : null}
            </div>
            <div className="story-body page-split-body">
              {story.map((x, i) => (
                <p key={i}>{x}</p>
              ))}
            </div>
          </div>
        ) : null}
        {c.mission || c.vision ? (
          <div className="mv">
            {c.mission ? (
              <Reveal delay={0.05} distance={24}>
                <div className="card">
                  <h3>Mission</h3>
                  <p className="mv-text">{c.mission}</p>
                </div>
              </Reveal>
            ) : null}
            {c.vision ? (
              <Reveal delay={0.1} distance={24}>
                <div className="card">
                  <h3>Vision</h3>
                  <p className="mv-text">{c.vision}</p>
                </div>
              </Reveal>
            ) : null}
          </div>
        ) : null}
        {vals.length ? (
          <>
            <SectionHead title="What we stand for" id="values-title" />
            <ol className="values" aria-labelledby="values-title">
              {vals.map(([name, text], i) => (
                <li key={i}>
                  <Reveal className="values-row" index={i} stagger={0.06} distance={20}>
                    <span className="n" aria-hidden="true">
                      {i + 1}
                    </span>
                    <strong>{name}</strong>
                    {text ? <span>{text}</span> : null}
                  </Reveal>
                </li>
              ))}
            </ol>
          </>
        ) : null}
      </section>
      <CtaBand whatsapp={co.whatsapp} phone={co.phone} title={c.ctaTitle} sub={c.ctaSub} />
    </>
  );
}
