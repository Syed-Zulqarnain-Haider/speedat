# Design assets (track C)

Everything the PHP site needs in order to look like the Next.js site: the five
stylesheets, the three font families, the flags, the icons, and the three
behaviour scripts. Nothing here talks to the database, and nothing here is
loaded from another domain — the site's Content-Security-Policy allows
`'self'` only for styles, fonts, images and scripts, and every path below is
under `php/public/assets/`.

The Next.js app remains the specification. Where this document says "today",
it means `/d/speedat/src/**` as it stands, and any disagreement between a file
here and the TypeScript there is a bug here.

---

## 1. Inventory

```
php/public/assets/
  css/
    base.css         Tailwind's preflight, lifted from the built stylesheet   (new)
    fonts.css        @font-face rules + the --font-* variables                (new)
    tokens.css       copy of src/styles/tokens.css                  (byte-identical)
    site.css         copy of src/styles/site.css                    (byte-identical)
    calculator.css   copy of src/styles/calculator.css              (byte-identical)
    admin.css        from src/styles/admin.css              (+4 deliberate edits, §1a)
    print.css        copy of src/styles/print.css                   (byte-identical)
  fonts/
    barlow-{400,500,600}-{latin,latin-ext,vietnamese}.woff2
    bigshoulders-var-{latin,latin-ext,vietnamese}.woff2
    jetbrainsmono-var-{latin,latin-ext,vietnamese,greek,cyrillic,cyrillic-ext}.woff2
    LICENSE.txt      SIL OFL 1.1 + who holds each copyright
  flags/
    271 country SVGs + LICENSE.txt   copy of public/flags/          (byte-identical)
  img/
    favicon.ico      copy of src/app/favicon.ico                    (byte-identical)
    icons.svg        every glyph in src/components/Icons.tsx, as <symbol>s
    icon-wa.svg      the four the header inlines, standalone
    icon-phone.svg
    icon-sun.svg
    icon-moon.svg
    mark.svg         the brand mark as a file (derived — see §4)
    og-default.svg   the social card, in the form it is drawn in
    og-default.png   the same card rasterised — og:image has to be a raster,
                     because no social crawler renders SVG
    og-logo.png      the logo alone, for the JSON-LD `logo` field
    brand/
      speedat-logo.png     the header logo, 1×
      speedat-logo@2x.png  …and 2×, which is the one a phone actually uses
      speedat-logo.webp    the same at 1×, for a browser that asks for webp
  js/
    pricing.js       PHASE 1's — not mine, not to be edited from this track
    ui.js            theme toggle, toasts, nav, <details>
    calculator.js    Calculator.tsx + HoldPanel.tsx
    admin.js         the admin's behaviours
```

Nothing else lives in `public/assets/`, and the one exception that used to —
the throwaway `dev-preview.html` harness beside `css/` — was **deleted on
2026-09-27**, the day the real templates rendered. It had to go, not merely
stop being used: it was plain HTML inside the web root, reachable by anyone who
guessed the path, and it carried an inline `<script>` the site's own CSP
forbids. `bin/build-release.php` refuses any `.html` under `public_html/` for
that reason, so it cannot come back by accident.

What replaced it is `tests/browser/**` for the customer pages, driven against
real routes, and the PHP view tests under `tests/View*/` for the markup. **The
gap §9 used to name is closed**: `tests/browser/flows/` drives the admin
through a real session — `admin-login`, `admin-rates-publish`, `admin-hold`,
`admin-inbox-shipments` and `admin-settings-content` — so the admin's
*rendered* CSS is exercised in a browser on every run, not only the customer
pages. What still is not covered is listed at the end of §9.

Four of the five copied stylesheets were verified byte-identical to
`src/styles/*.css` with `sha256sum`, as were all 271 flags and the favicon.
They are copies, not ports: a change to the customer-facing design belongs in
`src/styles/` first, then here.

### 1a. The one stylesheet that is not byte-identical

`admin.css` carries four edits the Next.js file does not, and each is here
because the PHP product is not the Next.js product:

