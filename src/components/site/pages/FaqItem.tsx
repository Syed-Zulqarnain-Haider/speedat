"use client";

/**
 * One FAQ entry: a native `<details>` (works without JavaScript, opens with
 * Enter/Space on the focused summary, and its state is the browser's own)
 * with a 56px summary row — the question and a plus that turns into a
 * minus — enhanced after mount so the answer folds open and shut instead
 * of popping. The fold is a Web Animations API tween on the answer's
 * height (no runtime styles, so the CSP is untouched); a click mid-fold
 * reverses it from where it is. With reduced motion, or where `animate` is
 * missing, the click is left to the browser and the answer just toggles.
 */
import { useRef, type MouseEvent, type ReactNode } from "react";
import { useReducedMotion } from "@/lib/client/motion";

const EASE = "cubic-bezier(0.22, 1, 0.36, 1)";

interface Props {
  question: string;
  children: ReactNode;
}

export function FaqItem({ question, children }: Props) {
  const ref = useRef<HTMLDetailsElement>(null);
  const bodyRef = useRef<HTMLDivElement>(null);
  const animRef = useRef<Animation | null>(null);
  const openingRef = useRef(false);
  const reduced = useReducedMotion();

  const onClick = (e: MouseEvent<HTMLElement>) => {
    const d = ref.current;
    const body = bodyRef.current;
    if (!d || !body || reduced || typeof body.animate !== "function") return; // native toggle
    e.preventDefault();
    const running = animRef.current;
    // Interrupted: continue from the current height and opacity, in the other direction.
    const curH = running ? body.getBoundingClientRect().height : null;
    const curO = running ? Number(getComputedStyle(body).opacity) : null;
    running?.cancel();
    const opening = running ? !openingRef.current : !d.open;
    openingRef.current = opening;
    d.open = true; // the answer must be rendered to be measured and to move
    const full = body.offsetHeight;
    const a = body.animate(
      [
        { height: `${curH ?? (opening ? 0 : full)}px`, opacity: curO ?? (opening ? 0 : 1) },
        { height: `${opening ? full : 0}px`, opacity: opening ? 1 : 0 },
      ],
      { duration: opening ? 320 : 240, easing: EASE },
    );
    animRef.current = a;
    a.onfinish = () => {
      if (animRef.current === a) animRef.current = null;
      if (!opening) d.open = false;
    };
    a.oncancel = () => {
      if (animRef.current === a) animRef.current = null;
    };
  };

  return (
    <details ref={ref} className="faq-item">
      <summary onClick={onClick}>
        <span className="faq-q">{question}</span>
        <span className="faq-x" aria-hidden="true" />
      </summary>
      <div ref={bodyRef} className="faq-a">
        {children}
      </div>
    </details>
  );
}
