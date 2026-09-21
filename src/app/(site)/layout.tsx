import { headers } from "next/headers";
import Link from "next/link";
import { CardIcons, UI } from "@/components/Icons";
import { Nav } from "@/components/site/Nav";
import { ShellWrap } from "@/components/site/ShellWrap";
import { Tagline } from "@/components/site/Tagline";
import { Pull } from "@/components/site/fx/Pull";
import { fmtDate } from "@/lib/pricing/format";
import { getLiveSite } from "@/lib/site/live";

const PAGES = [
  { href: "/", label: "Get a quote" },
  { href: "/services", label: "Services" },
  { href: "/about", label: "About us" },
  { href: "/contact", label: "Contact" },
  { href: "/faq", label: "FAQ" },
] as const;

export default async function SiteLayout({ children }: LayoutProps<"/">) {
  // Reading the nonce makes every page dynamic, which the per-request CSP requires; site data stays cached.
  const nonce = (await headers()).get("x-nonce") ?? undefined;
  const site = await getLiveSite();
  const c = site.company;
  const ct = site.content;
  const wa = `https://wa.me/${c.whatsapp}`;
  // Below 640 the brand splits into "Speedat" / "International Courier" so it never wraps to three lines beside two buttons.
  const nameWords = c.name.trim().split(/\s+/);
  const split = nameWords.length > 1 ? { first: nameWords[0], rest: nameWords.slice(1).join(" ") } : null;
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
        <div className="wrap head-row">
          <Link className={`brand-link${split ? " has-short" : ""}`} href="/">
            <span className="mark" aria-hidden="true" />
            <span className="brand-full">
              <span className="brand-name">{c.name}</span>
              <Tagline text={c.tagline} />
            </span>
            {split ? (
              <span className="brand-short" aria-hidden="true">
                <span className="brand-name">{split.first}</span>
                <span className="brand-tag">{split.rest}</span>
              </span>
            ) : null}
          </Link>
          <Nav />
          <div className="head-actions">
            {c.phone ? (
              <a className="btn outline small call" href={`tel:${c.phone.replace(/[^0-9+]/g, "")}`} aria-label={`Call ${c.phone}`}>
                <CardIcons.phone className="ico-sm" />
                <span className="call-num">{c.phone}</span>
              </a>
            ) : null}
            <Pull>
              <a className="btn wa small" href={wa} target="_blank" rel="noopener">
                <UI.wa />
                WhatsApp<span className="wa-us"> us</span>
              </a>
            </Pull>
          </div>
        </div>
      </header>
      <main>{children}</main>
      <footer className="site-foot">
        <span className="ghost" aria-hidden="true">
          {c.tagline}
        </span>
        <div className="wrap">
          <div className="foot-grid">
            <div className="foot-brand">
              <h3 className="foot-name">{c.name}</h3>
              <p className="brand-tag">{c.tagline}</p>
              {ct.address ? <p className="foot-address">{ct.address}</p> : null}
            </div>
            <div>
              <h3 className="eyebrow">Pages</h3>
              <ul>
                {PAGES.map((p) => (
                  <li key={p.href}>
                    <Link className="link" href={p.href}>
                      {p.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
            <div>
              <h3 className="eyebrow">Contact</h3>
              <ul>
                <li>
                  <a className="link" href={wa} target="_blank" rel="noopener">
                    WhatsApp +{c.whatsapp}
                  </a>
                </li>
                {c.phone ? <li>{c.phone}</li> : null}
                {c.email ? (
                  <li>
                    <a className="link" href={`mailto:${c.email}`}>
                      {c.email}
                    </a>
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
        </div>
      </footer>
      <script type="application/ld+json" nonce={nonce} dangerouslySetInnerHTML={{ __html: JSON.stringify(ld).replace(/</g, "\\u003c") }} />
    </ShellWrap>
  );
}
