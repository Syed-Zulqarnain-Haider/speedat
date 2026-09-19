import type { Metadata } from "next";
import { CardIcons } from "@/components/Icons";
import { CtaBand } from "@/components/site/CtaBand";
import { fmtHour, fmtPhone } from "@/lib/pricing/format";
import { getLiveSite } from "@/lib/site/live";
import { originCities } from "@/lib/site/text";

export const metadata: Metadata = { title: "Contact" };

export default async function ContactPage() {
  const site = await getLiveSite();
  const c = site.content;
  const co = site.company;
  const cities = originCities(co);
  const mapHref = c.mapUrl || (c.address ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(c.address)}` : "");
  const cutoff = site.settings.cutoffHour;
  return (
    <section className="page">
      <h1>Contact us</h1>
      <p className="lede">
        WhatsApp is the fastest way to reach us. We reply during working hours and confirm every pickup in the chat.
      </p>
      <div className="contact-grid">
        <div className="card">
          <CardIcons.phone />
          <h3>WhatsApp</h3>
          <div className="big">{fmtPhone(co.whatsapp)}</div>
          <p>Quotes, bookings and tracking updates.</p>
          <a className="btn wa small" href={`https://wa.me/${co.whatsapp}`} target="_blank" rel="noopener">
            Open WhatsApp
          </a>
        </div>
        <div className="card">
          <CardIcons.clock />
          <h3>Call</h3>
          <div className="big">{co.phone}</div>
          {c.phone2 ? <p>{c.phone2}</p> : <p>{c.hours}</p>}
          {co.phone ? (
            <a className="btn small" href={`tel:${co.phone.replace(/[^0-9+]/g, "")}`}>
              Call now
            </a>
          ) : null}
        </div>
        <div className="card">
          <CardIcons.doc />
          <h3>Email</h3>
          <div className="big">{co.email}</div>
          <p>For invoices, business accounts and cargo enquiries.</p>
          {co.email ? (
            <a className="btn small" href={`mailto:${co.email}`}>
              Write to us
            </a>
          ) : null}
        </div>
      </div>
      <h2>Visit us</h2>
      <div className="two">
        <div className="card">
          <h3>Office</h3>
          <p>{c.address}</p>
          {mapHref ? (
            <p style={{ marginTop: 10 }}>
              <a href={mapHref} target="_blank" rel="noopener">
                Open in Google Maps
              </a>
            </p>
          ) : null}
        </div>
        <div className="card">
          <h3>Hours and pickup</h3>
          <p>{c.hours}</p>
          {cities.length ? <p style={{ marginTop: 8 }}>Pickup on request in {cities.join(" and ")}.</p> : null}
          {cutoff != null ? <p style={{ marginTop: 8 }}>Book before {fmtHour(cutoff)} for same-day pickup.</p> : null}
        </div>
      </div>
      <CtaBand whatsapp={co.whatsapp} />
    </section>
  );
}
