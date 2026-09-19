"use client";

import type { SiteData } from "@/lib/site/types";
import { Area, Fld } from "./fields";

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
      <h2>Website pages</h2>
      <p className="desc">The words on the site. One line per item where it says so; separate the parts of a line with a vertical bar |. Publishing rates publishes these too.</p>
      <h3 style={{ margin: "8px 0" }}>Home</h3>
      <div className="grid2">
        <Fld label="Headline" value={ct.heroTitle} onChange={set("heroTitle")} disabled={readOnly} />
        <Fld label="Line under the headline" value={ct.heroSub} onChange={set("heroSub")} disabled={readOnly} />
      </div>
      <Area label="Numbers strip (optional, one per line: Label | Value; blank = automatic)" value={ct.stats} placeholder="Years in business | 12" onChange={set("stats")} disabled={readOnly} />
      <h3 style={{ margin: "16px 0 8px" }}>Services page</h3>
      <Area label="Services, one per line: icon | Title | Description (icons: plane, globe, doc, box, truck, shield, clock, phone)" value={ct.services} onChange={set("services")} disabled={readOnly} />
      <h3 style={{ margin: "16px 0 8px" }}>About page</h3>
      <Area label="Our story (one paragraph per line)" value={ct.story} onChange={set("story")} disabled={readOnly} />
      <div className="grid2">
        <Area label="Mission" value={ct.mission} onChange={set("mission")} disabled={readOnly} />
        <Area label="Vision" value={ct.vision} onChange={set("vision")} disabled={readOnly} />
      </div>
      <Area label="Values, one per line: Value | one-line explanation" value={ct.values} onChange={set("values")} disabled={readOnly} />
      <h3 style={{ margin: "16px 0 8px" }}>Contact page</h3>
      <div className="grid2">
        <Fld label="Office address" value={ct.address} onChange={set("address")} disabled={readOnly} />
        <Fld label="Working hours" value={ct.hours} onChange={set("hours")} disabled={readOnly} />
        <Fld label="Second phone number (optional)" value={ct.phone2} onChange={set("phone2")} disabled={readOnly} />
        <Fld label="Google Maps link (optional; blank = search by address)" value={ct.mapUrl} onChange={set("mapUrl")} disabled={readOnly} />
      </div>
      <h3 style={{ margin: "16px 0 8px" }}>FAQ page</h3>
      <Area label="Questions, one per line: Question? | Answer" value={ct.faq} onChange={set("faq")} disabled={readOnly} />
    </section>
  );
}