| edit | why |
|---|---|
| `input[type="file"]` and its `::file-selector-button` removed | the admin has no upload; `AdminDesksTest` and `RatesEditorTest` both assert no rendered `type="file"` |
| `.file-field`, `.map-grid` removed | the wrapper and the column-mapper grid of the deleted import panel; no template emits either |
| `.hist.sheets` removed | the emailed-rate-sheet list; the surviving `.hist` lists are versions, leads, admins, checks, activity, timeline |
| a `@media (max-width: 719px)` block raising controls to 48px | see §7a |

The first three follow the owner's 2026-09-27 decision that the Excel
rate-sheet feature leaves the product (rates CSV **export** stays). Re-copying
`src/styles/admin.css` over this file would put all four back — don't.

---

## 2. Loading the stylesheets

**The order is not negotiable.** It is the order `src/app/globals.css`
imports them in, with the two new files in front:

```html
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/fonts.css">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/site.css">
<link rel="stylesheet" href="/assets/css/calculator.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<link rel="stylesheet" href="/assets/css/print.css">
```

- `base.css` must be first because the other five are written against it:
  they assume `box-sizing: border-box`, zeroed margins and borders, and
  `button { font: inherit }`. It is wrapped in `@layer base`, so every
  unlayered rule in the other files beats it whatever the specificity — the
  same arrangement Tailwind produces in the Next build.
- `fonts.css` must come before `tokens.css` because `tokens.css` and the rest
  read `--font-sans`, `--font-display` and `--font-mono` from it.
- All seven on every page, customer and admin alike. `admin.css` scopes
  itself to `.admin-app`, `.admin`, `.login` and so on, and `calculator.css`
  to `.instrument`, so loading everything costs 160 KB uncompressed and
  changes nothing visually. Serving seven files also lets `.htaccess` set one
  long `Cache-Control` for the whole directory.
- Set `data-theme` on `<html>` **server-side**, from the `theme` cookie, on
  every page: `<html lang="en" data-theme="light">` or `"dark"`. This is the
  whole reason the site has no flash of the wrong theme. `tokens.css` has one
  dark block, `:root[data-theme="dark"]`, and never consults the OS
  preference. Keep `<meta name="theme-color">` in step: `#FFFFFF` light,
  `#0B1424` dark.

`1rem = 17px` — `tokens.css` sets `html { font-size: 17px }`. Do not
"normalise" it.

---

## 3. Fonts

Three families, self-hosted, no external request:

| family | weights | used for |
|---|---|---|
| Barlow | 400, 500, 600 | body text (`--font-sans`) |
| Big Shoulders | variable 100–900, `opsz` axis | display: headlines, prices, the brand name (`--font-display`) |
| JetBrains Mono | 400, 500 | the admin's eyebrows and readouts (`--font-mono`) |

The `.woff2` files are the ones `next/font/google` downloaded when the Next
app was built. They were taken from `.next/static/media/` and matched to
family, weight and subset by the `unicode-range` in
`.next/static/chunks/1fdjqyzxdoxs2.css`, then renamed. `fonts.css` reproduces
that stylesheet's `@font-face` rules exactly, including the two metric-adjusted
`local(Arial)` fallback faces that stop the swap shifting the layout. Big
Shoulders has no fallback face on purpose — `layout.tsx` passes
`adjustFontFallback: false` because next/font has no metric overrides for it.

Notes for phase 3:

- **Preload the two on the LCP path only**, as `layout.tsx` does (Barlow and
  Big Shoulders preload; JetBrains Mono has `preload: false`). The latin
  subsets are the ones English pages actually use:

  ```html
  <link rel="preload" as="font" type="font/woff2" crossorigin
        href="/assets/fonts/barlow-400-latin.woff2">
  <link rel="preload" as="font" type="font/woff2" crossorigin
        href="/assets/fonts/bigshoulders-var-latin.woff2">
  ```

  `crossorigin` is required on font preloads even same-origin, or the file is
  fetched twice.
- The other subsets are gated by `unicode-range` and are never fetched by an
  English page. Verified: on the customer harness the browser loaded Barlow
  400/500/600 and Big Shoulders only; JetBrains Mono loaded on the admin page
  and not before.
- `.htaccess` should serve `.woff2` as `font/woff2` with
  `Cache-Control: public, max-age=31536000, immutable`. The filenames carry no
  hash, so change the name if a file is ever replaced.
- `--font-serif` exists in `fonts.css` for completeness and is used by
  nothing. The Next app maps it to an Instrument Serif that is never loaded;
  design brief v3 §2 puts every customer headline in one face.

