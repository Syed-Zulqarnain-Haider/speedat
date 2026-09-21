import { CardIcons, UI } from "@/components/Icons";
import { Accent } from "@/components/site/Accent";
import { Reveal } from "@/components/site/Reveal";
import { lines, parts } from "@/lib/pricing/engine";
import type { Content } from "@/lib/site/types";

/** The same three icons that head the calculator's steps, so the page teaches the calculator before the visitor reaches it. */
const STEP_ICONS = [UI.pin, UI.scale, UI.wa] as const;

/**
 * How it works: `content.stepsTitle` and `content.steps` (`Title | text` per
 * line) as numbered picture cards. Server component. Rendered inside the
 * hero grid (`section.calc`): three cards in a row after the calculator on
 * a phone, a vertical list under the proof points beside it from 1024.
 */
export function Steps({ content }: { content: Pick<Content, "stepsTitle" | "steps"> }) {
  const items = lines(content.steps).map((ln) => parts(ln, 2));
  if (!items.length) return null;
  return (
    <section className="how" aria-labelledby="how-title">
      <h2 id="how-title">
        <Accent text={content.stepsTitle} />
      </h2>
      <ol className="how-list">
        {items.map(([title, text], i) => {
          const Icon = i < STEP_ICONS.length ? STEP_ICONS[i] : CardIcons.box;
          return (
            <li key={i}>
              <Reveal index={i} stagger={0.08} distance={24} threshold={0.02} className="how-item">
                <span className="n" aria-hidden="true">
                  {i + 1}
                </span>
                <span className="how-ico" aria-hidden="true">
                  <Icon />
                </span>
                <h3>{title}</h3>
                {text ? <p>{text}</p> : null}
              </Reveal>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
