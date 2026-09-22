import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { SectionHead } from "@/components/site/pages/SectionHead";
import { lines, parts } from "@/lib/pricing/engine";
import { getLiveSite } from "@/lib/site/live";
import { cutoffFact, originCities } from "@/lib/site/text";

export const metadata: Metadata = { title: "About us" };

/** "Honesty." — a value's name as the start of its sentence; a name that already ends in punctuation is left alone. */
function lead(name: string): string {
  return /[.!?:]$/.test(name) ? name : `${name}.`;
}

/**
 * About (brief v3 §3): the story as plain paragraphs, then the facts as a
 * list of rows — office, pickup cities, hours, cutoff — every one from the
 * company, content and settings fields. The two numbers are NOT rows here:
 * the contact line under the list and the footer already print them, and a
 * third copy 200px above the second was one of the restatements the owner
 * read as a template (QA, round 1). For the same reason the contact line
 * on this page goes without the hours, which sit in the row just above it.
 * Mission, vision and values are plain h2 + paragraphs, and only when the
 * owner wrote them. Then the contact line. No cards, no numerals, no split.
 */
export default async function AboutPage() {
  const site = await getLiveSite();
  const c = site.content;
  const co = site.company;
  const story = lines(c.story);
  const vals = lines(c.values).map((ln) => parts(ln, 2));
  const cities = originCities(co);
  const cutoff = cutoffFact(site.settings.cutoffHour);
  const mapHref = c.mapUrl || (c.address ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(c.address)}` : "");
  const hasFacts = Boolean(c.address || cities.length || c.hours || cutoff);
  return (
    <>
      <section className="page">
        <PageHead title={`About ${co.name.trim()}`} />
        {story.length ? (
          <div className="story">
            {story.map((x, i) => (
              <p key={i}>{x}</p>
            ))}
          </div>
        ) : null}
        {hasFacts ? (
          <dl className="facts">
            {c.address ? (
              <div>
                <dt>Office</dt>
                <dd>
                  {c.address}
                  {mapHref ? (
                    <>
                      {" · "}
                      <a href={mapHref} target="_blank" rel="noopener">
                        Google Maps
                      </a>
                    </>
                  ) : null}
                </dd>
              </div>
            ) : null}
            {cities.length ? (
              <div>
                <dt>Pickup</dt>
                <dd>{cities.join(" and ")}</dd>
              </div>
            ) : null}
            {c.hours ? (
              <div>
                <dt>Hours</dt>
                <dd>{c.hours}</dd>
              </div>
            ) : null}
            {cutoff ? (
              <div>
                <dt>Cutoff</dt>
                <dd>{cutoff}</dd>
              </div>
            ) : null}
          </dl>
        ) : null}
        {c.mission.trim() ? (
          <>
            <SectionHead title="Mission" id="mission-title" />
            <p className="prose">{c.mission.trim()}</p>
          </>
        ) : null}
        {c.vision.trim() ? (
          <>
            <SectionHead title="Vision" id="vision-title" />
            <p className="prose">{c.vision.trim()}</p>
          </>
        ) : null}
        {vals.length ? (
          <>
            <SectionHead title="Values" id="values-title" />
            {vals.map(([name, text], i) => (
              <p key={i} className="prose">
                {name ? <strong>{text ? lead(name) : name}</strong> : null}
                {text ? <> {text}</> : null}
              </p>
            ))}
          </>
        ) : null}
      </section>
      <CtaBand whatsapp={co.whatsapp} phone={co.phone} title={c.ctaTitle} sub={c.ctaSub} />
    </>
  );
}
