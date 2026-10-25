# SEO — what the site emits, how to change it, how to check it

The binding requirement is `docs/quality-bar.md` §1. This file is the operator's
half of it: what each page actually puts in its head, which owner-editable field
feeds which tag, and what to do on the day the domain is pointed.

Everything is **server-rendered**. Nothing in here needs JavaScript, and a
crawler that executes none still sees the whole head.

Code: `app/Domain/Seo/**` (namespace `Speedat\Domain\Seo`), the head template
`app/Views/partials/seo.php`, the tests `tests/Seo/**`, and the shipped images
`public/assets/img/og-default.png`, `og-default.svg`, `og-logo.png`.

---

## 1. The shape of it

| Class | What it is for |
| --- | --- |
| `Url` | The site's address, and the only place a URL is built or a path normalised. `app.url` beats the request. |
| `Routes` / `Route` | The five public pages, the non-indexable `error()` route, and the two prefixes crawlers are kept out of. |
| `SiteFacts` | The published document, read the way SEO needs it (headline, FAQ, services, countries, price range). |
| `Meta` | Title, description, canonical, robots, Open Graph, Twitter. |
| `JsonLd` | The `<script type="application/ld+json">` graph. |
| `ShareCard` | What is on the share image, where it is, how big it is. |
| `ShareCardRenderer` | Draws the PNGs with GD. **Build-time only** — the server never runs it. |
| `Sitemap` / `Robots` | `/sitemap.xml` and `/robots.txt`, each as a body + a content type. |
| `Head` | The facade a page controller uses. |
| `OpeningHours` / `Address` | Two parsers that turn owner prose into schema.org structure. |

### Wiring a page

```php
use Speedat\Domain\Seo\Head;
use Speedat\Domain\Seo\Url;

$seo      = Head::forKey($facts, Url::fromConfig($requestOrigin), 'faq');
$headHtml = $view->partial('partials/seo', ['seo' => $seo]);
```

`$headHtml` is the layout's head slot. When the slot is filled the layout must
print **none** of its own `<title>`, `<meta name="description">` or
`<meta name="robots">` — the partial prints all three, and two of each is worse
than none.

`$facts` is a `SiteFacts`: the published `SiteData`, plus the version number and
the publish timestamp.

```php
$facts = new SiteFacts($liveDocument, $version, $publishedAt);
```

---

## 2. What each page emits

Every **indexable** page — the five in the table below. Error pages differ in
two tags and are covered in §2a.

* `<title>` — the page's own name, an em dash, the company name. Deduplicated:
  a page already named after the company does not say it twice.
* `<meta name="description">` — see the table below. Clamped to 160 characters
  on a word boundary.
* `<link rel="canonical">` — absolute, no query, no fragment, no trailing slash.
* `<meta name="robots">` — `index, follow, max-image-preview:large,
  max-snippet:-1, max-video-preview:-1`.
* `og:type`, `og:site_name`, `og:title`, `og:description`, `og:url`, `og:locale`,
  `og:image` (+ `:type`, `:width`, `:height`, `:alt`).
* `twitter:card=summary_large_image`, `twitter:title`, `twitter:description`,
  `twitter:image`, `twitter:image:alt`.
* JSON-LD `LocalBusiness`.

And per page:

| Page | Title leaf | Extra JSON-LD |
| --- | --- | --- |
| `/` | Get a price | — |
| `/services` | Services | `BreadcrumbList`, one `Service` per `content.services` line |
| `/about` | About us | `BreadcrumbList` |
| `/contact` | Contact | `BreadcrumbList` |
| `/faq` | Questions | `BreadcrumbList`, `FAQPage` |

`<html lang="en">`, the charset, the viewport, the stylesheets and the icons stay
in the layout; the one `<h1>` per page, the landmarks and the image `alt`s and
dimensions belong to the page templates (`docs/assets.md`).

### 2a. The error pages (404, 500)

An error page is not one of the five routes, so it gets its head from
`Routes::error()`:

```php
$seo = Head::forRoute($facts, Url::fromConfig($origin), Routes::error('Page not found'));
```

It emits a title, a description, the whole share set and the `LocalBusiness`,
and it differs from a real page in exactly three ways:

* `robots` is `noindex, nofollow`.
* There is **no `<link rel="canonical">`**. A canonical is itself an indexing
  instruction, and a page that says "do not index me" and "index me as X" in
  the same head has told a crawler two different things. A 404 whose canonical
  names a real URL is the standard way an error page ends up in the index under
  that URL's name.
* There is **no `og:url`**, and the route's path is fixed at `/` rather than
  taken from the request, so nothing a visitor typed into the address bar is
  reflected into the head at all.

The status code is the routing layer's job (`404`, `500` — never `200`); the
head is this layer's. The same route is the right one for any future page that
must stay out of the index.

---

## 3. Which field feeds which tag

Everything below is an **owner-editable field of the published document**. Edit
it in the admin, publish, and the tag changes. Nothing else is editable without
changing code.

| Tag | Field | When the field is blank |
| --- | --- | --- |
| `<title>` (company half) | `company.name` | the leaf title stands alone |
| description, `/` | `content.heroSub` | generated: "*Express and Normal* courier prices from *Pakistan* by country and weight. Book on WhatsApp." |
| description, `/services` | `content.servicesLede` | the generated sentence above |
| description, `/about` | first line of `content.story` | the generated sentence above |
| description, `/contact` | `content.contactLede` | the generated sentence above |
| description, `/faq` | `content.faqLede` | the generated sentence above |
| `og:site_name` | `company.name` | omitted |
| `og:image:alt`, card headline | `content.heroTitle` | generated: "*Lahore* to *United Kingdom, United States, Canada and 7 more countries.*" from `company.originCities` and the **active** destinations |
| `LocalBusiness.name` | `company.name` | — |
| `.slogan` | `company.tagline` | omitted |
| `.telephone` | `company.phone` | falls back to the WhatsApp number |
| `.email` | `company.email` | omitted |
| `.address` | `content.address` | omitted |
| `.openingHoursSpecification` | `content.hours` | omitted (see §4) |
| `.areaServed` | the **active** destinations, in the admin's order | omitted |
| `.contactPoint`, `.sameAs` | `company.whatsapp` | omitted |
| `.priceRange` | computed (see §5) | omitted |
| `.currenciesAccepted` | `settings.currency` | omitted |
| `.hasMap` | `content.mapUrl` | omitted |
| `FAQPage` | `content.faq` (`Question? \| Answer`, one per line) | no `FAQPage` at all |
| `Service` entries | `content.services` (`icon \| Title \| text`) | no `Service` entries |
| sitemap `lastmod` | the published version's timestamp | omitted entirely |

**The rule behind the table:** nothing is invented. A blank field falls back to
another field or to a sentence generated from the rate document — never to a
claim. The FAQ and the service entries are parsed with the *same* two calls the
templates use (`Engine::lines()`, `Engine::parts()`), so the structured data and
the visible page cannot drift apart. A hidden destination is not claimed as
`areaServed`.

---

## 4. Opening hours: the formats that parse

`content.hours` is free text, and the parser refuses rather than guesses. A line
it cannot read is simply left out of the structured data (the visible page still
prints the text as typed). These all work:

```
Monday to Saturday, 9 am – 7 pm
Mon-Sat 09:00-19:00
Monday, Wednesday and Friday 9 am to 1 pm
Sunday 11 am - 3 pm
Monday to Friday, 9 am - 7 pm
Saturday, 10 am - 4 pm          (a second line becomes a second specification)
```

These do **not**, on purpose:

* `Monday to Saturday, 9 - 7` — 9 to 7 with no am/pm could be 09:00–19:00 or a
  typo, and a wrong closing time sends somebody to a locked door.
* `Open most days` — no days, no times, nothing to structure.

---

## 5. `priceRange`, and why it is computed

`priceRange` is the cheapest and the dearest **1 kg parcel** on the site, in the
site's currency — for example `PKR 3,500-PKR 5,500`.

It is produced by `Engine::priceAll()` with exactly the call a country tile
makes (1 kg, one piece, parcel, cheapest priced service) plus the add-ons that
are ticked by default. The low end of the range is therefore the same number as
the home page's "1 kg from PKR 5,000". Computing it any other way would
eventually contradict the page, which is the one thing structured data must
never do. Change the rates, publish, and the range changes with them.

---

## 6. The share card

`public/assets/img/og-default.png` — 1200x630, navy `#0a1a33`, the one orange
`#ea580c`, paper `#f5f0e7`. On it: the brand mark exactly as
`public/assets/img/mark.svg` freezes it, the company name, the tagline as an
eyebrow, the headline sentence the home page prints as its h1, and the domain.
Nothing else — no ratings, no counts, no awards.

