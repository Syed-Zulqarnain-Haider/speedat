import { notFound } from "next/navigation";

/**
 * Catch-all for every path no other route claims. Calling notFound() here
 * renders the designed (site)/not-found.tsx inside the site shell with a
 * 404 status; without it Next's default page answered, whose inline
 * <style> the production CSP (style-src nonce) would strip. Static routes
 * (/, /services, /admin/*, /api/*, robots, sitemap) always win over this.
 * (A page that throws notFound() loses its own metadata, so the title is
 * set inside not-found.tsx.)
 */
export default function CatchAll(): never {
  notFound();
}
