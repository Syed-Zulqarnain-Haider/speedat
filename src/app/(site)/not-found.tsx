import Link from "next/link";
import { PageHead } from "@/components/site/PageHead";
import { getLiveSite } from "@/lib/site/live";

/**
 * 404: the page head over a ghost numeral, one way back to the calculator.
 * Reached through (site)/[...rest]/page.tsx for every unmatched URL. A
 * not-found file cannot export metadata, so the tab title is a rendered
 * <title>, which React hoists into the head.
 */
export default async function NotFound() {
  const site = await getLiveSite();
  return (
    <section className="page nf">
      <title>{`Not found — ${site.company.name}`}</title>
      <span className="ghost nf-ghost" aria-hidden="true">
        404
      </span>
      <PageHead
        no="404"
        name="Not found"
        title="That page is not *here*"
        lede="The link may be old. The price calculator is on the home page, and we are one message away on WhatsApp."
      />
      <div className="nf-actions">
        <Link className="btn primary big" href="/">
          Get a quote
        </Link>
        <Link className="btn outline big" href="/contact">
          Contact us
        </Link>
      </div>
    </section>
  );
}
