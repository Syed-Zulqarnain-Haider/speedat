import type { Metadata } from "next";
import { UI } from "@/components/Icons";
import { ContactForm } from "@/components/site/ContactForm";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { PhoneText } from "@/components/site/pages/PhoneText";
import { SectionHead } from "@/components/site/pages/SectionHead";
import { phoneSegmentsExcept } from "@/components/site/pages/phones";
import { issueFormToken } from "@/lib/form-token";
import { fmtPhone } from "@/lib/pricing/format";
import { getLiveSite } from "@/lib/site/live";
import { cutoffFact, originCities } from "@/lib/site/text";

export const metadata: Metadata = { title: "Contact" };

/**
 * Contact (brief v3 §3): the WhatsApp number as the page — big, green, one
 * tap — then the other numbers, the email, the office, the hours and the
 * pickup facts as plain rows, then the message form under its own h2, then
 * the contact line. Every number and sentence comes from the company,
 * content and settings fields, and every number is a `tel:` link — the
 * free-text `phone2` field included, whatever label or separators the
 * owner typed around its numbers. No cards; the only glyph is WhatsApp's.
 *
 * Each number is stated ONCE on this page (QA, round 3): the "Also" row
 * skips whatever in `phone2` is the WhatsApp number or the landline
 * already printed above it, and the contact line at the end goes without
 * its numbers line — the two buttons alone. The footer (the shell's) still
 * prints them in its small print, as on every page.
 */
export default async function ContactPage() {
  const site = await getLiveSite();
  const c = site.content;
  const co = site.company;
  const cities = originCities(co);
  const mapHref = c.mapUrl || (c.address ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(c.address)}` : "");
  const cutoff = cutoffFact(site.settings.cutoffHour);
  const dests = site.destinations
    .filter((d) => d.active)
    .map((d) => ({ id: d.id, name: d.name }))
    .sort((a, b) => a.name.localeCompare(b.name));
  const wa = `https://wa.me/${co.whatsapp}`;
  const tel = co.phone ? `tel:${co.phone.replace(/[^0-9+]/g, "")}` : "";
  const also = phoneSegmentsExcept(c.phone2, [co.whatsapp, co.phone]);
  const hasFacts = Boolean(tel || also.length || co.email || c.address || c.hours || cities.length || cutoff);
  return (
    <>
      <section className="page">
        <PageHead title="Contact" lede={c.contactLede} />
        <a className="reach-big" href={wa} target="_blank" rel="noopener">
          <UI.wa />
          {fmtPhone(co.whatsapp)}
        </a>
        {hasFacts ? (
          <dl className="facts">
            {tel ? (
              <div>
                <dt>Call</dt>
                <dd>
                  <a href={tel}>{co.phone}</a>
                </dd>
              </div>
            ) : null}
            {also.length ? (
              <div>
                <dt>Also</dt>
                <dd>
                  <PhoneText segments={also} />
                </dd>
              </div>
            ) : null}
            {co.email ? (
              <div>
                <dt>Email</dt>
                <dd>
                  <a href={`mailto:${co.email}`}>{co.email}</a>
                </dd>
              </div>
            ) : null}
            {c.address ? (
              <div>
                <dt>Office</dt>
                <dd>
                  {c.address}
                  {mapHref ? (
                    <>
                      {" · "}
                      <a href={mapHref} target="_blank" rel="noopener">
                        Google Maps
                      </a>
                    </>
                  ) : null}
                </dd>
              </div>
            ) : null}
            {c.hours ? (
              <div>
                <dt>Hours</dt>
                <dd>{c.hours}</dd>
              </div>
            ) : null}
            {cities.length ? (
              <div>
                <dt>Pickup</dt>
                <dd>{cities.join(" and ")}</dd>
              </div>
            ) : null}
            {cutoff ? (
              <div>
                <dt>Cutoff</dt>
                <dd>{cutoff}</dd>
              </div>
            ) : null}
          </dl>
        ) : null}
        <SectionHead title="Or write to us" id="write-title" />
        <ContactForm destinations={dests} whatsapp={co.whatsapp} token={issueFormToken()} />
      </section>
      <CtaBand whatsapp={co.whatsapp} phone={co.phone} title={c.ctaTitle} sub={c.ctaSub} showLine={false} />
    </>
  );
}
