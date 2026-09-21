/**
 * The colour theme is light for everyone unless the visitor chose dark with
 * the header toggle, which the "theme" cookie remembers for a year. Layouts
 * read it on the server so <html data-theme> is in the first byte: no
 * flash, no script before paint, and the OS preference never decides.
 */
import { cookies } from "next/headers";

export type Theme = "light" | "dark";

export async function readTheme(): Promise<Theme> {
  return (await cookies()).get("theme")?.value === "dark" ? "dark" : "light";
}
