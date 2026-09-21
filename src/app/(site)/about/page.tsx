import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { Reveal } from "@/components/site/Reveal";
import { StoryReveal } from "@/components/site/fx/StoryReveal";
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

/** 03 — About: the story beside a sticky label, mission and vision, the values as an editorial index, the stamp band. */
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
        <PageHead no="03" name="About" title={aboutTitle(co.name)} lede={co.tagline} />
        {story.length ? (
          <div className="story page-split">
            <div className="page-split-side">
              <p className="eyebrow">Story</p>
              <span className="rule" aria-hidden="true" />
              <h2>Our story</h2>
              {from ? <span className="meta">From {from}</span> : null}
            </div>
            <div className="story-body page-split-body">
              <StoryReveal text={story[0]} />
              {story.slice(1).map((x, i) => (
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
                  <p className="eyebrow">Mission</p>
                  <span className="rule" aria-hidden="true" />
                  <p className="mv-text">{c.mission}</p>
                </div>
              </Reveal>
            ) : null}
            {c.vision ? (
              <Reveal delay={0.15} distance={24}>
                <div className="card">
                  <p className="eyebrow">Vision</p>
                  <span className="rule" aria-hidden="true" />
                  <p className="mv-text">{c.vision}</p>
                </div>
              </Reveal>
            ) : null}
          </div>
        ) : null}
        {vals.length ? (
          <>
            <SectionHead eyebrow="Values" title="What we stand for" id="values-title" />
            <ol className="values" aria-labelledby="values-title">
              {vals.map(([name, text], i) => (
                <li key={i}>
                  <Reveal className="values-row" index={i} stagger={0.06} distance={20}>
                    <span className="numeral" aria-hidden="true">
                      {String(i + 1).padStart(2, "0")}
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
      <CtaBand whatsapp={co.whatsapp} title={c.ctaTitle} sub={c.ctaSub} eyebrow="Book" />
    </>
  );
}
