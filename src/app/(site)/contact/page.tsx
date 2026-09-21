import type { Metadata } from "next";
import { CardIcons } from "@/components/Icons";
import { ContactForm } from "@/components/site/ContactForm";
import { CtaBand } from "@/components/site/CtaBand";
import { PageHead } from "@/components/site/PageHead";
import { Reveal } from "@/components/site/Reveal";
import { issueFormToken } from "@/lib/form-token";
import { fmtHour, fmtPhone } from "@/lib/pricing/format";
import { getLiveSite } from "@/lib/site/live";
import { originCities } from "@/lib/site/text";

export const metadata: Metadata = { title: "Contact" };

/** 04 — Contact: the contact sheet (WhatsApp, call, email), visit us, the message form, the stamp band. */
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
  const tel = co.phone ? `tel:${co.phone.replace(/[^0-9+]/g, "")}` : "";
  return (
    <>
      <section className="page">
        <PageHead no="04" name="Contact" title="Contact *us*" lede={c.contactLede} />
        <Reveal distance={24}>
          <div className="card contact-sheet">
            <div className="contact-row">
              <div>
                <p className="eyebrow lab">
                  <CardIcons.phone />
                  WhatsApp
                </p>
                <p className="contact-big">{fmtPhone(co.whatsapp)}</p>
                <p className="contact-note">Quotes, bookings and tracking updates.</p>
              </div>
              <a className="btn wa" href={`https://wa.me/${co.whatsapp}`} target="_blank" rel="noopener">
                Open WhatsApp
              </a>
            </div>
            {co.phone ? (
              <div className="contact-row">
                <div>
                  <p className="eyebrow lab">
                    <CardIcons.clock />
                    Call
                  </p>
                  <p className="contact-big">{co.phone}</p>
                  {c.phone2 || c.hours ? <p className="contact-note">{c.phone2 || c.hours}</p> : null}
                </div>
                <a className="btn outline" href={tel}>
                  Call now
                </a>
              </div>
            ) : null}
            {co.email ? (
              <div className="contact-row">
                <div>
                  <p className="eyebrow lab">
                    <CardIcons.doc />
                    Email
                  </p>
                  <p className="contact-big">{co.email}</p>
                  <p className="contact-note">For invoices, business accounts and cargo enquiries.</p>
                </div>
                <a className="btn outline" href={`mailto:${co.email}`}>
                  Write to us
                </a>
              </div>
            ) : null}
          </div>
        </Reveal>
        <div className="visit page-split">
          <div className="page-split-side">
            <p className="eyebrow">Visit us</p>
            <span className="rule" aria-hidden="true" />
            <h2 id="visit-title">Office and hours</h2>
          </div>
          <Reveal className="two page-split-body" delay={0.05} distance={24}>
            {c.address ? (
              <div className="card">
                <h3>Office</h3>
                <p>{c.address}</p>
                {mapHref ? (
                  <p>
                    <a className="link" href={mapHref} target="_blank" rel="noopener">
                      Open in Google Maps ↗
                    </a>
                  </p>
                ) : null}
              </div>
            ) : null}
            <div className="card">
              <h3>Hours and pickup</h3>
              {c.hours ? <p>{c.hours}</p> : null}
              {cities.length ? <p>Pickup on request in {cities.join(" and ")}.</p> : null}
              {cutoff != null ? <p>Book before {fmtHour(cutoff)} for same-day pickup.</p> : null}
            </div>
          </Reveal>
        </div>
        <div className="write page-split">
          <div className="page-split-side">
            <p className="eyebrow">Write to us</p>
            <span className="rule" aria-hidden="true" />
            <h2 id="write-title">Send us a message</h2>
            {c.hours ? <span className="meta">We reply {c.hours}</span> : null}
          </div>
          <div className="page-split-body">
            <ContactForm destinations={dests} whatsapp={co.whatsapp} token={issueFormToken()} />
          </div>
        </div>
      </section>
      <CtaBand whatsapp={co.whatsapp} title={c.ctaTitle} sub={c.ctaSub} eyebrow="Book" />
    </>
  );
}
