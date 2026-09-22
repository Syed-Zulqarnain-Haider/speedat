import type { Metadata, Viewport } from "next";
import { Barlow, Big_Shoulders, JetBrains_Mono } from "next/font/google";
import { getLiveSite } from "@/lib/site/live";
import { readTheme } from "@/lib/site/theme";
import "./globals.css";

// Three self-hosted families (CSP font-src 'self'): Barlow for body, Big Shoulders for display, JetBrains Mono
// for the admin's eyebrows and readouts. Only the first two preload — they are on the LCP path; the mono loads
// on first use. No serif: customer headlines are set in one face (brief v3 §2).
const barlow = Barlow({ variable: "--font-barlow", subsets: ["latin"], weight: ["400", "500", "600"], display: "swap" });
// next/font has no metric overrides for Big Shoulders (the build warns and skips the fallback), so say so explicitly.
const display = Big_Shoulders({ variable: "--font-bigshoulders", subsets: ["latin"], weight: "variable", axes: ["opsz"], display: "swap", adjustFontFallback: false });
const mono = JetBrains_Mono({ variable: "--font-jetbrains", subsets: ["latin"], weight: ["400", "500"], display: "swap", preload: false });

export async function generateMetadata(): Promise<Metadata> {
  const site = await getLiveSite();
  const title = `${site.company.name} — courier prices from ${site.company.origin}`;
  const description = `${site.services.map((s) => s.name).join(" and ")} courier prices from ${site.company.origin} by country and weight. Book on WhatsApp.`;
  const base = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";
  return {
    metadataBase: new URL(base),
    title: { default: title, template: `%s — ${site.company.name}` },
    description,
    openGraph: { title, description, type: "website" },
  };
}

/** The address-bar colour follows the chosen theme (the toggle updates the meta tag in place). */
export async function generateViewport(): Promise<Viewport> {
  const theme = await readTheme();
  return { themeColor: theme === "dark" ? "#0B1424" : "#FFFFFF", viewportFit: "cover" };
}

/**
 * `data-theme` is always present on the server HTML — "light" unless the
 * "theme" cookie says dark — so tokens.css never needs the OS preference
 * and there is no flash of the wrong theme.
 */
export default async function RootLayout({ children }: LayoutProps<"/">) {
  const theme = await readTheme();
  return (
    <html lang="en" data-theme={theme} className={`${barlow.variable} ${display.variable} ${mono.variable}`}>
      <body>{children}</body>
    </html>
  );
}