---

## 4. Images, flags and icons

**Flags.** `Flag.tsx` becomes plain markup. The file is decorative — the
country's name is always printed beside it — so `alt=""`, and width/height are
always set so nothing shifts as it loads:

```html
<img class="flag" src="/assets/flags/ca.svg" alt="" width="32" height="24" fetchpriority="high">
```

Height is `round(width * 0.75)` (the set is 4:3). Sizes in use: 32 on the
country tiles, 24 on the "1 Country" tick and in the hold panel. The flags on
the country tiles and the tick are **not** lazy — they are the one symbol a
first-time visitor recognises and must be there on the first paint; only the
"Where we deliver" board below the fold passes `loading="lazy"`.

When the country is not in the map, print the badge instead, exactly as
`Flag.tsx` does — the first two letters of the name, upper-cased:

```html
<span class="flag flag-badge" style="width:32px;height:24px" aria-hidden="true">NE</span>
```

PHP owns the name → ISO code mapping (the port of
`src/lib/site/countries.ts`); `calculator.js` never guesses one, it is handed
the codes in its data blob (§6).

**Icons.** `icons.svg` holds every glyph from `src/components/Icons.tsx` as a
`<symbol>`: `#i-*` for the UI glyphs (`UI.*`) and `#c-*` for the eight card
icons (`CardIcons.*`, in `CARD_ICON_ORDER`). **Inline it, do not link it.**
Print the whole file once inside `<body>`, before anything that uses it, then:

```php
<?php readfile(ASSETS . '/img/icons.svg'); ?>
...
<svg class="ico" aria-hidden="true"><use href="#i-wa"></use></svg>
```

Linking (`<use href="icons.svg#i-wa">`) does not work: Chrome dropped external
`<use>` references entirely, and where it does work `currentColor` resolves
against the external document — black icons on the navy WhatsApp band. The
four standalone `icon-*.svg` files are the same paths for a view that wants to
`readfile()` just one.

**The brand mark is CSS, not a file.** `src/app/(site)/layout.tsx` renders an
empty `<span class="mark" aria-hidden="true"></span>` and `site.css` paints the
stripes from `--hot-a`, `--gap`, `--ink` and `--line`, so it follows the theme.
Keep that markup. `img/mark.svg` is a *derived* file for the places CSS cannot
reach — the SVG favicon, an Open Graph image, an emailed quote — and it is the
light theme frozen.

**Favicon.**

```html
<link rel="icon" href="/assets/img/favicon.ico" sizes="any">
<link rel="icon" href="/assets/img/mark.svg" type="image/svg+xml">
```

---

## 5. `ui.js`

One `<script defer src="/assets/js/ui.js">` on every page. It is pure
enhancement — with JavaScript off the site is whole.

What it does:

| behaviour | markup it needs |
|---|---|
| theme toggle | `<button type="button" class="btn outline theme">` (empty; it paints the sun/moon itself) or anything with `data-theme-toggle` |
| toast | none — `Speedat.toast("Quote copied")` |
| admin phone nav | `.anav-top` with `aria-current="page"` on the current link (printed by PHP) |
| smooth in-page jumps | any `a[href^="#"]` — delegated |
| open a `<details>` that a fragment points into | none |

It also exposes, for the other two scripts and for views that need them:
`Speedat.toast`, `Speedat.esc`, `Speedat.readBlob(id)`, `Speedat.ready(fn)`,
`Speedat.revealInDetails(el)`, `Speedat.setTheme("dark")`.

The theme toggle writes the cookie itself:
`theme=dark; Path=/; Max-Age=31536000; SameSite=Lax` (plus `; Secure` on
https). PHP reads that cookie and prints `data-theme` — the two must agree on
the name and the values `light` / `dark`.

**There is no mobile nav drawer**, here or in the Next app. On a phone the
customer nav is the five links in the footer (`site.css` hides the header's
copy below 1024px) and the admin's is a horizontally scrolling row. Do not
add a hamburger; it would not be the same site.

**The FAQ page does not fold.** `/faq` is a `<dl class="faq-list">` with every
answer open — brief v3 §3. The two places that do fold, `details.how` in the
quote and `details.fold` in the admin, are native `<details>` and need no
script.

---

## 6. `calculator.js` — the data blob (what phase 2 must produce)

