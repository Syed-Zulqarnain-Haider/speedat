"use client";

import type { ReactNode } from "react";
import AnimatedContent from "@/components/bits/AnimatedContent";
import { useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";

/** Slides content in as it scrolls into view; plain markup before mount or when motion is reduced. */
export function Reveal({ children, delay = 0, distance = 36, className }: { children: ReactNode; delay?: number; distance?: number; className?: string }) {
  const reduced = useReducedMotion();
  const mounted = useMounted();
  if (!mounted || reduced) return <div className={className}>{children}</div>;
  return (
    <AnimatedContent className={className} distance={distance} direction="vertical" duration={0.7} ease="power3.out" initialOpacity={0} threshold={0.12} delay={delay}>
      {children}
    </AnimatedContent>
  );
}
