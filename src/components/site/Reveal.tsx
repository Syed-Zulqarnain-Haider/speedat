"use client";

import type { ReactNode } from "react";
import AnimatedContent from "@/components/bits/AnimatedContent";
import { useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";

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
}

/** Slides content in as it scrolls into view; plain markup before mount or when motion is reduced. */
export function Reveal({ children, delay = 0, distance = 36, className, index = 0, stagger = 0, cap = 8 }: Props) {
  const reduced = useReducedMotion();
  const mounted = useMounted();
  if (!mounted || reduced) return <div className={className}>{children}</div>;
  const wait = delay + Math.min(index, cap) * stagger;
  return (
    <AnimatedContent className={className} distance={distance} direction="vertical" duration={0.7} ease="power3.out" initialOpacity={0} threshold={0.12} delay={wait}>
      {children}
    </AnimatedContent>
  );
}