```html
<div id="quote-instrument" class="instrument">
  <div data-calculator="speedat-calculator">
    <noscript>…the plain WhatsApp way out…</noscript>
  </div>
  …
</div>

<script type="application/json" id="speedat-calculator"><?= $json ?></script>
<script defer src="/assets/js/pricing.js"></script>
<script defer src="/assets/js/ui.js"></script>
<script defer src="/assets/js/calculator.js"></script>
```

- The mount's **existing children are left alone** until the script has a
  document to render, then replaced in one go. Put the no-script way out
  there (a WhatsApp link, a phone number) — that is the only thing a visitor
  without JavaScript will see, and it is also what stays on screen if
  `pricing.js` fails to load.
- `data-calculator` holds the id of the JSON `<script>`. The default is
  `speedat-calculator`.
- Encode with `json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`.
  `JSON_HEX_TAG` is the one that matters: it stops a destination named
  `</script>` ending the block.

### The shape

```jsonc
{
  "flagsBase": "/assets/flags/",     // optional, this is the default
  "quotesUrl": "/api/quotes",        // optional, this is the default
  "cities": ["Lahore"],              // originCities(company), already split and trimmed
  "flags": { "ca": "ca", "np": null },   // destination id -> lowercase ISO code, or null for the badge
  "site": {                          // a PublishedVersion, as lib/site/types.ts defines it
    "version": 12,
    "publishedAt": "2026-09-22T09:14:00.000Z",
    "live": false,
    "company": {
      "name": "…", "tagline": "…", "origin": "Pakistan",
      "whatsapp": "923157667076",    // digits only, with country code
      "phone": "…", "email": "…",
      "includes": "…",               // one paragraph under the detailed view
      "originCities": "Lahore",
      "notes": "one\nper\nline"
    },
    "content": { "promise": "…" },   // the calculator itself reads nothing else from content
    "services": [ { "id": "exp", "name": "Express", "note": "Fastest, tracked door to door" } ],
    "settings": {
      "pricingMode": "grid",         // "slab" | "grid"
      "currency": "PKR",
      "volumetricDivisor": 5000,
      "firstKg": 0.5, "stepKg": 0.5,
      "taxPct": 0, "roundTo": 10, "maxKg": 25,
      "showEta": true,
      "cutoffHour": 15,              // 0-23, or null for none
      "workingDays": "Mon, Tue, Wed, Thu, Fri, Sat",
      "holidays": "2026-12-25 | Christmas",
      "addons": "Pickup and service charges | 500 | on",
      "disclaimer": "…"
    },
    "destinations": [
      {
        "id": "ca", "name": "Canada", "active": true,
        "rates": {
          "exp": { "days": "4-6", "grid": { "1": 5220, "2": 6700 } },
          "eco": { "days": "8-12", "first": 4100, "addl": 900 }
        }
      }
    ]
  }
}
```

Rules:

- `site` is the **published** document and nothing else. No draft, no admin
  fields, no lead data. Every number a visitor can see is derived from it in
  the browser, so anything in it is public.
- **Inactive destinations may be left in or taken out** — the engine and the
  calculator both ignore `active: false`. Leaving them out is smaller.
- `flags` must cover every destination id in `site.destinations`. A missing
  key or a `null` gives the two-letter badge, which is correct for a country
  with no flag file but wrong for one that has one.
- `cities` decides the "From" control: one city renders a static line, more
  than one renders a `<select>`. Empty is allowed.
- `numbers must be numbers`. `"5220"` as a string will price wrong — the
  engine's `isNum` rejects it and the service disappears.
- The tile's "from PKR …" is **not** in the blob. `calculator.js` computes it
  with the same engine call the third tap makes, so the tile and the price can
  never disagree. PHP computes the same figure for the headline and the rates
  board with its own port of `lib/site/copy.ts`; the algorithm is: price 1 kg
  as a package, take the cheapest priced service, add the add-ons that are on
  by default.

### The hold panel

While prices are on hold the rate document must **not** reach the browser.
Render a different mount, with names only:

```html
<div data-hold-panel="speedat-hold"></div>
<script type="application/json" id="speedat-hold">
{
  "companyName": "Speedat International Courier",
  "whatsapp": "923157667076",
  "message": "…the admin's message…",
  "maxKg": 25,
  "destinations": [ { "id": "ca", "name": "Canada" } ]
}
</script>
```

