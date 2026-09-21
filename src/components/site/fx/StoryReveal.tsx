"use client";

/**
 * The first paragraph of the About story, its words sharpening in as the
 * visitor scrolls. Both branches show identical text: the server and
 * reduced-motion branch is a plain `.story-first` paragraph (whose
 * `::first-letter` is the drop cap); the animated branch sets the drop cap
 * explicitly (split words are inline-blocks, which `::first-letter` cannot
 * reach), hides that visual from assistive tech and carries the text once
 * more in a visually hidden paragraph.
 */
import ScrollReveal from "@/components/bits/ScrollReveal";
import { useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";

export function StoryReveal({ text }: { text: string }) {
  const mounted = useMounted();
  const reduced = useReducedMotion();
  if (!mounted || reduced || text.length < 2) return <p className="story-first">{text}</p>;
  return (
    <>
      <div className="story-first" aria-hidden="true">
        <span className="drop">{text[0]}</span>
        <ScrollReveal baseOpacity={0.15} baseRotation={2} enableBlur blurStrength={6} textClassName="story-first">
          {text.slice(1)}
        </ScrollReveal>
      </div>
      <p className="sr">{text}</p>
    </>
  );
}
