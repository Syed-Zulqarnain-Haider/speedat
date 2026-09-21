import { Accent } from "@/components/site/Accent";
import { Reveal } from "@/components/site/Reveal";
import { lines, parts } from "@/lib/pricing/engine";
import type { Content } from "@/lib/site/types";

/** 03 — how it works: `content.stepsTitle` and `content.steps` (`Title | text` per line). Server component. */
export function Steps({ content }: { content: Pick<Content, "stepsTitle" | "steps"> }) {
  const items = lines(content.steps).map((ln) => parts(ln, 2));
  if (!items.length) return null;
  return (
    <section className="steps-section" aria-labelledby="steps-title">
      <div className="steps-head">
        <p className="eyebrow">03 — How it works</p>
        <span className="rule" aria-hidden="true" />
        <h2 id="steps-title">
          <Accent text={content.stepsTitle} />
        </h2>
      </div>
      <ol className="steps">
        {items.map(([title, text], i) => (
          <li key={i}>
            <Reveal index={i} stagger={0.08} distance={24} className="step-body">
              <span className="numeral" aria-hidden="true">
                {String(i + 1).padStart(2, "0")}
              </span>
              <h3>{title}</h3>
              {text ? <p>{text}</p> : null}
            </Reveal>
          </li>
        ))}
      </ol>
    </section>
  );
}