`flags` and `flagsBase` from the calculator blob are reused if both are on the
page; on a hold page, include them in the hold blob's page too (or accept the
two-letter badge beside the chosen country).

### What it expects from `pricing.js`

`calculator.js` does **no pricing arithmetic of its own**. It requires
`window.SpeedatPricing.priceAll` and uses, when present: `isGrid`,
`gridWeights`, `gToKg`, `kgToG`, `effectiveSteps`, `lines`, `toNumLoose`,
`addonsList`.

It also carries its own port of `format.ts`, `dates.ts` and `quote.ts`,
because the brief only promised `engine.ts`. At boot it **adopts** every one
of those helpers that `pricing.js` actually exports, and the port is only a
fallback. With the `pricing.js` phase 1 shipped, all eighteen are adopted:
`fmtNum fmtMoney fmtDay fmtRange fmtHour inputDate localDateFromInput quoteId
quoteIdFor parseDaysRange estimateDelivery piecesText weightSentence
midSentence bookedTotal quoteText waLink cargoText` (plus `holdText` in the
hold panel). That matters: `quoteText` writes the WhatsApp message and the
server writes the inbox line from the same function, and two copies would
eventually word one quote two ways.

The port that is **not** in `pricing.js` and lives only here:
`daysText`, `fromPrice`, and the piece-row helpers from
`src/components/calculator/pieces.ts` (`entry`, `newRow`, `num`,
`metricValue`, `shown`, `toRows`). If `pricing.js` ever grows them, add them
to `adoptFromEngine()`.

### The booking log

Tapping Book fires one `POST` to `quotesUrl` with `keepalive: true` and
`content-type: application/json`, and ignores the result — logging never
blocks a booking. The body is the quote object plus:

```jsonc
{ "…quote fields…",
  "total": 19040,                 // shipping PLUS the add-ons that were on: the number the customer SAW
  "addons": ["Pickup and service charges"],   // labels, in the document's order
  "booked": true,
  "mode": "quick" | "detail",
  "contents": "clothes and a gift"            // detailed view only
}
```

Quote fields: `id from dest destId type service serviceId days eta pickup
pieces piecesText billableG chargeG total docRate version`. `total` inside the
quote is shipping **before** add-ons; the top-level `total` includes them.
The server must re-price from `destId`, `chargeG`/`billableG` and `serviceId`
against the live document and trust nothing in the body — `resolveAddons` in
`pricing.js` is there for exactly that.

### The four rules the file keeps

1. **Nothing scrolls by itself.** There is not one `scrollIntoView`, `focus()`
   or hash write in `calculator.js`.
2. **Tap feedback under 80 ms.** Every handler mutates the DOM synchronously —
   no timers, no debounce, no fetch between the tap and the paint. Measured in
   the harness: two taps, click to painted price, 120–243 ms *including
   Playwright's own round trips*.
3. **Nothing shifts when the price lands.** `.result` carries `data-state` and
   `data-n` and `calculator.css` reserves the room. Measured across all six
   states — `country`, `weight`, `live`, `cargo`, `none` (no service),
   `none` (no price at this weight) — the block was 200 px every time.
4. **Plain words, never "PKR 0", never a dash.** The figure exists only in
   state `live`; every other state is a sentence, and the green button always
   has somewhere to go (the quote, the cargo message, the
   no-price-at-this-weight message, or plain chat).

---

## 7. `admin.js` — the markup contract

The React admin keeps the whole draft in the browser, diffs it there and saves
it through a server action. This one cannot and should not: the draft lives in
MySQL, and the diff and the validation decide what gets published, so they
belong on the server. **PHP renders real `<form>`s; `admin.js` decorates
them.** Every action has a plain POST that works with JavaScript off.

### Autosave

```html
<form data-autosave action="/admin/rates/save" method="post"
      data-saved-at="22 Sep 2026, 09:31">
  <input type="hidden" name="_token" value="…">
  …every field of the rates editor…
  <button type="submit" data-save-submit>Save draft</button>   <!-- hidden by JS -->
</form>

<div class="pubbar"><div class="inner"><div class="pub-state">
  <strong data-change-count>1 unpublished change</strong>
  <span class="meta" data-save-state>Draft saved 22 Sep 2026, 09:31</span>
</div>…</div></div>
```

