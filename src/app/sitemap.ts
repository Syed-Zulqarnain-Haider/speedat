import type { MetadataRoute } from "next";
import { getLiveSite } from "@/lib/site/live";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const base = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";
  const site = await getLiveSite();
  const lastModified = site.version ? new Date(site.publishedAt) : new Date();
  return ["", "/services", "/about", "/track", "/contact", "/faq"].map((p) => ({ url: `${base}${p}`, lastModified, changeFrequency: p === "" ? "daily" : "monthly", priority: p === "" ? 1 : 0.6 }));
}
