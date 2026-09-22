import { headers } from "next/headers";
import Link from "next/link";
import type { ReactNode } from "react";
import { UI } from "@/components/Icons";
import { Nav } from "@/components/site/Nav";
import { ShellWrap } from "@/components/site/ShellWrap";
import { ThemeToggle } from "@/components/site/ThemeToggle";
import { fmtDate, fmtPhone } from "@/lib/pricing/format";
import { getLiveSite } from "@/lib/site/live";
import { readTheme } from "@/lib/site/theme";

/**
 * The customer shell (brief v3 §3): one header row — the mark, the name,
 * Call and WhatsApp; the page links only from 1024 — the page, and a
 * two-line footer: the five page links on one line (on a phone the site's
 * only nav), then one wrapping line of facts with every number a plain
 * link. The theme toggle is not navigation, so it sits beside the nav, not
 * in it: at the right end of the links' row from 720, and on a phone —
 * where five links already fill the row — in the corner of the facts line.
 * The shell is a flex column, so on a short page the footer still sits at
 * the bottom of the window. Light by default; the toggle is seeded from
 * the same cookie the root layout read.
 */
export default async function SiteLayout({ children }: LayoutProps<"/">) {
  // Reading the nonce makes every page dynamic, which the per-request CSP requires; site data stays cached.
  const nonce = (await headers()).get("x-nonce") ?? undefined;
  const [site, theme] = await Promise.all([getLiveSite(), readTheme()]);
  const c = site.company;
  const ct = site.content;
  const wa = `https://wa.me/${c.whatsapp}`;
  const tel = c.phone ? `tel:${c.phone.replace(/[^0-9+]/g, "")}` : "";
  // The first word of the name ("Speedat") is the phone header's brand; the rest joins it from 1200.
  const nameWords = c.name.trim().split(/\s+/);
  const first = nameWords[0] ?? c.name;
  const rest = nameWords.slice(1).join(" ");
  // The footer line: every fact that is set, in one order, separated by a middle dot; blank fields skip their slot.
  const facts: ReactNode[] = [];
  if (c.name) facts.push(c.name);
  if (c.tagline) facts.push(c.tagline);
  if (ct.address) facts.push(ct.address);
  if (c.whatsapp)
    facts.push(
      <>
        WhatsApp{" "}
        <a href={wa} target="_blank" rel="noopener">
          {fmtPhone(c.whatsapp)}
        </a>
      </>,
    );
  if (tel)
    facts.push(
      <>
        Call <a href={tel}>{c.phone}</a>
      </>,
    );
  if (c.email) facts.push(<a href={`mailto:${c.email}`}>{c.email}</a>);
  if (ct.hours) facts.push(ct.hours);
  if (site.version) facts.push(`Rates updated ${fmtDate(site.publishedAt)}`);
  facts.push(`© ${new Date().getFullYear()}`);
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
        <div className="head-row">
          <Link className="brand-link" href="/" aria-label={`${c.name} — home`}>
            <span className="mark" aria-hidden="true" />
            <span className="brand-name" aria-hidden="true">
              {first}
              {rest ? <span className="brand-rest"> {rest}</span> : null}
            </span>
          </Link>
          <Nav />
          <div className="head-actions">
            {tel ? (
              <a className="btn outline call" href={tel} aria-label={`Call ${c.phone}`}>
                <UI.phone />
                <span className="call-word">
                  Call<span className="call-num"> {c.phone}</span>
                </span>
              </a>
            ) : null}
            <a className="btn wa" href={wa} target="_blank" rel="noopener" aria-label="WhatsApp us">
              <UI.wa />
              <span className="wa-word">WhatsApp us</span>
            </a>
          </div>
        </div>
      </header>
      <main>{children}</main>
      <footer className="site-foot">
        <Nav label="Pages" />
        <ThemeToggle initial={theme} />
        <p className="foot-line">
          {facts.map((f, i) => (
            <span key={i} className="foot-fact">
              {f}
            </span>
          ))}
        </p>
      </footer>
      <script type="application/ld+json" nonce={nonce} dangerouslySetInnerHTML={{ __html: JSON.stringify(ld).replace(/</g, "\\u003c") }} />
    </ShellWrap>
  );
}