On any `input` or `change`, after 800 ms of quiet (the same delay
`AdminEditor.tsx` uses), the form posts **itself, whole**, as `FormData`, to
its own `action`, with `X-Requested-With: fetch`. The endpoint answers JSON:

```jsonc
{ "ok": true,  "savedAt": "22 Sep 2026, 09:33", "changes": 7 }
{ "ok": false, "message": "Someone else edited this draft — reload." }
```

Same URL, same fields, same CSRF token as the no-JS submit: **one code path on
the server**, which decides whether to answer JSON or redirect by looking at
`X-Requested-With`. Saves never overlap — a second is queued, not raced — so
two responses can never land out of order and print a stale time.
`[data-save-submit]` is hidden once JS is running, and `beforeunload` warns
while anything is unsaved.

### The rest

| what | markup | no-JS path |
|---|---|---|
| find a destination | `<input name="q" data-filter-table="rates-table">`, rows carry `data-name` | the same input in a GET form; the server filters |
| per-kg fold | `<button data-fold-toggle="fold-ca">` + `<tr class="grid-row" id="fold-ca" data-open="0" hidden>` | a link to `?open=ca`; the server renders it open |
| quick fill | `[data-fill-scope]` containing `[data-fill-start]`, `[data-fill-step]`, `[data-kg-box]`×N and `<button data-fill="blanks">` / `="all"` | a submit that fills on the server |
| the fill sum, before it is applied | `[data-fill-preview]` inside the same `[data-fill-tool]` | nothing is printed; the two buttons still fill on the server |
| keys inside a ladder | `[data-ladder]` wrapping `[data-kg-box][data-kg]` — `Enter`/`↓` next kilogram, `Shift+Enter`/`↑` back, `Esc` restores the box | `Tab` already walks the boxes in kilogram order |
| paste a column | a `paste` on any `[data-kg-box]` whose text splits on tab/newline fills from that kilogram down | one value pastes as the browser always would |
| copy a ladder to another country | `[data-copy-tool]` (starts `hidden`) with `[data-copy-target]` — **no `name`** — `[data-copy-go]`, `[data-copy-preview]`; targets found by `[data-ladder][data-service=…]` | hidden; Download as CSV is the way out |
| the running check | `[data-ladder-running]`, filled from the Settings fields on the same page | empty and invisible |
| what is wrong with a ladder | `[data-ladder-note]` + `[data-ladder-count]`; bad boxes get `.is-low` / `.is-gap` and `aria-invalid` | the server marks the same boxes on save |
| one weight, every country | `[data-colview]` with `[data-colview-kg]`, `[data-colview-service]`, `[data-colview-body]`, opened by `[data-colview-open]` | hidden; Download as CSV gives the same comparison |
| undo a row | `<button data-undo-row>` inside `<tr data-live='{"input name":"value",…}'>` | a submit (`action=undo:ca`) |
| remove | `<button data-confirm="Confirm remove">` — first click arms, second submits | a submit landing on a confirm page |
| hold dialog | `<button data-hold-open>` + `<form data-hold-dialog hidden>` with `[data-hold-cancel]` and `<textarea data-hold-count="hold-count" maxlength="300">` | a link to `?hold=1`; the server renders it open |
| bulk adjust | `[data-bulk-form]`, `[data-bulk-msg]`, checkboxes named `services[]` / `fields[]`, `value` | the server re-checks and re-renders |
| copy CSV | `<button data-copy-from="rates-csv" data-copy-ok="CSV copied to the clipboard">` + `<pre id="rates-csv" hidden>…</pre>` | only the download link is shown (JS un-hides the button) |
| wide tables | `.tablewrap` | the table scrolls either way; the shadow is a hint |
| review card | `#reviewbox` with the server-rendered card inside | it is already on the page; JS only scrolls it clear of the publish bar |
| a one-off toast | `<span data-toast="Version 12 is live" hidden>` | it is a `.notice` instead |
| a button that must fire once | `<button data-once data-busy-text="Publishing…">` | a double submit is the server's problem anyway |

**There is no import row.** The Excel rate-sheet feature left the product on
the owner's decision of 2026-09-27, so `admin.js` has no `initImport`, no
`[data-import-*]` hooks and no `[data-submit-on-change]`, and `admin.css` has
no file-input rules. Rates CSV **export** stays — that is the copy/download
row above.

