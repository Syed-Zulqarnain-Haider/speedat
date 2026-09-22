import type { Metadata } from "next";
import SiteLayout from "@/app/(site)/layout";
import { NOT_FOUND, NotFoundBody } from "@/components/site/pages/NotFoundBody";
import { getLiveSite } from "@/lib/site/live";

/** Read through the root title template, so the tab says "Page not found — {company}". */
export const metadata: Metadata = { title: "Page not found" };

/**
 * The 404 for every URL no route claims (/typo, /services/old, /admin/x,
 * /api/x …). Next serves it as its own `/_not-found` route with a 404
 * status, so the root layout's `data-theme`, the stylesheet and this whole
 * body are in the first byte of server HTML — no blank first paint, no
 * white flash for dark-theme visitors, and it reads without JavaScript.
 *
 * A `notFound()` thrown from a catch-all page cannot do that: outside a
 * Suspense boundary it aborts the shell to Next's bare error document
 * (<html id="__next_error__">, filled in on the client); inside one the
 * shell has already streamed as 200. So this route owns the 404, and
 * because Next mounts it in the root layout only, it wraps itself in the
 * site layout — the same header, nav, footer and JSON-LD as every customer
 * page — and adds the two ways out: the calculator and WhatsApp.
 */
export default async function NotFound() {
  const site = await getLiveSite();
  return (
    <SiteLayout params={Promise.resolve({})}>
      <NotFoundBody title={NOT_FOUND.title} line={NOT_FOUND.line} whatsapp={site.company.whatsapp} />
    </SiteLayout>
  );
}
