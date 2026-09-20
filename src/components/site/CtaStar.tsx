"use client";

import type { ReactNode } from "react";
import StarBorder from "@/components/bits/StarBorder";
import { useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";

/** Wraps a call-to-action in React Bits' travelling-star border; plain before mount or with reduced motion. */
export function CtaStar({ children }: { children: ReactNode }) {
  const reduced = useReducedMotion();
  const mounted = useMounted();
  if (!mounted || reduced) return <>{children}</>;
  return (
    <StarBorder as="div" color="#f97316" speed="5s" thickness={2} backgroundColor="transparent" textColor="inherit" borderColor="transparent" className="cta-star">
      {children}
    </StarBorder>
  );
}