`og-default.svg` is the same layout as vectors. `og-logo.png` is the mark on
white, 512x512, for the JSON-LD `logo` (Google wants a raster there, not an
SVG).

**It is a static file.** A scraper fetches it once and caches it for weeks, so
nothing is generated per request and the server needs no GD.

### Regenerating it

On a machine with GD + FreeType and the repository's `node_modules` present
(the font used is Geist, SIL OFL 1.1, which ships inside Next's own
dependencies — `ShareCardRenderer::findFont()` also falls back to Bahnschrift,
Segoe UI, DejaVu or Liberation):

```bash
cd php
php -r '
require "app/Core/Autoloader.php";
Speedat\Core\Autoloader::register(__DIR__ . "/app");
Speedat\Core\Bootstrap::cli(__DIR__);
$facts = new Speedat\Domain\Seo\SiteFacts($liveDocument, $version, $publishedAt);
$card  = new Speedat\Domain\Seo\ShareCard($facts, Speedat\Domain\Seo\Url::fromConfig());
$font  = Speedat\Domain\Seo\ShareCardRenderer::findFont(__DIR__);
print_r((new Speedat\Domain\Seo\ShareCardRenderer($card, $font))->write(__DIR__ . "/public"));
'
```

`$liveDocument` is the published `SiteData`. Commit the three files.

### When it goes stale — regenerate after any of these

* the company name or the tagline changes,
* a destination is added, removed, hidden or renamed (the headline sentence
  names them),
* `content.heroTitle` is typed or cleared,
* **the domain changes** (the card has the host printed on it),
* the brand tokens in `public/assets/css/tokens.css` move (the colours are
  frozen literals in `ShareCard`, since a PNG cannot read a CSS variable).

Scrapers cache by URL. To force WhatsApp and Facebook to refetch, **rename the
file** (`og-default-2.png`) and update `ShareCard::CARD_PNG` — a query string is
not reliable here.

---

## 7. Sitemap and robots

`/sitemap.xml` lists the five public pages and nothing else, with `lastmod`
taken from the published version's timestamp. Before the first publish there is
no honest date and `lastmod` is omitted rather than set to today — a sitemap
that claims everything changed today is one crawlers learn to disbelieve.

`/robots.txt`:

```
User-agent: *
Allow: /
Disallow: /admin
Disallow: /api

Sitemap: https://speedat.com.pk/sitemap.xml
```

The disallow list is `Routes::DISALLOWED`, the same constant the sitemap filters
on, so the two files cannot disagree. **robots.txt is not a security control** —
the admin is protected by a server-side session check on every route; these two
lines only keep a login form out of the index.

Both are returned by classes as a body plus a content type
(`application/xml; charset=UTF-8` and `text/plain; charset=UTF-8`); the routing
layer serves them.

### The URL policy the routing layer must enforce

One URL per page, and the canonical is the trailing-slash-free form:

| Requested | Response |
| --- | --- |
| `/faq` | 200 |
| `/faq/` | **301** to `/faq` |
| `/index.php` | **301** to `/` (already in `app/routes.php`) |
| `http://…` | 301 to `https://…` — **by the host, not by the app** (do not double it) |
| `www.` | 301 to the apex once (or the reverse; pick one) |

The home page's canonical is `https://host/` — the root keeps its slash and
nothing else does.

**Doubled and dotted paths.** `Url::normalise()` is the single normaliser: it
strips the query and the fragment, collapses `//`, resolves `.` and `..`, and
drops the trailing slash. `Routes::isDisallowed()` and `Routes::byPath()` call
it, so `//admin/login` and `/./admin/rates` are recognised as admin paths and
get `noindex` — which matters because LiteSpeed and Apache both hand a doubled
slash to the same handler, and `REQUEST_URI` keeps the doubled slash even when
the filesystem mapping does not. The routing layer should still 301 those forms
to their canonical one; this is the belt to that's braces.

---

## 8. Verifying after go-live

Run these in order. Each one is a pass/fail, not an opinion.

1. **The head, by eye.** `curl -s https://speedat.com.pk/faq | head -40`.
   Exactly one `<title>`, one `rel="canonical"`, and the canonical is the URL
   you asked for.
