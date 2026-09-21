"use client";

/**
 * Motion and capability probes for client components, all read through
 * useSyncExternalStore with a `false` server snapshot: the server (and the
 * hydration pass) always paints the static, fully readable branch, and the
 * browser upgrades after mount without a mismatch warning.
 */
import { useCallback, useSyncExternalStore } from "react";

const REDUCED = "(prefers-reduced-motion: reduce)";

const lists = new Map<string, MediaQueryList>();

function mql(query: string): MediaQueryList | null {
  if (typeof window === "undefined" || !window.matchMedia) return null;
  let m = lists.get(query);
  if (!m) {
    m = window.matchMedia(query);
    lists.set(query, m);
  }
  return m;
}

const serverFalse = () => false;
const noop = () => () => {};

/** True while `query` matches; false on the server and during hydration. */
export function useMediaQuery(query: string): boolean {
  const subscribe = useCallback(
    (cb: () => void) => {
      const m = mql(query);
      if (!m) return () => {};
      m.addEventListener("change", cb);
      return () => m.removeEventListener("change", cb);
    },
    [query],
  );
  return useSyncExternalStore(subscribe, () => mql(query)?.matches ?? false, serverFalse);
}

/** True when the visitor asked the OS for less motion; animated components fall back to static rendering. */
export function useReducedMotion(): boolean {
  return useMediaQuery(REDUCED);
}

function saveData(): boolean {
  if (typeof navigator === "undefined") return false;
  return (navigator as Navigator & { connection?: { saveData?: boolean } }).connection?.saveData === true;
}

/** True when the browser reports the Save-Data preference; decorative effects skip their downloads. */
export function useSaveData(): boolean {
  return useSyncExternalStore(noop, saveData, serverFalse);
}

let fxProbe: boolean | null = null;

function canFx(): boolean {
  if (fxProbe !== null) return fxProbe;
  if (typeof document === "undefined") return false;
  try {
    const c = document.createElement("canvas");
    const gl = c.getContext("webgl2") ?? c.getContext("webgl");
    fxProbe = !!gl;
    // Give the probe context back straight away; browsers cap live contexts per page.
    gl?.getExtension("WEBGL_lose_context")?.loseContext();
  } catch {
    fxProbe = false;
  }
  return fxProbe;
}

/** True when WebGL is available (probed once per page); a throwing getContext counts as no. */
export function useCanFx(): boolean {
  return useSyncExternalStore(noop, canFx, serverFalse);
}