**There are TWO copy buffers now, and one mechanism.** `admin.js`'s
`initCsvCopy` binds every `[data-copy-from]` on the page to the element whose
`id` it names, so the pattern is general rather than the rates table's own:

| where | button | buffer |
| --- | --- | --- |
| rates | `data-copy-from="rates-csv"` | `<pre id="rates-csv" hidden>` — the draft as CSV |
| a shipment | `data-copy-from="ship-msg"` | `<pre id="ship-msg" hidden>` — the WhatsApp update, the same string the `wa.me` link carries |

Both start `hidden` and `admin.js` un-hides the button only once it has found
the buffer, so a page without JavaScript shows neither a dead button nor a
stray block of text. A third one is three lines of markup and no JavaScript.

**Why a copy buffer is a `<pre hidden>` and not a `<script type="text/plain">`.**
A destination name is free text the owner types, and the one string a `<script>`
element cannot carry is its own closing tag: no escape exists inside raw text,
so a country called `</script>` would end the element and spill the rest of the
CSV into the page as markup. A `<pre>` is ordinary element content, where `e()`
escapes `<` and `&` and nothing can break out. `hidden` keeps it off screen;
`textContent` reads it back unescaped, which is what the clipboard needs. The
button's own `data-copy-ok` supplies the confirmation text; `admin.js` falls
back to a plain "Copied to the clipboard" and names no spreadsheet.

**JS-only, and why each is safe:** the live filter (a GET form otherwise), the
`data-overflow` shadow (a hint), the "unsaved edits / saving / saved" line (a
Save button otherwise), the `beforeunload` guard (cannot exist without JS, and
without it the Save button is explicit), the character counter under the hold
message, the two Bulk adjust refusals, and the two-step Remove. The server
re-checks every one of them. **Nothing is written by JavaScript that the
server would not write from the same POST**. Two sums do happen here: the quick
fill's `start + i × step`, printed in `[data-fill-preview]` before either button
is pressed, and the running check's customer-facing price — both re-validated,
and re-priced, by the server on save.

