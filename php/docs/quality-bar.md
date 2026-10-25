# The quality bar for the PHP site

Owner's standing order (2026-09-27): *"the website should be SEO friendly, and
error and bug free, and should not have any security loopholes or leakages."*
This file turns that into things that can be checked. Every phase of the port is
built against it, and the audits at the end score against it.

---

## 1. SEO

The Next.js site already did most of this; the PHP port must not lose it.

**Per page, server-rendered (never injected by JavaScript):**

- A unique `<title>` and `<meta name="description">`, both drawn from the
  owner-editable content fields, with the company name appended to the title.
- `<link rel="canonical">` with the absolute URL, no query string, no trailing
  slash inconsistency. One URL per page — pick trailing-slash-free and redirect
  the other form with 301.
- Open Graph and Twitter tags (`og:title`, `og:description`, `og:url`,
  `og:type=website`, `og:site_name`, `og:image`, `twitter:card=summary_large_image`)
  so a WhatsApp or Facebook share shows a card rather than a bare link — WhatsApp
  is this business's main channel, so the share card matters more than usual.
  The image is a static asset we ship, with its dimensions declared.
- `<html lang="en">`, correct `<meta charset>` and viewport.
- Exactly one `<h1>`, a sane heading order under it, landmarks (`header`, `nav`,
  `main`, `footer`), and descriptive link text (no "click here").
- Every image has `alt` (decorative ones get `alt=""`), and width/height so
  nothing shifts while loading.

**The 14 px floor — the whole site, `/admin` included (decided 2026-09-28):**

- **No text anybody reads renders below 14 px.** Not a tag, not a count, not a
  meta line, not an eyebrow, not a table caption. `1rem = 17px` on this site, so
  the smallest usable step is `0.83rem` (14.1 px).
- The floor covers the **admin as well as the public pages**. It was briefly
  true only of the public site: `tokens.css` raises the type scale inside
  `.site` and left `:root` at the smaller values, so the admin quietly
  inherited 0.7–0.82 rem on `.tag`, `.count`, `.meta` and every eyebrow — 95
  leaf elements under 14 px on the rates page at 1366 px, which is the screen
  the owner keys prices into all day. The admin scale was raised to meet the
  floor (`admin.css`, `.admin-app`/`.login`); the check was **not** narrowed to
  let the admin off. Anything the owner reads to do their job is text somebody
  reads.
- It is enforced, not hoped for: `contract.nothingUnderMinFont()` measures the
  computed font size of every visible leaf element and the twelve browser flows
  call it on the public pages, the quote panels **and** the admin editors
  (`docs/browser-tests.md`). `tests/Core/AdminTypeScaleTest.php` holds the same
  line in the stylesheet, so a regression is caught without a browser.
- If a future design genuinely needs smaller text somewhere, it changes **this
  paragraph first** and the harness with it. A number in a stylesheet that the
  contract does not know about is how the two drifted apart the first time.

**Structured data (JSON-LD, validated against schema.org):**

- `LocalBusiness` on every page: name, telephone, email, address, opening hours,
  `areaServed` from the live destination list, `priceRange`, the logo and the
  WhatsApp link as `contactPoint`.
- `FAQPage` on the FAQ page, generated from the same content field that renders
  the visible answers — never fabricated, never different from what a visitor
  reads.
- `BreadcrumbList` on inner pages.
- `Service` entries for Express / Economy / Documents / Cargo on the services page.

**Site-level:**

- `/sitemap.xml` generated from the real routes with `lastmod` taken from the
  published version's timestamp, and `/robots.txt` pointing at it. Both served by
  the app, both valid XML/text, no localhost URLs.
- Clean, readable URLs (`/services`, `/contact`, `/faq`) — no query-string routing.
- A real 404 page with the site's own layout that returns **status 404** (not 200),
  and a 500 page that returns 500 without leaking anything.
- Correct redirects: `http` → `https` is handled by the host (do not double it),
  `www` → apex (or the reverse) once, with 301.
- `hreflang` is not needed (one language), but the content language is declared.

**Performance, because it is ranking-relevant and this audience is on phones:**

- The largest element on screen is server-rendered text, visible without waiting
  for JavaScript.
- Cumulative layout shift ~0: no element moves once the price lands, images and
  fonts have reserved space, fonts are self-hosted with `font-display: swap`.
- Assets cache-busted by `filemtime()` and served with long expiry; gzip on;
  no render-blocking third-party anything (the CSP forbids it anyway).
- Target: Lighthouse **90+ on mobile** for performance, accessibility, best
  practices and SEO, measured on the release build, not the dev server.

**Content honesty (carried over from the current site):** no invented reviews,
ratings, client logos, counts or awards in markup or structured data. Numbers
come from the live rates and settings.

