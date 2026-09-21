"use client";

/**
 * A manifest number that counts up once its cell scrolls into view. The
 * real value is in the DOM from the server render and stays there until the
 * count starts, so the strip never reads "0": reduced motion, a browser
 * without IntersectionObserver, or a cell that never enters the viewport all
 * simply keep the number. The count itself is a short rAF loop driven by
 * React state (no imperative text writes) that starts at the smallest figure
 * with the target's digit count (1 for 4, 10 for 25), so the first frame is
 * already a real figure and the cell's width (tabular digits) never moves.
 */
import { useEffect, useRef, useState } from "react";
import { useReducedMotion } from "@/lib/client/motion";

interface Props {
  to: number;
  /** Milliseconds for the whole count. */
  duration?: number;
}

export function StatCounter({ to, duration = 1400 }: Props) {
  const ref = useRef<HTMLSpanElement>(null);
  const reduced = useReducedMotion();
  const [shown, setShown] = useState<number | null>(null);

  useEffect(() => {
    const el = ref.current;
    if (reduced || !el || !(to > 1) || typeof IntersectionObserver === "undefined") return;
    let raf = 0;
    let settle = 0;
    let started = 0;
    // Start at the smallest figure with the same number of digits (1 for 4, 10 for 25) so the cell's
    // width and the unit beside it never move while the digits roll.
    const from = to < 10 ? 1 : 10 ** (String(Math.floor(to)).length - 1);
    const tick = (now: number) => {
      if (!started) started = now;
      const t = Math.min(1, (now - started) / duration);
      const eased = 1 - (1 - t) ** 3;
      setShown(t < 1 ? Math.round(from + eased * (to - from)) : to);
      if (t < 1) raf = requestAnimationFrame(tick);
      else clearTimeout(settle);
    };
    const io = new IntersectionObserver(
      (entries) => {
        if (!entries.some((e) => e.isIntersecting)) return;
        io.disconnect();
        raf = requestAnimationFrame(tick);
        // Frames stop when a tab is hidden mid-count (or never run in a headless capture); a timer still
        // fires, so the strip always settles on the real figure.
        settle = window.setTimeout(() => {
          cancelAnimationFrame(raf);
          setShown(to);
        }, duration + 100);
      },
      { threshold: 0.5 },
    );
    io.observe(el);
    return () => {
      io.disconnect();
      cancelAnimationFrame(raf);
      clearTimeout(settle);
    };
  }, [reduced, to, duration]);

  return <span ref={ref}>{shown ?? to}</span>;
}
