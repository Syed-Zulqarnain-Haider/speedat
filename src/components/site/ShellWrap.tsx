import type { ReactNode } from "react";

/**
 * The page container: one width everywhere (`--wrap`), gutters from
 * `--gutter`. `.site` is the scope every customer override hangs from
 * (the v2 type scale, targets and skins in tokens.css), so the admin is
 * untouched by them. A flex column at least one window tall (site.css
 * SHELL), so the footer meets the bottom of a short page instead of
 * floating above blank paper. A plain server component: nothing here runs
 * in the browser, so it costs the customer bundle nothing.
 */
export function ShellWrap({ children }: { children: ReactNode }) {
  return <div className="site wrap">{children}</div>;
}
