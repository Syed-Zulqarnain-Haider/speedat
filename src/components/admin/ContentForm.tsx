"use client";

import type { SiteData } from "@/lib/site/types";
import { Area, Fld } from "./fields";
import { sectionNo } from "./sections";

interface Props {
  draft: SiteData;
  readOnly: boolean;
  update: (fn: (d: SiteData) => void) => void;
  epoch: number;
}

/** The headline fits two lines on a laptop up to this many characters; longer wraps to three. */
const HEADLINE_MAX = 60;

/**
 * The words on the site. Three fields are "blank = automatic": the site
 * writes them from the rate document (the pickup city, the live countries,
 * the lowest 1 kg price, the day span, the document limit, the pickup
 * charge), so they can never say a number the rates do not. `stepsTitle`,
 * `steps` and `servicesTitle` render nowhere since v3 and are not shown
 * here; the keys stay in the document.
 */
export function ContentForm({ draft, readOnly, update, epoch }: Props) {
  const ct = draft.content;
  const set = (k: keyof typeof ct) => (raw: string) =>
    update((d) => {
      d.content[k] = raw;
    });
  const titleLen = ct.heroTitle.trim().length;
  const titleHint = !titleLen
    ? "Blank = automatic: the first pickup city and the countries on the site, e.g. Lahore to Australia, Canada, France and Germany."
    : titleLen > HEADLINE_MAX
      ? `Too long: ${titleLen} characters. Over ${HEADLINE_MAX} the headline wraps to three lines on a laptop — shorten it, or leave it blank for the automatic one.`
      : `${titleLen} of ${HEADLINE_MAX} characters. Printed exactly as typed. Leave it blank for the automatic one: the first pickup city and the countries on the site.`;
  return (
    <section className="block" id="sec-content" key={epoch}>
      <p className="eyebrow">{sectionNo("content")} — Pages</p>
      <h2>Website pages</h2>
      <p className="desc">The words on the site. One line per item where it says so; separate the parts of a line with a vertical bar |. Publishing rates publishes these too.</p>
      <h3 className="fh">Home</h3>
      <div className="grid2">
        <Fld label="Headline (blank = automatic)" value={ct.heroTitle} onChange={set("heroTitle")} disabled={readOnly} hint={titleHint} />
        <Fld
          label="Line under the headline (blank = automatic)"
          value={ct.heroSub}
          onChange={set("heroSub")}
          disabled={readOnly}
          hint="Blank = automatic: the lowest 1 kg price (pickup included) and the day span, e.g. 1 kg from PKR 5,000 · 3 to 10 days. While prices are on hold: Pickup in Lahore · 3 to 10 days. Keep your own line to about ten words so the country tiles stay on the first phone screen."
        />
        <Fld label="Line under the calculator (blank = none)" value={ct.promise} onChange={set("promise")} disabled={readOnly} />
      </div>
      <Area label="Extra line under the headline (optional, one per line: Label | Value; blank = none)" value={ct.stats} placeholder="Years in business | 12" onChange={set("stats")} disabled={readOnly} />
      <h3 className="fh">Rates board</h3>
      <div className="grid2">
        <Fld label="Caption" value={ct.routesTitle} onChange={set("routesTitle")} disabled={readOnly} hint="The heading of the rates table on the home page and on Services, e.g. Rates." />
        <Fld
          label="Second line of the caption (blank = automatic)"
          value={ct.routesNote}
          onChange={set("routesNote")}
          disabled={readOnly}
          hint="Blank = automatic: the parcel weight, the document limit and the pickup charge, e.g. 1 kg parcel · documents up to 0.5 kg · pickup PKR 500 included."
        />
      </div>
      <h3 className="fh">Services page</h3>
      <Fld label="Line under the heading" value={ct.servicesLede} onChange={set("servicesLede")} disabled={readOnly} />
      <Area label="Services, one per line: icon | Title | text (the icon word is kept for older pages and not shown)" value={ct.services} onChange={set("services")} disabled={readOnly} />
      <h3 className="fh">Contact line (inner pages)</h3>
      <div className="grid2">
        <Fld label="Heading (optional)" value={ct.ctaTitle} onChange={set("ctaTitle")} disabled={readOnly} />
        <Fld label="Line under the heading (optional)" value={ct.ctaSub} onChange={set("ctaSub")} disabled={readOnly} />
      </div>
      <h3 className="fh">About page</h3>
      <Area label="Our story (one paragraph per line)" value={ct.story} onChange={set("story")} disabled={readOnly} />
      <div className="grid2">
        <Area label="Mission (optional)" value={ct.mission} onChange={set("mission")} disabled={readOnly} />
        <Area label="Vision (optional)" value={ct.vision} onChange={set("vision")} disabled={readOnly} />
      </div>
      <Area label="Values (optional), one per line: Value | one-line explanation" value={ct.values} onChange={set("values")} disabled={readOnly} />
      <h3 className="fh">Contact page</h3>
      <Fld label="Line under the heading" value={ct.contactLede} onChange={set("contactLede")} disabled={readOnly} />
      <div className="grid2">
        <Fld label="Office address" value={ct.address} onChange={set("address")} disabled={readOnly} />
        <Fld label="Working hours" value={ct.hours} onChange={set("hours")} disabled={readOnly} />
        <Fld label="Second phone number (optional)" value={ct.phone2} onChange={set("phone2")} disabled={readOnly} />
        <Fld label="Google Maps link (optional; blank = search by address)" value={ct.mapUrl} onChange={set("mapUrl")} disabled={readOnly} />
      </div>
      <h3 className="fh">FAQ page</h3>
      <Fld label="Line under the heading (optional)" value={ct.faqLede} onChange={set("faqLede")} disabled={readOnly} />
      <Area label="Questions, one per line: Question? | Answer" value={ct.faq} onChange={set("faq")} disabled={readOnly} />
    </section>
  );
}
