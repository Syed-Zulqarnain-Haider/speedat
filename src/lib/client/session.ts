"use client";

/**
 * Per-tab remembered values (last destination, chosen mode) without effects:
 * an in-memory store mirrored to sessionStorage when that is available, read
 * through useSyncExternalStore so the server renders the empty snapshot and
 * the browser fills in after hydration with no mismatch warnings.
 */
import { useSyncExternalStore } from "react";

const mem = new Map<string, string | null>();
const listeners = new Set<() => void>();

function read(key: string): string | null {
  if (mem.has(key)) return mem.get(key) ?? null;
  let v: string | null = null;
  try {
    v = sessionStorage.getItem(key);
  } catch {
    /* private mode, disabled storage: memory only */
  }
  mem.set(key, v);
  return v;
}

export function setSession(key: string, value: string | null): void {
  mem.set(key, value);
  try {
    if (value == null) sessionStorage.removeItem(key);
    else sessionStorage.setItem(key, value);
  } catch {
    /* memory only */
  }
  listeners.forEach((l) => l());
}

function subscribe(l: () => void): () => void {
  listeners.add(l);
  return () => listeners.delete(l);
}

export function useSession(key: string): string | null {
  return useSyncExternalStore(subscribe, () => read(key), () => null);
}

/** True once hydrated in the browser; false during server render and hydration. */
export function useMounted(): boolean {
  return useSyncExternalStore(
    () => () => {},
    () => true,
    () => false,
  );
}