---

## 2. Security — what "no loopholes" means here

Mapped to how this application is actually attacked. Every item is a test or a
check in the audit, not a hope.

**Input and output**
- Every database call is a prepared statement with bound parameters. No string
  interpolation into SQL, ever, including `ORDER BY` (allow-list the column).
- Every value printed into HTML goes through the escaping helper; the templates
  escape by default and raw output is a named, reviewed exception.
- JSON embedded for the browser is encoded with `JSON_HEX_TAG | JSON_HEX_AMP |
  JSON_HEX_APOS | JSON_HEX_QUOT` so it cannot break out of the script tag.
- All input is validated against an explicit type and a length cap that matches
  the database column, and rejected politely — never a 500.

**Authentication and session**
- Passwords with `password_hash` (argon2id where available), rehash on login,
  constant-time verification, and a minimum length policy.
- Google sign-in verifies the token signature, issuer, audience and expiry
  against Google's published keys; no service-account key is stored on the server.
- Second factor required for both login paths; codes are single-use (replay
  guard) and the secret is encrypted at rest with the app key.
- Session id rotates on login and on privilege change; cookie is `HttpOnly`,
  `SameSite=Lax`, `Secure` on HTTPS; idle and absolute expiry; server-side
  session records so a session can be revoked.
- Login, password-reset and 2FA endpoints are rate-limited per IP **and** per
  account, with a uniform error message and no user enumeration.

**Authorisation**
- Every admin route re-checks the session server-side; hiding a link is never the
  control. Owner-only actions (publish, resume prices, manage admins) re-check
  the role.
- Object access is checked against the signed-in admin, not the URL.

**CSRF and clickjacking**
- Every state-changing request carries a per-session token verified with
  `hash_equals`; `SameSite=Lax` is the second layer.
- `X-Frame-Options: DENY` / `frame-ancestors 'none'`.

**File upload (the rate sheets)**
- Extension **and** magic bytes checked, size capped, stored outside the web root,
  never executed, filenames never taken from the client, zip bombs bounded by
  a row/column/expanded-size cap.

**Headers and transport**
- Content-Security-Policy with a per-request nonce, `default-src 'self'`, no
  `unsafe-inline`, no external origins except the ones Google sign-in needs.
- `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy` denying camera/microphone/geolocation, HSTS once HTTPS is
  confirmed stable.
- Secrets live above the web root; a request for the config path returns 404/403
  even if `.htaccess` is ignored, because the file is not under the web root at all.

**Errors, logging, leakage**
- Visitors never see a stack trace, a file path, a query or a driver message;
  the detail goes to a log file above the web root.
- `expose_php` off; no version banners; no directory listings; no `.git`, `.sql`,
  `.md`, `.log`, backup or editor files reachable.
- The audit log is append-only and records who did what, from which IP (hashed),
  with what payload summary.
- Nothing sensitive is logged: no passwords, no tokens, no full card-like numbers,
  no customer message bodies beyond what the inbox needs.

**Abuse**
- Per-IP rate limits on the quote endpoint, the contact form, login and the cron
  endpoint; the contact form also has a signed token and a honeypot.
- The cron and metrics endpoints require a bearer token compared with
  `hash_equals`; the inbound-email webhook requires its own secret.
- Input caps make a giant payload a 400, not a memory exhaustion.

**Hosting reality (see `hostinger-constraints.md`)**
- No process spawning, no symlinks, one non-persistent database connection per
  request, everything UTC internally, OPcache reset after deploy, ModSecurity
  exceptions scoped to the admin path only.

---

## 3. "Error and bug free" — how it is demonstrated

Claims are not accepted; evidence is. Before the site is called ready:

1. **Unit and integration tests** for every domain rule, run on PHP 8.2 and
   parse-checked on 8.3. The pricing engine additionally proves parity with the
   940 golden vectors, in PHP and in the browser.
2. **A full flow walk in a real browser** (Playwright + Chrome), the same one the
   Next.js site passed: quick rate on laptop and phone, detailed quote, documents,
   cargo, the hold panel, contact form → inbox → shipment, the rates editor,
   publish and restore, the Excel import, settings, website pages, login with
   both methods and 2FA, and every ops endpoint. Each flow is pass/fail with
   screenshots.
3. **An adversarial security audit** of the PHP code: independent finders per
   surface, each finding challenged by separate verifiers before it is fixed.
4. **A misuse pass**: double submits, back button after publish, two admins
   editing at once, an expired session mid-form, a 404 in every language of the
   URL space, a database that is down, a disk that is full, an upload that is
   not a spreadsheet, a clock that jumps.
5. **Lighthouse** on the release build, mobile and desktop, recorded.
6. Every check re-run after the last fix — not before it.
