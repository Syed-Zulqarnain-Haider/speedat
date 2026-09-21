"use client";

import { useEffect, useRef, type ReactNode } from "react";
import { gsap } from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";
import { useReducedMotion } from "@/lib/client/motion";

gsap.registerPlugin(ScrollTrigger);

interface Props {
  children: ReactNode;
  /** Seconds before the slide starts. */
  delay?: number;
  /** Pixels travelled. */
  distance?: number;
  className?: string;
  /** Position in a list; multiplied by `stagger` and added to `delay`. Capped by `cap`. */
  index?: number;
  /** Seconds per list position (e.g. 0.04 for route rows, 0.08 for steps). */
  stagger?: number;
  /** Highest index that still adds delay, so long lists do not wait forever (default 8). */
  cap?: number;
  /**
   * How far into the viewport the block's top must be before it reveals, as a fraction of the viewport
   * height (default 0.12). Use ~0 for blocks that may sit partly inside the first screen, so nothing that
   * is already on screen waits, invisible, for a scroll.
   */
  threshold?: number;
}

/**
 * Slides content in as it scrolls into view.
 *
 * The markup is always the plain server-rendered div, so what the visitor sees before hydration is what
 * stays. On mount the block is measured once: only a block whose top is below the viewport is hidden and
 * given the slide-in when it scrolls up; a block already on the first screen (or scrolled past) is left
 * exactly as it painted. Nothing on screen ever vanishes and returns. Reduced motion: never animates.
 */
export function Reveal({ children, delay = 0, distance = 36, className, index = 0, stagger = 0, cap = 8, threshold = 0.12 }: Props) {
  const reduced = useReducedMotion();
  const ref = useRef<HTMLDivElement>(null);
  const wait = delay + Math.min(index, cap) * stagger;

  useEffect(() => {
    const el = ref.current;
    if (!el || reduced) return;
    // On the first screen already (or above it): leave it as it painted.
    if (el.getBoundingClientRect().top < window.innerHeight) return;
    gsap.set(el, { y: distance, opacity: 0 });
    const tl = gsap.timeline({ paused: true, delay: wait }).to(el, { y: 0, opacity: 1, duration: 0.7, ease: "power3.out" });
    const st = ScrollTrigger.create({ trigger: el, start: `top ${(1 - threshold) * 100}%`, once: true, onEnter: () => tl.play() });
    return () => {
      st.kill();
      tl.kill();
      gsap.set(el, { clearProps: "transform,opacity" });
    };
  }, [reduced, distance, wait, threshold]);

  return (
    <div ref={ref} className={className}>
      {children}
    </div>
  );
}
