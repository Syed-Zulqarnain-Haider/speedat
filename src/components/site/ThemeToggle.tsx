"use client";

/**
 * The sun/moon button in the header. The site is light for everyone by
 * default; a tap flips `data-theme` on <html> at once and remembers the
 * choice in the "theme" cookie (a year, SameSite=Lax), which the root
 * layout reads on the server so the next page paints in the chosen theme
 * with no flash. No localStorage, no server action, no matchMedia: the OS
 * preference never decides. State is seeded from the server-read cookie,
 * so the first client render matches the HTML.
 */
import { useState } from "react";
import { UI } from "@/components/Icons";
import type { Theme } from "@/lib/site/theme";

/** The colour behind the address bar on phones, kept in step with the page. */
const BAR: Record<Theme, string> = { light: "#FFFFFF", dark: "#0B1424" };

export function ThemeToggle({ initial }: { initial: Theme }) {
  const [theme, setTheme] = useState<Theme>(initial);
  // The label names what a tap does, so it changes with the state (the play/pause pattern); no aria-pressed,
  // which would contradict a changing label.
  const label = theme === "dark" ? "Light mode" : "Dark mode";
  const flip = () => {
    const next: Theme = theme === "dark" ? "light" : "dark";
    document.documentElement.dataset.theme = next;
    document.cookie = `theme=${next}; Path=/; Max-Age=31536000; SameSite=Lax${location.protocol === "https:" ? "; Secure" : ""}`;
    document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')?.setAttribute("content", BAR[next]);
    setTheme(next);
  };
  return (
    <button type="button" className="btn outline theme" aria-label={label} title={label} onClick={flip}>
      {theme === "dark" ? <UI.sun /> : <UI.moon />}
    </button>
  );
}
