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

export function ContentForm({ draft, readOnly, update, epoch }: Props) {
  const ct = draft.content;
  const set = (k: keyof typeof ct) => (raw: string) =>
    update((d) => {
      d.content[k] = raw;
    });
  return (
    <section className="block" id="sec-content" key={epoch}>
      <p className="eyebrow">{sectionNo("content")} — Pages</p>
      <h2>Website pages</h2>
      <p className="desc">The words on the site. One line per item where it says so; separate the parts of a line with a vertical bar |. Publishing rates publishes these too.</p>
      <h3 className="fh">Home</h3>
      <div className="grid2">
        <Fld
          label="Headline"
          value={ct.heroTitle}
          onChange={set("heroTitle")}
          disabled={readOnly}
          hint="Wrap one word in *asterisks* to set it in italic, e.g. Send anything *abroad*. An unbalanced asterisk prints as typed. Every heading below accepts the same."
        />
        <Fld label="Line under the headline" value={ct.heroSub} onChange={set("heroSub")} disabled={readOnly} />
        <Fld label="Route line above the headline (blank = automatic)" value={ct.heroEyebrow} onChange={set("heroEyebrow")} disabled={readOnly} placeholder="Lahore → United Kingdom · United States · …" />
        <Fld label="Promise line under the calculator (blank = none)" value={ct.promise} onChange={set("promise")} disabled={readOnly} />
      </div>
      <Area label="Numbers strip (optional, one per line: Label | Value; blank = automatic)" value={ct.stats} placeholder="Years in business | 12" onChange={set("stats")} disabled={readOnly} />
      <h3 className="fh">Routes</h3>
      <div className="grid2">
        <Fld label="Routes heading" value={ct.routesTitle} onChange={set("routesTitle")} disabled={readOnly} />
        <Fld label="Routes note" value={ct.routesNote} onChange={set("routesNote")} disabled={readOnly} />
      </div>
      <h3 className="fh">How it works</h3>
      <Fld label="Heading" value={ct.stepsTitle} onChange={set("stepsTitle")} disabled={readOnly} />
      <Area label="Steps, one per line: Title | text" value={ct.steps} onChange={set("steps")} disabled={readOnly} />
      <h3 className="fh">Services</h3>
      <div className="grid2">
        <Fld label="Home services heading" value={ct.servicesTitle} onChange={set("servicesTitle")} disabled={readOnly} />
        <Fld label="Services page intro" value={ct.servicesLede} onChange={set("servicesLede")} disabled={readOnly} />
      </div>
      <Area label="Services, one per line: icon | Title | Description (icons: plane, globe, doc, box, truck, shield, clock, phone)" value={ct.services} onChange={set("services")} disabled={readOnly} />
      <h3 className="fh">Book band</h3>
      <div className="grid2">
        <Fld label="Heading" value={ct.ctaTitle} onChange={set("ctaTitle")} disabled={readOnly} />
        <Fld label="Line under the heading" value={ct.ctaSub} onChange={set("ctaSub")} disabled={readOnly} />
      </div>
      <h3 className="fh">About page</h3>
      <Area label="Our story (one paragraph per line)" value={ct.story} onChange={set("story")} disabled={readOnly} />
      <div className="grid2">
        <Area label="Mission" value={ct.mission} onChange={set("mission")} disabled={readOnly} />
        <Area label="Vision" value={ct.vision} onChange={set("vision")} disabled={readOnly} />
      </div>
      <Area label="Values, one per line: Value | one-line explanation" value={ct.values} onChange={set("values")} disabled={readOnly} />
      <h3 className="fh">Contact page</h3>
      <Fld label="Intro line" value={ct.contactLede} onChange={set("contactLede")} disabled={readOnly} />
      <div className="grid2">
        <Fld label="Office address" value={ct.address} onChange={set("address")} disabled={readOnly} />
        <Fld label="Working hours" value={ct.hours} onChange={set("hours")} disabled={readOnly} />
        <Fld label="Second phone number (optional)" value={ct.phone2} onChange={set("phone2")} disabled={readOnly} />
        <Fld label="Google Maps link (optional; blank = search by address)" value={ct.mapUrl} onChange={set("mapUrl")} disabled={readOnly} />
      </div>
      <h3 className="fh">FAQ page</h3>
      <Fld label="Intro line" value={ct.faqLede} onChange={set("faqLede")} disabled={readOnly} />
      <Area label="Questions, one per line: Question? | Answer" value={ct.faq} onChange={set("faq")} disabled={readOnly} />
    </section>
  );
}
