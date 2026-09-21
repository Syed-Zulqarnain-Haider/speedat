import { headers } from "next/headers";
import Link from "next/link";
import { UI } from "@/components/Icons";
import { Nav } from "@/components/site/Nav";
import { ShellWrap } from "@/components/site/ShellWrap";
import { Tagline } from "@/components/site/Tagline";
import { ThemeToggle } from "@/components/site/ThemeToggle";
import { Pull } from "@/components/site/fx/Pull";
import { fmtDate } from "@/lib/pricing/format";
import { getLiveSite } from "@/lib/site/live";
import { readTheme } from "@/lib/site/theme";

const PAGES = [
  { href: "/", label: "Get a price" },
  { href: "/services", label: "Services" },
  { href: "/about", label: "About us" },
  { href: "/contact", label: "Contact" },
  { href: "/faq", label: "FAQ" },
] as const;

/**
 * The customer shell: a 64px header (brand · nav · Call / WhatsApp / theme),
 * the page, and a three-column footer. Everything sits inside `.site.wrap`,
 * the scope every customer style hangs from. Light by default; the toggle
 * is seeded from the same cookie the root layout read.
 */
export default async function SiteLayout({ children }: LayoutProps<"/">) {
  // Reading the nonce makes every page dynamic, which the per-request CSP requires; site data stays cached.
  const nonce = (await headers()).get("x-nonce") ?? undefined;
  const [site, theme] = await Promise.all([getLiveSite(), readTheme()]);
  const c = site.company;
  const ct = site.content;
  const wa = `https://wa.me/${c.whatsapp}`;
  const tel = c.phone ? `tel:${c.phone.replace(/[^0-9+]/g, "")}` : "";
  // Below 720 (and 1024–1199) the brand splits into "Speedat" / "International Courier" so both lines stay whole beside the buttons.
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
          <Link className={`brand-link${split ? " has-short" : ""}`} href="/" aria-label={`${c.name} — home`}>
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
            {tel ? (
              <a className="btn outline call" href={tel} aria-label={`Call ${c.phone}`}>
                <UI.phone />
                <span className="call-num">Call</span>
              </a>
            ) : null}
            <Pull>
              {/* The name stays "WhatsApp us" at every width; below 560 the words hide and the glyph alone fills the 48px square. */}
              <a className="btn wa" href={wa} target="_blank" rel="noopener" aria-label="WhatsApp us">
                <UI.wa />
                <span className="wa-word">
                  WhatsApp<span className="wa-us"> us</span>
                </span>
              </a>
            </Pull>
            <ThemeToggle initial={theme} />
          </div>
        </div>
      </header>
      <main>{children}</main>
      <footer className="site-foot">
        <div className="wrap">
          <div className="foot-grid">
            <div className="foot-brand">
              <h3 className="foot-name">{c.name}</h3>
              <p className="brand-tag">{c.tagline}</p>
              {ct.address ? <p className="foot-address">{ct.address}</p> : null}
            </div>
            <div>
              <h3>Pages</h3>
              <ul>
                {PAGES.map((p) => (
                  <li key={p.href}>
                    <Link href={p.href}>
                      <span className="link">{p.label}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
            <div>
              <h3>Contact</h3>
              <ul>
                <li>
                  <a href={wa} target="_blank" rel="noopener">
                    <span className="link">WhatsApp +{c.whatsapp}</span>
                  </a>
                </li>
                {tel ? (
                  <li>
                    <a href={tel}>
                      <span className="link">Call {c.phone}</span>
                    </a>
                  </li>
                ) : null}
                {c.email ? (
                  <li>
                    <a href={`mailto:${c.email}`}>
                      <span className="link">{c.email}</span>
                    </a>
                  </li>
                ) : null}
                {ct.hours ? <li>{ct.hours}</li> : null}
              </ul>
            </div>
          </div>
          <div className="foot-bottom">
            <span>
              {site.version ? `Rates updated ${fmtDate(site.publishedAt)} · ` : ""}© {new Date().getFullYear()} {c.name}
            </span>
          </div>
        </div>
      </footer>
      <script type="application/ld+json" nonce={nonce} dangerouslySetInnerHTML={{ __html: JSON.stringify(ld).replace(/</g, "\\u003c") }} />
    </ShellWrap>
  );
}
