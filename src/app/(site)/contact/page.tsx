import type { Metadata } from "next";
import { UI } from "@/components/Icons";
import { ContactForm } from "@/components/site/ContactForm";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { Reveal } from "@/components/site/Reveal";
import { SectionHead } from "@/components/site/pages/SectionHead";
import { issueFormToken } from "@/lib/form-token";
import { fmtHour, fmtPhone } from "@/lib/pricing/format";
import { getLiveSite } from "@/lib/site/live";
import { originCities } from "@/lib/site/text";

export const metadata: Metadata = { title: "Contact" };

/** An envelope for the Email card (the shared icon set has no mail glyph); same 24px stroke style as `UI`. */
function MailIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="M3 7.5l9 6 9-6" />
    </svg>
  );
}

/**
 * Contact: three cards with a giant button each (WhatsApp, Call, Email),
 * the office and hours, the message form, the WhatsApp / Call band. Every
 * number and sentence comes from the company, content and settings fields.
 */
export default async function ContactPage() {
  const site = await getLiveSite();
  const c = site.content;
  const co = site.company;
  const cities = originCities(co);
  const mapHref = c.mapUrl || (c.address ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(c.address)}` : "");
  const cutoff = site.settings.cutoffHour;
  const dests = site.destinations
    .filter((d) => d.active)
    .map((d) => ({ id: d.id, name: d.name }))
    .sort((a, b) => a.name.localeCompare(b.name));
  const wa = `https://wa.me/${co.whatsapp}`;
  const tel = co.phone ? `tel:${co.phone.replace(/[^0-9+]/g, "")}` : "";
  const hasVisit = Boolean(c.address || c.hours || cities.length || cutoff != null);
  return (
    <>
      <section className="page">
        <PageHead title="Contact *us*" lede={c.contactLede} />
        <div className="cards three">
          <div className="card contact-card">
            <span className="card-ico" aria-hidden="true">
              <UI.wa />
            </span>
            <div className="contact-text">
              <h3>WhatsApp</h3>
              <p className="contact-big">{fmtPhone(co.whatsapp)}</p>
              {c.hours ? <p className="contact-note">{c.hours}</p> : null}
            </div>
            <a className="btn wa giant" href={wa} target="_blank" rel="noopener">
              <UI.wa />
              Open WhatsApp
            </a>
          </div>
          {co.phone ? (
            <div className="card contact-card">
              <span className="card-ico" aria-hidden="true">
                <UI.phone />
              </span>
              <div className="contact-text">
                <h3>Call</h3>
                <p className="contact-big">{co.phone}</p>
                {c.phone2 ? <p className="contact-note">{c.phone2}</p> : null}
              </div>
              <a className="btn giant outline" href={tel}>
                <UI.phone />
                Call now
              </a>
            </div>
          ) : null}
          {co.email ? (
            <div className="card contact-card">
              <span className="card-ico" aria-hidden="true">
                <MailIcon />
              </span>
              <div className="contact-text">
                <h3>Email</h3>
                <p className="contact-big">{co.email}</p>
              </div>
              <a className="btn giant outline" href={`mailto:${co.email}`}>
                <MailIcon />
                Write to us
              </a>
            </div>
          ) : null}
        </div>
        {hasVisit ? (
          <>
            <SectionHead title="Office and hours" id="visit-title" />
            <div className="cards">
              {c.address ? (
                <Reveal distance={24}>
                  <div className="card">
                    <h3>Office</h3>
                    <p>{c.address}</p>
                    {mapHref ? (
                      <a className="btn outline" href={mapHref} target="_blank" rel="noopener">
                        <UI.pin />
                        Open in Google Maps
                      </a>
                    ) : null}
                  </div>
                </Reveal>
              ) : null}
              {c.hours || cities.length || cutoff != null ? (
                <Reveal delay={0.06} distance={24}>
                  <div className="card">
                    <h3>Hours and pickup</h3>
                    {c.hours ? <p>{c.hours}</p> : null}
                    {cities.length ? <p>Pickup on request in {cities.join(" and ")}.</p> : null}
                    {cutoff != null ? <p>Book before {fmtHour(cutoff)} for same-day pickup.</p> : null}
                  </div>
                </Reveal>
              ) : null}
            </div>
          </>
        ) : null}
        <div className="write page-split">
          <div className="page-split-side">
            <h2 id="write-title">Send us a message</h2>
            {c.hours ? <p className="from">We reply {c.hours}</p> : null}
          </div>
          <div className="page-split-body">
            <ContactForm destinations={dests} whatsapp={co.whatsapp} token={issueFormToken()} />
          </div>
        </div>
      </section>
      <CtaBand whatsapp={co.whatsapp} phone={co.phone} title={c.ctaTitle} sub={c.ctaSub} />
    </>
  );
}
