"use client";

/**
 * The one living surface on the site: hairline threads drifting behind the
 * home headline. The server (and every phone, reduced-motion visitor,
 * save-data connection and machine without WebGL) gets the inline `.silk`
 * SVG only; the WebGL canvas is fetched and mounted after hydration when the
 * gate passes, fading in over the SVG so nothing pops. `ogl` is never
 * requested unless the gate passes. Threads itself pauses off-screen and
 * while the tab is hidden, and caps the device pixel ratio at 1.5.
 */
import dynamic from "next/dynamic";
import { useCanFx, useMediaQuery, useReducedMotion, useSaveData } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";

const Threads = dynamic(() => import("@/components/bits/Threads"), { ssr: false });

const LIGHT: [number, number, number] = [0.04, 0.1, 0.2];
const DARK: [number, number, number] = [0.95, 0.93, 0.89];

/** Five hairline threads, drawn in a 1200×600 box and stretched to the hero. */
const SILK = [
  "M0 120 C 300 60, 600 200, 900 140 S 1200 100, 1200 100",
  "M0 220 C 250 160, 550 320, 850 240 S 1200 200, 1200 220",
  "M0 320 C 350 260, 600 420, 950 340 S 1200 300, 1200 320",
  "M0 420 C 300 360, 650 520, 900 440 S 1200 400, 1200 420",
  "M0 520 C 250 460, 550 600, 850 520 S 1200 480, 1200 500",
];

export function LivingBackground() {
  const mounted = useMounted();
  const reduced = useReducedMotion();
  const saveData = useSaveData();
  const wide = useMediaQuery("(min-width: 720px)");
  const dark = useMediaQuery("(prefers-color-scheme: dark)");
  const canFx = useCanFx();
  const live = mounted && !reduced && !saveData && wide && canFx;
  return (
    <div className="hero-bg" aria-hidden="true">
      <svg className="silk" viewBox="0 0 1200 600" preserveAspectRatio="none" fill="none" stroke="currentColor" strokeWidth="1" style={{ opacity: 0.18 }}>
        {SILK.map((d) => (
          <path key={d} d={d} vectorEffect="non-scaling-stroke" />
        ))}
      </svg>
      {live ? (
        <div className="hero-canvas">
          <Threads color={dark ? DARK : LIGHT} amplitude={0.8} distance={0.2} enableMouseInteraction={false} />
        </div>
      ) : null}
    </div>
  );
}
