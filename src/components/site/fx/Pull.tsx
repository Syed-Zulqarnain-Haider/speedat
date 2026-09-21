"use client";

/**
 * A gentle magnetic pull on a call to action for pointer devices: the button
 * leans towards the cursor within 32px. Same DOM depth in both branches
 * (`.pull > .pull-in > children`) so layout never shifts when the effect
 * mounts; touch, reduced motion and the server all get the bare wrappers.
 */
import type { ReactNode } from "react";
import Magnet from "@/components/bits/Magnet";
import { useMediaQuery, useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";

export function Pull({ children, className }: { children: ReactNode; className?: string }) {
  const mounted = useMounted();
  const reduced = useReducedMotion();
  const pointer = useMediaQuery("(hover: hover) and (pointer: fine)");
  const cls = className ? `pull ${className}` : "pull";
  if (!mounted || reduced || !pointer) {
    return (
      <div className={cls}>
        <div className="pull-in">{children}</div>
      </div>
    );
  }
  return (
    <Magnet padding={32} magnetStrength={4} wrapperClassName={cls} innerClassName="pull-in">
      {children}
    </Magnet>
  );
}