2. **No development URL anywhere.**
   `curl -s https://speedat.com.pk/sitemap.xml | grep -iE 'localhost|127\.0\.0\.1|\.test'` — no output.
   Same for `/robots.txt`.
3. **Rich Results Test** — <https://search.google.com/test/rich-results> — on
   `/` and on `/faq`. Expect `LocalBusiness` on both and `FAQPage` on `/faq`,
   with no errors. Warnings about optional properties are acceptable; errors are
   not.
4. **Schema Markup Validator** — <https://validator.schema.org/> — paste the
   same two URLs for the full graph, including `BreadcrumbList` and `Service`.
5. **The share card, in the debuggers.** WhatsApp is the one that matters here:
   send the link to yourself in a chat and confirm the card draws. Then
   * Facebook: <https://developers.facebook.com/tools/debug/> ("Scrape Again"),
   * X: <https://cards-dev.twitter.com/validator>,
   * LinkedIn: <https://www.linkedin.com/post-inspector/>.
   If a stale image shows, rename the file (§6) — the scrapers' caches do not
   respect a query string.
6. **Search Console** — <https://search.google.com/search-console>.
   * Add the property and verify it (DNS TXT record on the domain is the form
     that survives a host change).
   * Submit `https://speedat.com.pk/sitemap.xml`.
   * URL Inspection on `/` and `/faq`: "URL is on Google" eventually; on day one
     "Crawled — currently not indexed" is normal, an error is not.
   * Watch Coverage for a week for duplicate-canonical warnings — those are the
     symptom of the trailing-slash redirect not being in place.
7. **Lighthouse**, mobile, on the release build (not the dev server): SEO 100,
   and 90+ on the other three (`docs/quality-bar.md` §1).
8. **The tests**: `php bin/test.php` — 92 SEO tests across `tests/Seo/**`. They
   assert the sitemap against the sitemaps.org schema, the FAQ JSON-LD against
   the rendered answers one for one, that a hostile company name cannot inject
   an element into the head, that no development URL survives a configured
   `app.url`, and that the admin paths are absent from both the sitemap and the
   robots file. (`--filter=` matches **method** names, not classes, so it is not
   a way to run only this suite.)

---

## 9. The domain-switch checklist

The site is built at one address and served at another. In order:

1. Set `app.url` in `config/config.php` to the final absolute origin, **no
   trailing slash**, `https://` — e.g. `https://speedat.com.pk`. This one value
   drives every canonical, every `og:url`, every sitemap entry, the robots
   sitemap line and the share-card URL. Nothing else needs changing.
   If it is left empty the request's own origin is used, and `Url::isLocal()`
   is what flags that: it knows `localhost`, `127.0.0.0/8`, `0.0.0.0`,
   `169.254.0.0/16`, the RFC1918 ranges, the IPv6 loopback `::1` (brackets and
   all), `fc00::/7`, `fe80::/10` and the `.localhost`/`.test`/`.local`/
   `.internal` suffixes. Check it after the switch: `isLocal()` returning true
   in production means `app.url` did not take.
2. Decide `www` or apex, and make the other 301 to it at the host — once, not
   twice.
3. Confirm the host forces `https` and that the app does **not** also redirect
   (a double redirect is a wasted hop on every request).
4. Confirm `/faq/` 301s to `/faq`.
5. **Regenerate the share card** (§6) — the domain is printed on it.
6. Clear OPcache after the deploy (`docs/deploy-hostinger.md`).
7. Run §8 items 1, 2, 3 and 5 against the real domain.
8. Submit the sitemap in Search Console, and — if there was an old site at this
   address — keep its URLs 301ing to the new ones rather than 404ing them.
9. Once HTTPS has been stable for a day or two, turn on HSTS.

---

## 10. Assumptions worth knowing

* **The head slot.** The partial returns a string; the layout must print it and
  drop its own fallback title/description/robots when it does.
* **`SiteFacts` needs the version and the timestamp.** The document object does
  not carry them yet, so they are passed alongside. When the published-version
  type grows them, `SiteFacts` takes them from there instead — one constructor,
  no other change.
* **The card bakes in the document.** It is a static file drawn from the
  document at build time, so §6's staleness list is the price of not generating
  an image on every crawl.
* **`priceRange` needs a priceable rate card.** With every destination hidden it
  is omitted rather than guessed.