**The column view and the copy select post nothing.** Every box the column view
draws is a *proxy*: it carries `data-colview-proxy` (the real box's `name`) and
**no `name` of its own**, and writes through to the box in the fold. The copy
target `<select>` carries no `name` either. This is deliberate and load-bearing:
the editor posts exactly the fields it posted before the redesign and not one
more, so `Http\Admin\DraftForm::wasTruncated` — the guard that exists because
shared hosting caps `max_input_vars` — keeps measuring the same number. **Any
change that adds a named field to this form changes the truncation arithmetic
and must be said out loud.**

The admin's "Test a price" panel must POST and let the server price it with
`App\Domain\Pricing\Engine`. Do not wire it to `pricing.js`: the point of that
panel is to prove the *server* prices the way the owner expects.

### 7a. Touch targets in the admin

`docs/quality-bar.md` asks for 48px on every control a finger reaches.
`tokens.css` raises `--tap` to 48px only inside `.site` and keeps 44px at
`:root`, deliberately, so the admin's dense desktop grid stays dense — 25
per-kilogram boxes at 48px each would push the fold off a laptop screen. But
the admin is used on a phone too (the whole `.admin-top` row exists for that),
so `admin.css` ends with a `@media (max-width: 719px)` block raising
`.admin-app`'s buttons, text/number/date/email/password inputs, selects,
per-kilogram boxes and phone-nav links — and the sign-in page's `.btn.small` —
to `min-height: 48px`. Desktop density is untouched.

Checkboxes are handled differently, because a `min-height` on a checkbox
stretches the box and not the hit area. Where one sits inside
`<label class="chk">` the label is what takes the tap, so the **label** goes to
48px and the 18×18 box is left alone (those labels measured 25px before this).
The rates table's per-row "show on website" toggle is the one bare checkbox —
it has an `aria-label` and deliberately no visible label, since the row already
names the country — so it has nothing to widen and its own box grows to 26px
instead. **48px there needs a wrapping `<label>` in
`admin/partials/rates-table.php`**, which is a template change, not a CSS one.
`input.code` is also left out: the one-time-code field is 52px already and
matching it would shrink it.

`tokens.css` was **not** edited: it is the shell's file, and the 44px `:root`
default is a decision, not an oversight.

Measured with Playwright + Chrome against the real `admin/rates`,
`admin/inbox` and `admin/login` markup (see §9) — at 375×812 every visible
button, text/number/email input, select, per-kilogram box, table cell input,
toolbar filter and phone-nav link computes to 48px, and at 1366×768 they still
compute to 36–44px, exactly as before.

---

## 8. Print

`print.css` is already in the list. It hides the publish bar, the nav, the
header buttons, the theme toggle, the quote's actions and the price bar's
button, and puts the shell back into plain flow. It does **not** force a
closed `<details>` open, and `ui.js` does not either — printing should give
what is on screen.

---

## 9. What was verified, and what was not

**READ THIS SECTION AS A DATED MEASUREMENT, NOT AS COVERAGE.** The findings
below were taken with Playwright + Chrome at 1366×768 and 375×812 (and the
screenshots read) against the `dev-preview.html` harness, back when that
harness was the only thing that could load these files together. That harness
was deleted on 2026-09-27 (§1) and these numbers were never re-taken against
the real templates, so nothing here is a claim about what `tests/browser/**`
covers today. The one exception is the last bullet under "Not verified", which
says how it was measured.

**What covers this ground now** is `php/tests/browser/` — twelve flows against
real routes, five of them through a real admin session — plus the PHP view
tests under `tests/View*/`. `docs/browser-tests.md` is the current statement of
what is and is not checked; when the two documents disagree, that one is right
and this section is history.

**Confirmed working.** Fonts load and apply — `h1` computes to
`"Big Shoulders", "Arial Narrow", Impact, sans-serif`, body to
`Barlow, "Barlow Fallback", …`, the admin's eyebrows to
`"JetBrains Mono", …`; `html` is 17 px; `--ink` `#0a1a33`, `--hot-a` `#ea580c`.
No external request and no failed request. All six calculator readout states
with the result block 200 px in every one. Per-service radio rows, the add-on
line, the arrival line. The phone sticky price bar, pinned, with the country
dropped from the note as the CSS intends. The detailed view end to end:
volumetric weight winning, the status sentence, service cards, the quote card
as a direct sibling of `.results`, the `how` list, and a WhatsApp link whose
text matches `quoteText` line for line. The units toggle showing 4.5 kg as
9.92 lb and 40 cm as 15.75 in, and giving back the typed `4.5` / `40` on the
way home — `pieces.ts`'s whole point. Dark mode on both shells. The admin:
sideways-scroll shadow (`data-overflow="right"`), the per-kg fold, quick fill
blanks-vs-all, the two-step Remove, the accent-folding filter (`turkiye`
matches `Türkiye`), the hold dialog and its counter, and the dirty indicator.
Zero page errors and zero console errors on every run.

**Not verified, and why.**

- **No comparison against the live Next.js site.** It was not running —
  `http://localhost:3020` and `:3000` both refused the connection — so the
  match is against the stylesheets and the TypeScript, not against pixels.
  Still open, and now it has to be done against the real routes.
- **The real pages, with these stylesheets, in a browser.** The harness only
  ever mounted the shell, the calculator and a cut-down admin. `/services`,
  `/about`, `/contact`, `/faq`, the rates board, the CTA band, the contact
  form, the sign-in page and the shipment desk all render in PHP now and are
  covered by the view tests, but their *rendered pixels* under `admin.css` /
  `site.css` were never looked at.
- **`Speedat.toast` on screen.** The API is exercised by `admin.js`, but no
  screenshot caught the toast mid-life.
- **Chrome only**, one engine, one platform. The `@supports` blocks in
  `base.css` and `color-mix()` in `tokens.css` are untested on Safari.
- **The 48px block in §7a was measured, but not on a served route.** No route
  serves the admin yet — `app/routes.php` has the five site pages, the contact
  and quote endpoints, the sitemap, robots and health, and nothing under
  `/admin`. So the templates were rendered to HTML through the real `View`
  engine and the suite's own admin fixtures, written **outside `public/`** (a
  scratch directory, deleted after the run) and driven from `file://`. The
  computed heights in §7a are real; what is untested is the same markup coming
  off the front controller with a session, and the row toggle's 26px box
  against a real thumb.
