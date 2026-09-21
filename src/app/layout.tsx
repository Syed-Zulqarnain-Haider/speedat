import type { Metadata, Viewport } from "next";
import { Barlow, Big_Shoulders, Instrument_Serif, JetBrains_Mono } from "next/font/google";
import { getLiveSite } from "@/lib/site/live";
import "./globals.css";

// Four self-hosted families (CSP font-src 'self'): Barlow for body, Big Shoulders for display,
// Instrument Serif italic for the one accent word, JetBrains Mono for eyebrows and readouts.
// Only the first two preload — they are on the LCP path; the serif and mono load on first use.
const barlow = Barlow({ variable: "--font-barlow", subsets: ["latin"], weight: ["400", "500", "600"], display: "swap" });
// next/font has no metric overrides for Big Shoulders (the build warns and skips the fallback), so say so explicitly.
const display = Big_Shoulders({ variable: "--font-bigshoulders", subsets: ["latin"], weight: "variable", axes: ["opsz"], display: "swap", adjustFontFallback: false });
const serif = Instrument_Serif({ variable: "--font-instrument", subsets: ["latin"], weight: "400", style: "italic", display: "swap", preload: false });
const mono = JetBrains_Mono({ variable: "--font-jetbrains", subsets: ["latin"], weight: ["400", "500"], display: "swap", preload: false });

export async function generateMetadata(): Promise<Metadata> {
  const site = await getLiveSite();
  const title = `${site.company.name} — courier prices from ${site.company.origin}`;
  const description = `Instant ${site.services.map((s) => s.name).join(" and ")} courier prices from ${site.company.origin} by destination, weight and size. Book on WhatsApp.`;
  const base = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";
  return {
    metadataBase: new URL(base),
    title: { default: title, template: `%s — ${site.company.name}` },
    description,
    openGraph: { title, description, type: "website" },
  };
}

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#F5F0E7" },
    { media: "(prefers-color-scheme: dark)", color: "#0B1424" },
  ],
  viewportFit: "cover",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className={`${barlow.variable} ${display.variable} ${serif.variable} ${mono.variable}`}>
      <body>{children}</body>
    </html>
  );
}
