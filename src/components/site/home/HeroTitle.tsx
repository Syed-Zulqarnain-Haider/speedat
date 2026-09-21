import { Fragment, type CSSProperties } from "react";
import { accentWords } from "@/lib/site/accent";

/**
 * The home h1 with each word in its own span so the words can rise in from
 * first paint with CSS alone (no JS, no hydration flash). Server component;
 * `--i` staggers the entrance and is capped so long headlines finish together.
 * A literal space follows every word but the last so lines can wrap between words.
 */
export function HeroTitle({ text }: { text: string }) {
  const words = accentWords(text);
  return (
    <h1 className="hero-title">
      {words.map((w, i) => (
        <Fragment key={i}>
          <span className="w" style={{ "--i": Math.min(i, 12) } as CSSProperties}>
            {w.head}
            {w.accent ? <em className="accent">{w.text}</em> : w.text}
            {w.tail}
          </span>
          {i < words.length - 1 ? " " : null}
        </Fragment>
      ))}
    </h1>
  );
}
