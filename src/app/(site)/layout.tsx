import { headers } from "next/headers";
import Link from "next/link";
import { CardIcons } from "@/components/Icons";
import { Nav } from "@/components/site/Nav";
import { ShellWrap } from "@/components/site/ShellWrap";
import { Tagline } from "@/components/site/Tagline";
import { fmtDate } from "@/lib/pricing/format";
import { getLiveSite } from "@/lib/site/live";

export default async function SiteLayout({ children }: LayoutProps<"/">) {
  // Reading the nonce makes every page dynamic, which the per-request CSP requires; site data stays cached.
  const nonce = (await headers()).get("x-nonce") ?? undefined;
  const site = await getLiveSite();
  const c = site.company;
  const ct = site.content;
  const wa = `https://wa.me/${c.whatsapp}`;
  const ld = {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    name: c.name,
    telephone: c.phone || undefined,
    email: c.email || undefined,
    address: ct.address || undefined,
    openingHours: ct.hours || undefined,
    areaServed: site.destinations.filter((x) => x.active).map((x) => x.name),
  };
  return (
    <ShellWrap>
      <header className="site-head">
        <div className="brand-row">
          <Link className="brand-link" href="/">
            <span className="mark" aria-hidden="true" />
            <span>
              <span className="brand-name">{c.name}</span>
              <Tagline text={c.tagline} />
            </span>
          </Link>
          <div className="head-actions">
            {c.phone ? (
              <a className="btn small call" href={`tel:${c.phone.replace(/[^0-9+]/g, "")}`} aria-label={`Call ${c.phone}`}>
                <CardIcons.phone className="ico-sm" />
                <span className="call-num">{c.phone}</span>
              </a>
            ) : null}
            <a className="btn wa small" href={wa} target="_blank" rel="noopener">
              WhatsApp us
            </a>
          </div>
        </div>
        <Nav />
      </header>
      <main>{children}</main>
      <footer className="site-foot">
        <div className="foot-grid">
          <div>
            <h3>{c.name}</h3>
            <p>{c.tagline}</p>
            {ct.address ? <p style={{ marginTop: 6 }}>{ct.address}</p> : null}
          </div>
          <div>
            <h3>Pages</h3>
            <ul>
              <li>
                <Link href="/">Get a quote</Link>
              </li>
              <li>
                <Link href="/services">Services</Link>
              </li>
              <li>
                <Link href="/about">About us</Link>
              </li>
              <li>
                <Link href="/contact">Contact</Link>
              </li>
              <li>
                <Link href="/faq">FAQ</Link>
              </li>
            </ul>
          </div>
          <div>
            <h3>Contact</h3>
            <ul>
              <li>
                <a href={wa} target="_blank" rel="noopener">
                  WhatsApp +{c.whatsapp}
                </a>
              </li>
              {c.phone ? <li>{c.phone}</li> : null}
              {c.email ? (
                <li>
                  <a href={`mailto:${c.email}`}>{c.email}</a>
                </li>
              ) : null}
              {ct.hours ? <li>{ct.hours}</li> : null}
            </ul>
          </div>
        </div>
        <div className="foot-bottom">
          <span>
            Rates updated {site.version ? fmtDate(site.publishedAt) : "—"} · © {new Date().getFullYear()} {c.name}
          </span>
        </div>
      </footer>
      <script type="application/ld+json" nonce={nonce} dangerouslySetInnerHTML={{ __html: JSON.stringify(ld).replace(/</g, "\\u003c") }} />
    </ShellWrap>
  );
}
