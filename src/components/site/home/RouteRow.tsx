"use client";

/**
 * One row of the route board. On the home page a click selects the
 * destination in the calculator (the calculator already subscribes to the
 * `sp-last` session key; the remembered kg is preserved) and scrolls to the
 * instrument. On /services (`href` given) it renders a link, writes the same
 * key and lets the navigation proceed. The trailing arrow is decorative.
 */
import type { ReactNode } from "react";
import { useReducedMotion } from "@/lib/client/motion";
import { setSession, useSession } from "@/lib/client/session";

interface Remembered {
  destId?: string;
  kg?: string;
}

function safeParse(raw: string | null): Remembered {
  if (!raw) return {};
  try {
    const v = JSON.parse(raw) as Remembered;
    return typeof v === "object" && v ? v : {};
  } catch {
    return {};
  }
}

export function RouteRow({ destId, href, children }: { destId: string; href?: string; children: ReactNode }) {
  const raw = useSession("sp-last");
  const reduced = useReducedMotion();
  const remember = () => setSession("sp-last", JSON.stringify({ ...safeParse(raw), destId }));
  const go = (
    <span className="route-go" aria-hidden="true">
      →
    </span>
  );
  if (href) {
    return (
      <a className="route-row" href={href} onClick={remember}>
        {children}
        {go}
      </a>
    );
  }
  return (
    <button
      className="route-row"
      type="button"
      onClick={() => {
        remember();
        document.getElementById("quote-instrument")?.scrollIntoView({ behavior: reduced ? "auto" : "smooth", block: "start" });
      }}
    >
      {children}
      {go}
    </button>
  );
}
