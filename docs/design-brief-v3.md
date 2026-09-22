# Speedat — design brief v3: SUBTRACTION (amendment to v2)

**Status.** AMENDS `docs/design-brief-v2.md`. Where they conflict, **v3 wins**. v2 stays valid for: the type scale except `--fs-h1` (§3), tap targets (48 / 56 / 64), the low-literacy rules (§1 of v2), light-by-default + cookie toggle (v2 §7), flags (v2 §6), the `.site` scope, `--edge`, the hard constraints (CSP with nonces, no runtime `<style>`, no external assets, server-rendered text, `useMounted()` before anything client-only, no invented testimonials / logos / ratings / statistics, React Compiler lint, no `any`, Write/Edit only, no commits, never touch `.env.local`, never start/stop the dev server, admin untouched).

**Why.** Owner: *"the UI is good but it looks AI generated and also some sort of resistance in using it — resolve it."* Both are one thing: a landing-page template with the calculator in its hero slot, and motion between the visitor and the number. The fix is subtraction, not a redesign. The three-tap flow, its handlers, ids, WhatsApp messages and quote logging do not change.

**Ground truths** (dev DB on http://localhost:3020, 2026-09-22): four destinations in admin order `au` Australia, `ca` Canada, `france` France, `de` Germany; grid pricing 1–25 kg; `maxKg` 25; `docMaxKg` 0.5; one add-on "Pickup and service charges | 500 | on"; cutoff 15; `originCities` "Lahore"; 1 kg Normal 4,500 (France, Germany) / 5,220 (Canada) / 5,350 (Australia); 1 kg Express 6,210–7,460; Express days 3–5 / 4–6, Normal 6–9 / 7–10; document rates 3,080–5,210. `priceAll` totals EXCLUDE add-ons; the calculator adds `addonsTotal`. Measured friction (critic, 2026-09-22): calculator opacity 0 until ~705 ms; price stable at 695–734 ms through 17–21 wrong values (first frame "PKR 13,336" for 19,620); phone scrolls itself 631 px on the weight tap; Book button moves +91 px on desktop when the price lands; header 106 px at 375; 45 words before the first tile; every below-fold tile opacity 0 until gsap; deliver-tile tap throws the page −890 / −1,967 px.

---

## 1. Voice

**Rules for every customer sentence** (seed defaults and admin hints):
- Every home sentence carries a place, a number or a day count. A sentence that could head any courier in any country is deleted, not rewritten.
- No sentence instructs the visitor to do what the control already invites ("Tap the green button", "Pick where the parcel is going"). No reassurance ("A person answers", "we mean it"). No questions as headings ("Ready to send?"). No exclamation marks, no em-dash asides, no "instantly / easy / simple / seamless / trusted / premium / journey / experience".
- A number that lives in `settings` or `company` (maxKg, docMaxKg, cutoff, cities, prices, days, phone numbers) is never typed into a content string; the page computes it. Content strings hold only what has no field.
- Anything that can be generated from the rate document IS generated; the content field is an override, blank = automatic (the pattern `content.stats` already uses).

**New pure module `src/lib/site/copy.ts`** (SHELL+HOME; tests in `copy.test.ts`; no `"use client"`):

```ts
export function countryList(names: string[]): string
// 1 → "Canada" · 2 → "Canada and Germany" · 3–4 → "Australia, Canada, France and Germany" · >4 → "A, B, C and {n-3} more countries"
export function fromPrice(site: SiteData, destId: string): { total: number; days: string; serviceId: string } | null
// priceAll(site, { destId, type: "pkg", rows: [{ kg: 1, qty: 1 }] }); cheapest service; total + sum of addonsList(settings).filter(a => a.on)
export function docPrice(site: SiteData, destId: string, serviceId: string): number | null
// priceAll(..., { type: "doc", rows: [{ kg: settings.docMaxKg > 0 ? settings.docMaxKg : settings.firstKg, qty: 1 }] }); only when the result's docRate is true; + on-by-default add-ons
export function daySpan(site: SiteData): [number, number] | null   // min lower / max upper bound over active destinations × services (parseDaysRange)
export function heroTitleAuto(site: SiteData): string
// `${originCities(site.company)[0] ?? site.company.origin} to ${countryList(active names in admin order)}.`  → dev: "Lahore to Australia, Canada, France and Germany."
export function heroSubAuto(site: SiteData, holdOn: boolean): string
// addonOn = addonsList(settings).some(a => a.on); priced: `1 kg from ${fmtMoney(min fromPrice.total)}${addonOn ? ", pickup included" : ""}. At the door in ${a} to ${b} days.` → dev: "1 kg from PKR 5,000, pickup included. At the door in 3 to 10 days."
// hold, or no price: `Pickup in ${cities.join(" and ")}. At the door in ${a} to ${b} days.`; no days → the first sentence only.
export function boardNote(site: SiteData): string
// `1 kg parcel${docs ? ` · documents up to ${docMaxKg} kg` : ""}${addonOn ? ` · pickup ${fmtMoney(addonTotal)} included` : ""}` → dev: "1 kg parcel · documents up to 0.5 kg · pickup PKR 500 included"
```

**Seed defaults v3** (`seed.ts`; the dev DB keeps its copy until the reviewer publishes these through Admin → Website pages). No field is removed; `Content`, `ContentSchema`, `migrate` and `diff` keep every key.

| field | v3 default | rendered where |
|---|---|---|
| `heroTitle` | `""` = `heroTitleAuto` | home h1. Set: printed as typed, asterisks and all (no accent parsing) |
| `heroSub` | `""` = `heroSubAuto` | home line under h1 |
| `stats` | `""` = nothing | when set: one 16 px `--muted` line "{value} {label} · …", no icons, no display numerals |
| `promise` | `Confirmed at pickup when we weigh and measure the parcel. Duties at the destination, if any, are paid by the receiver.` | one line under the calculator (home), plain, no icon |
| `routesTitle` | `Rates` | the rates board caption (home + /services) |
| `routesNote` | `""` = `boardNote` | second line of the caption |
| `stepsTitle`, `steps` | `""` | nowhere. Hidden in the admin form; kept in the type |
| `servicesTitle` | `""` | nowhere. Hidden in the admin form |
| `servicesLede` | `Two air services from Lahore, both door to door: Express and Normal. Documents have a flat rate. Heavier cargo is quoted on WhatsApp.` | /services line under h1 |
| `services` | lines below | /services list (format `icon \| Title \| text` kept; the icon token is parsed and ignored) |
| `ctaTitle`, `ctaSub` | `""` | when set: h2 / line above the contact line on inner pages (§2) |
| `contactLede` | `One WhatsApp number. We answer during working hours and confirm every pickup in the chat.` | /contact |
| `faqLede` | `""` | when set: line under the /faq h1 |
| `story` | two paragraphs below | /about |
| `mission`, `vision`, `values` | `""` | when set: plain h2 + paragraph(s), no cards, no numbers |
| `address`, `hours`, `mapUrl`, `phone2` | unchanged | /about facts, /contact, footer |
| `faq` | lines below | /faq, all open |

`services` v3: `plane | Express | Priority air. The faster of the two on every route; the days are in the table.` · `globe | Normal | Economy air. Same handling, a few days longer, the lower price.` · `doc | Documents | Passports, certificates, contracts. A flat rate per envelope, in the table.` · `box | Cargo | Above the calculator's top weight, commercial goods or many boxes: send the details on WhatsApp and we quote within the hour.` · `truck | Pickup | We collect from your door in our pickup cities; book before the cutoff and we come the same day.` · `shield | Packing and customs | We tell you what can fly and what paperwork the destination needs.` — /services prints the cities, the cutoff hour, `docMaxKg` and `maxKg` itself (§2).

`story` v3: `Speedat International Courier is a courier office in Model Town, Lahore. We send documents, parcels and cargo abroad through partner airlines and express networks, collecting from your door and delivering to the receiver's.` / `The price on this site is the price we charge: you see it before you book, we confirm it at pickup when the parcel is weighed, and we send the tracking on WhatsApp once the shipment is with the airline.`

`faq` v3: `How is the price worked out? | By weight: the higher of the scale weight and the volumetric weight (length × width × height), rounded up to the next half kilo. The detailed price shows the working.` · `What cannot be sent? | What airlines refuse: loose lithium batteries, aerosols, flammable liquids, perfume above the limit, cash, fresh food. Ask on WhatsApp if unsure.` · `Who pays duties at the destination? | The receiver, when the country charges them.` · `How do I book? | Tap Book on WhatsApp under the price and send the message. We confirm the pickup time in the chat.` · `How do I track it? | We send the tracking number on WhatsApp as soon as the airline has the shipment.`

**Words allowed on the quote page** (UI strings; everything else is data or a content field): `1 Country` · `2 Weight` · `kg` · `from` · `days` · `Arrives` · `Includes` · service names from data · `Tap a country` · `Now tap a weight` · `Book on WhatsApp` · `Ask for a cargo rate` · `Ask on WhatsApp` · `Call` · `Over {maxKg} kg` · `Documents · box size` · `More countries` · `Back` · `Sample prices for now. We confirm the real price on WhatsApp.` · `Over {maxKg} kg? We price it on WhatsApp` · `No service to {country} yet. Ask us on WhatsApp`. Gone: "Your price" (label and head), "Fastest", "Cheapest", "Good to know before you book", "Get a detailed price", "Documents or big boxes?", "priced instantly", "at the fastest".

---

## 2. Delete → replace (exact)

| v2 device | files / selectors | v3 replacement | owner |
|---|---|---|---|
| Italic serif accent | `Accent.tsx` renders `{before}{accent}{after}` as plain text (keep the file so INNER compiles; `accent.ts` + tests untouched); delete `.accent`, `.on-navy .accent` (tokens.css), `.hero-copy .accent` (site.css); delete the asterisk hint in `ContentForm`; Instrument Serif is no longer requested on customer pages | headlines in one face | SHELL+HOME |
| Slogan h1 + lede | `seed.heroTitle/heroSub` | `heroTitleAuto` / `heroSubAuto` (§1) | SHELL+HOME |
| Proof row | `Hero.tsx` `.proof`, `PROOF_ICONS`, `heroStats()` in `page.tsx`, site.css `.proof*` | nothing; `content.stats` when set = one plain line | SHELL+HOME |
| "Three taps, one price" | delete `home/Steps.tsx`, site.css `.how*`, `.calc > .how` | nothing | SHELL+HOME |
| "Where we deliver" band | delete `home/RouteBoard.tsx`, `home/RouteRow.tsx`, site.css `.deliver*`, `.dest*`; `.deliver` out of the bleed list | from-price on every country tile (§4) + **RatesBoard** (§3) | SHELL+HOME |
| "Two ways to send" | delete `home/Teaser.tsx`, site.css `.services-home*`, `.services-all` | nothing | SHELL+HOME |
| Navy "Ready to send?" band | `CtaBand.tsx` re-rendered as the **contact line** (§3); site.css PAGES `.cta-*` restyled; `.home > .cta-band` deleted; home no longer renders it | one line + two plain buttons on inner pages only | INNER (page.tsx: SHELL+HOME) |
| Three-column footer | `(site)/layout.tsx` `.foot-grid`, h3s, `Pages` list | two-line footer (§3) | SHELL+HOME |
| Phone header: two rows, moon beside WhatsApp | `.head-row` areas, `.nav` tab strip < 1024, `ThemeToggle` in `.head-actions` | one 56 px row; nav and toggle in the footer (§3) | SHELL+HOME |
| Hero entrance, button press transform | site.css `rise` on `.hero-copy` and `.instrument`; tokens.css `@keyframes rise`, `lift`, `fadein`; `html { scroll-behavior: smooth }`; `.site .btn:active { transform: scale(.97) }` and the `transform 180ms` entry of the `.btn` transition (customer scope only; the admin keeps its `:root` motion) | nothing; the page paints finished; `.site .btn:active { filter: brightness(.92) }` | SHELL+HOME |
| Scroll reveal | delete `site/Reveal.tsx`; `pnpm remove gsap @gsap/react` (no admin import — grep-verified) | plain markup | SHELL+HOME (after INNER/HOME imports are gone) |
| Magnet / ClickSpark | delete `fx/Pull.tsx`, `bits/Magnet.tsx`, `bits/ClickSpark.tsx`; `.pull`, `.pull-in` rules | a button is a rectangle that does not move | SHELL+HOME (after CALCULATOR/INNER imports are gone) |
| Count-up | `PriceReadout.tsx`: delete the rAF loop, `DURATION`, `Shown`, the settle timer, the 60 % start | `fmtMoney(value, currency)` at once; file may be deleted, the bar prints the text | CALCULATOR |
| Self-scroll, nudge | delete `revealPrice`, `nudge`, `NUDGE_MS`, `nudgeTimers`, `earlyTap`, `totalRef/ctasRef/step1Ref/step2Ref`, the `revealKey` effect, `.nudge`, `ring` | the price bar (§4) | CALCULATOR |
| Glow, sweep, lift, land, tick-in, 350 ms print | calculator.css motion block; tokens `--glow-live`, `--t-print`, `--t-odo`, `--t-reveal`, `--t-hero`, `--t-num` (delete each token only if `admin.css` does not reference it) | §5 | CALCULATOR (tokens: SHELL+HOME) |
| Navy price card | `.total`, `.tlabel`, `.sweep`, `.twhen`/`.tlines` inside it, `.step3`, `.step3-pick`, `.step3-price` | `.opts` rows + `.lines` + `.pricebar` (§4) | CALCULATOR |
| Fake-disabled Book, dashed placeholder | the `aria-disabled` anchor, `role="button"`, `tabIndex`, `onKeyDown`; `.total.idle` | nothing until there is a price; one `.wait` line before | CALCULATOR |
| Pastel pills | `.opt-badge`, `.opt-badge.price`, `cheapest`/`fastest` computation | days only on the row (`4–6 days`) | CALCULATOR |
| Step icons, numbered circles, note icons, check glyphs | `StepHead` `icon` prop, `.n` circle, `noteIcon`, `UI.clock`, `UI.check` in `.step-done` and `.tline`, `CardIcons.doc` / `UI.box` on the wide buttons | text labels `1 Country` / `2 Weight`; no third head | CALCULATOR |
| "Good to know" accordion, `.switch` link | `details.how.notes`, `<p class="switch">` | quick view: `content.promise` only (rendered by page.tsx). Detailed view: `includes`, `notes`, `disclaimer` as plain text at its end (`<p class="includes">`, `<ul class="notes">`, `<p class="disclaimer">`), no `<details>`; "How this price is calculated" `<details>` stays | CALCULATOR |
| "Other" | `.kg.other` label | `Documents · box size` (same `onKg("exact")`) | CALCULATOR |
| Shield promise line with the date | `page.tsx` `.promise` icon + "Rates updated" | `content.promise` plain; the date only in the footer | SHELL+HOME |
| /services card grid | `ServiceGrid.tsx` `.cards.three`, `ServiceCard.tsx` (delete), icons | `<ul class="svc-list">` rows (§3) + RatesBoard | INNER |
| /about mission/vision cards, numbered values, "Our story" split | `.mv`, `.values`, `.page-split` on /about | two paragraphs + a facts list (§3) | INNER |
| /contact three icon cards + two more cards | `.contact-card`, `.card-ico`, `MailIcon`, "Office and hours" cards | numbers as the page (§3) | INNER |
| /faq accordion + side card | delete `pages/FaqItem.tsx`; `.faq-grid`, `.faq-ask`, `.faq-x` | all answers open (§3) | INNER |
| 404 orange code, "not *here*" | `.nf-code`, accent | h1 `Page not found`, line `Nothing is at this address.`, two buttons | INNER |
| `--fs-h1` 34→61 px | tokens.css `.site` scale | `--fs-h1: clamp(1.9rem, 1.2rem + 1.6vw, 2.6rem)` (32 @375 · 44 @1366); h1 `max-width: 26ch`; `--lh-h1: 1.05` | SHELL+HOME |

Nothing else from v2 §2.4 comes back.

---

## 3. Layout rhythm

**One surface.** Customer pages have one background (`--bg`); no `.bleed` band outside header and footer; no `--paper-2` panel anywhere on the home page (`--paper-2` survives only as the `:active` fill and the board's zebra rows if wanted). Everything is left-aligned; nothing is centred, including the footer and the 404. The calculator's `.panel` has a 1 px `--line` border, `--r3` and `--panel-pad` from 1024; below 1024 `padding: 0`, no border, no shadow, no radius (the calculator is the page; the wrap's gutter is its margin, which is what the sticky bar's negative margin in §4 assumes). Delete `--shadow-instrument` usage.

**Home** (`(site)/page.tsx`, HOME):

| block | 375 | 1366 |
|---|---|---|
| header | one row 56 px: `.mark` 28 px + first word of `company.name` (Big Shoulders 700 22 px; `aria-label` carries the full name) · Call 48×48 (phone glyph only, when `company.phone`) · WhatsApp 48×48 green glyph (`aria-label="WhatsApp us"`). No nav, no toggle, no tagline. Not sticky | 64 px sticky: mark 32 + full name 24 px · `<Nav/>` centred · Call (outline, `Call {phone}`) · WhatsApp (`WhatsApp us`). No tagline, no toggle |
| h1 + sub | 16 px top; h1 32 px ≤ 2 lines; sub 19 px ≤ 2 lines; `stats` line if set; 16 px below → first tile top ≤ 260 px | grid `minmax(0, 42fr) minmax(0, 58fr)`, gap 56, `align-items: start`, padding-top 24. Left column: h1 44 px (2 lines), sub, then the RatesBoard 24 px below (this fills the column where Steps were). Right: the calculator |
| calculator | full width, no panel chrome; the price bar sticks to the viewport bottom once live (§4) | `.instrument` right column, `max-width: 780px`; the bar is static under the rows |
| `.sample` (when `!live`) and `.promise` | one 16 px line each, `--muted`, under the calculator, no icon, no border | same |
| RatesBoard | after the calculator, 32 px gap | in the left column under the sub, 24 px gap; NOT repeated under the calculator. One DOM node: the third child of `section.calc` (`.hero-copy`, `.instrument`, `table.rates`), grid-placed exactly as `.calc > .how` was |
| footer | two lines (below) | two lines |

Budget: home `scrollHeight` ≤ 1,600 px at 375 cold with four destinations (was 4,644) and ≤ 1,100 at 1366 (was 2,388). Words above the first tile ≤ 20 at 375 (was 45).

**RatesBoard** — `src/components/site/home/RatesBoard.tsx` (SHELL+HOME; server component; imported by /services): data from `boardRows(site, holdOn)` added to `home/routes.ts` (`routeRows`/`routeCode` stay exported and tested): `{ id, name, cells: { serviceId, price: number | null, days: string | null }[], doc: number | null }`, admin order, every price = `fromPrice`-style totals (add-ons included), all prices `null` under hold (rows still render with days). Markup: `<table class="rates">` → `<caption><strong>{routesTitle}</strong> <span>{routesNote || boardNote(site)}</span></caption>` → `<thead>` `Country` · one `<th>` per `site.services` name · `Documents` (only if any row has `doc`) → rows: `<th scope="row"><Flag size={24}/> {name}</th>` · `<td data-label="{service}"><b>{fmtMoney}</b><span>{days} days</span></td>` … · `<td data-label="Documents"><b>{fmtMoney(doc)}</b></td>`. Style: `font-variant-numeric: tabular-nums` on the table; hairline `border-bottom: 1px solid var(--line)` on every row, no vertical rules, no radius, no background; row 56 px from 720 (price 17 px 600 `--ink`, days 14.9 px `--muted` under it, numbers right-aligned in fixed-width columns); caption left-aligned, `--fs-h3` bold + 16 px `--muted`. Below 720: `thead` visually hidden (`.sr`), each `tr` = `display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding: 10px 0`, the row header spanning all columns (flag + name, 17 px 600), each `td` showing `data-label` via `::before` (14.9 px `--muted`) over the price and days. No tap behaviour anywhere on the board (it is a reference, not a control).

**Footer** (`(site)/layout.tsx`, SHELL+HOME): `margin-top: 40px; padding: 20px 0 32px; border-top: 1px solid var(--line)`. Line 1 `<nav aria-label="Pages">`: the five links (48 px targets, 16 px 600 `--muted`, current page `--ink`) and `<ThemeToggle/>` last (48×48 outline, moon/sun) — on phones this is the site's only nav. Line 2 `<p class="foot-line">` 16 px `--muted`, wrapping: `{name} · {tagline} · {address} · WhatsApp {fmtPhone(whatsapp)} · Call {phone} · {email} · {hours} · Rates updated {fmtDate(publishedAt)} · © {year}` — every number and the email a plain link; blank fields skip their segment; the mark is NOT repeated. No h3, no columns, no list. Height ≤ 160 px at 1366, ≤ 360 at 375.

**Inner pages** (INNER): `.page { max-width: 720px; padding: 24px 0 var(--section) }` (32 top from 1024), left-aligned inside `.wrap` (no `margin: auto`); the space to the right at 1366 stays empty on purpose. `PageHead` = plain h1 (+ lede when given); `SectionHead` = plain h2. Sections separated by 32 px and a hairline, never a band. Every inner page ends with the **contact line** — `CtaBand.tsx` keeps its file and props (add `hours?: string`), renders `<div class="reach">` → `{title && <h2>}` `{sub && <p>}` → `<p class="reach-line">WhatsApp <a>{fmtPhone(whatsapp)}</a> · Call <a>{phone}</a>{hours && ` · ${hours}`}</p>` → `<div class="reach-actions"><a class="btn wa big">WhatsApp us</a>{phone && <a class="btn outline big">Call</a>}</div>` (56 px buttons, row from 560, no quote link, no `Pull`, no navy). `margin-top: 40px; padding-top: 24px; border-top: 1px solid var(--line)`.

- **/services**: h1 `Services` + `servicesLede`; `<ul class="svc-list">` one `<li>` per `content.services` line: `<strong>{title}</strong> <span>{text}</span>` on one line from 720 (title 18.7 px 600, text 18 px `--muted`), stacked below; hairline between rows; no icons, no boxes. Then `<p class="facts">` computed: `Pickup in {cities}. Book before {fmtHour(cutoff)} for same-day pickup. Documents up to {docMaxKg} kg. Parcels up to {maxKg} kg priced on the home page; heavier by quote.` (each sentence only when its setting is set). Then `<RatesBoard site holdOn />` full wrap width. Contact line.
- **/about**: h1 `About {company.name}` (plain); the `story` paragraphs 19 px/1.6 ≤ 62ch; `<dl class="facts">` rows with hairlines: `Office` {address} (+ Maps link) · `Pickup` {cities} · `Hours` {hours} · `Cutoff` `Book before {fmtHour(cutoff)} for same-day pickup` · `WhatsApp` {fmtPhone} · `Landline` {phone}; mission / vision / values render as h2 + paragraphs only when set (seed blank). Contact line.
- **/contact**: h1 `Contact` + `contactLede`; `<a class="reach-big" href="https://wa.me/…">{fmtPhone(whatsapp)}</a>` Big Shoulders 700 `--fs-h2` tabular, 64 px tall tap target, WhatsApp glyph before it; under it `<dl class="facts">`: `Call` {phone} · `Also` {phone2} · `Email` {email} · `Office` {address} + Maps link · `Hours` {hours} · `Pickup` {cities} · `Cutoff` … — plain rows, hairlines, no cards, no icons except the WhatsApp glyph. Last: h2 `Or write to us` + `ContactForm` unchanged in fields, token, honeypot, validation and success card. Contact line.
- **/faq**: h1 `Questions` (+ `faqLede` when set); `<dl class="faq-list">`: `<dt>` question 18.7 px 600, `<dd>` answer 18 px `--muted` ≤ 65ch, 20 px between pairs, hairline after each; all open, no `<details>`. Contact line.
- **404 / error**: h1 `Page not found` / `Something went wrong`; one line; `.btn.primary.big` "Get a price" + `.btn.wa.big` "WhatsApp us" (error: "Try again" first). No `.nf-code`.

---

## 4. Calculator (CALCULATOR: `Calculator.tsx`, `PriceReadout.tsx`, `calculator.css`)

**Untouched** (grep-verified hooks): `useSession("sp-mode"|"sp-last")`, `setDestId`, `rememberKg`, `switchMode`, `onExact`, `onKg` (incl. `"exact"`), `setKgv`, `setSvc`, `setAddons`, `TILE_MAX`, `DestOptions`, `weightOptions`, `KG_BUTTONS`, `etaQuick`, `priceAll` calls, `quoteIdFor(`${salt}|…`)`, `quoteText`/`cargoText`/`waLink` arguments, `logQuote` on both Book anchors, `id="quote-instrument"` (HOME) and `id="quote"`, `hidden={mode !== …}`, `role="radio"`/`aria-checked` on tiles, `aria-pressed` on weights, the `q-svc` radios, `Toast`, the detailed view's inputs, segments, pieces, share/copy, `.status`, `.results`, `.quote`. `.sample` and the hold panel path unchanged.

**Friction rules** (each is a QA item in §7):
1. The page never scrolls itself. No `scrollBy`, `scrollIntoView`, `scrollTo`, `focus()` in the quick view.
2. The price is printed at once: `fmtMoney(sum, cur)` in the render, no interim value ever. The bar may fade in over ≤ 120 ms on its first mount only.
3. Every transition inside `.instrument` ≤ 80 ms; `:active` feedback is instant (0 ms).
4. Nothing already on screen moves when the price lands or changes: rows, lines and bar have fixed heights; the Book button's top does not change on a service switch or a later weight tap.
5. No control looks dead: nothing `aria-disabled` on the quick view; the Book button exists only with an href.
6. Tap feedback works before hydration: `.ctile:active`, `.kg:active` are CSS.

**Quick view markup v3:**

```
<div class="panel quick">
  <h2 class="sr">Get your price</h2>
  <div class="step-head"><span class="step-name">1 Country</span>{dest && <span class="step-done"><Flag size={24}/>{dest.name}</span>}</div>
  <div class="ctiles" role="radiogroup" aria-label="Country">
    <button type="button" role="radio" class="ctile" aria-checked=… onClick={() => setDestId(d.id)}>
      <Flag size={32}/><span class="ctile-name">{d.name}</span>
      {from && <span class="ctile-from">from {fmtMoney(from.total, cur)}<span class="ctile-days"> · {from.days} days</span></span>}
    </button> …  {more && <select class="ctile-more" …unchanged…/>}
  </div>
  <div class="step-head"><span class="step-name">2 Weight</span>{kgv && kgv !== "more" && <span class="step-done">{kgv} kg</span>}</div>
  <div class="kgs" role="group" aria-label="Weight">…8 × .kg (strong + "kg")… <button class="kg more">Over {maxKg} kg</button><button class="kg other">Documents · box size</button></div>
  <div class="result" data-state={state} data-n={min(services, 4)}>   {/* always rendered, fixed height (rule 4) */}
    {state === "live" && <fieldset class="opts"><legend class="sr">Service</legend><div>
        <label class="opt"><input type="radio" name="q-svc" …/><span class="opt-name">{sv.name}</span><span class="opt-eta">{days} days</span><span class="opt-price">{fmtMoney(sp.total + addonsTotal)}</span></label> ×2
    </div></fieldset>}
    {state === "live" && <p class="lines"><span class="twhen">{when}</span>{selectedAddons.map(a => <span class="tline">Includes {fmtMoney(a.amount)} {midSentence(a.label)}</span>)}</p>}
    {!waiting && <div class="pricebar" data-state={state}>
      <div class="pricebar-sum" aria-live="polite">
        {state === "live" ? <><span class="tval">{fmtMoney(sum, cur)}</span><span class="tnote">{sv.name} · {kgv} kg · {dest.name}</span></> : <span class="tnote">{note}</span>}
      </div>
      <a class="btn book giant" href={bookHref} target="_blank" rel="noopener" onClick={…logQuote unchanged…}><UI.wa/>{bookLabel}</a>
    </div>}
    {waiting && <p class="wait" aria-live="polite">{note}</p>}   {/* last child: in the bar's slot until there is a bar */}
  </div>
</div>
```

`from = fromPrice(site, d.id)` per tile (import from `@/lib/site/copy`; `useMemo` on `site`); under hold the calculator is not rendered, so no guard is needed. Rows appear in `site.services` order, no `dim` state (only priced services render; with one service, one row). `.opt-eta` = `{days} days` (from `etaQuick`), nothing else. `bookHref` is never null in `.result`: `live` → the quote link; `cargo` → the cargo message, label `Ask for a cargo rate`; `none` → `https://wa.me/…`, label `Ask on WhatsApp`. The `state`/`note`/`when` computation is unchanged except that `live` has no note sentence beyond `tnote`.

**Sizes** (normal / 1366×768 density tier `@media (min-width: 1024px) and (max-height: 820px)`):

| element | rule |
|---|---|
| `.step-head` | 24 px row, Barlow 600 16 px `--ink`, `margin-bottom: 8px`; `.step-done` right-aligned, `--muted` 600, flag 24×18 + name |
| `.ctile` | `min-height: 64px` (56); grid `32px 1fr`, rows `auto auto`, `gap: 2px 12px`, padding `8px 12px`; name 17 px 600; `.ctile-from` 14.9 px `--muted` (inherits the `--bg` text colour at 80 % opacity when checked); each tile is an inline-size query container and `.ctile-days` shows only when the tile's content box is ≥ 196 px ("from PKR 5,850 · 7–10 days" is 172.5 px), so the from-line never ellipsizes — 3-up from about 720 wide, never a 4-up tile beside the hero; 2-up < 560, 3-up to 1023, 4-up from 1024 except while the panel's content box is < 592 px (viewports 1024–1200), where 3-up keeps a nine-letter name on one line; checked = `--ink` fill / `--bg` text as v2 |
| `.kg` | 56 (48); `.kg.more`, `.kg.other` 48 (44), Barlow 600 16 px, no glyph; grid as v2 |
| `.wait` | the result block's last child: `margin-top: auto`, `min-height` = the bar's height, the Barlow 600 20 px `--ink` line centred in it and left-aligned, so "Tap a country" stands exactly where the figure lands and the reserved room above it reads as space, not an empty frame; no box, no border, no icon |
| `.opt` | `min-height: 64px` (56), grid `24px 1fr auto` areas `"radio name price" "radio eta price"`, border `1.5px solid var(--edge)`, `--r3`; checked: `border-color: var(--ink); box-shadow: inset 3px 0 0 var(--hot-a)`; `.opt-price` Big Shoulders 700 24 px tabular; side by side from 560 |
| `.lines` | `min-height: 48px` (44), two 16 px lines `--muted`, `margin: 8px 0 0`; no icons |
| `.pricebar` < 1024 | `position: sticky; bottom: 0; z-index: 2; min-height: 72px; margin: 12px calc(var(--gutter) * -1) 0; padding: 8px var(--gutter) calc(8px + env(safe-area-inset-bottom)); background: var(--bg); border-top: 1px solid var(--line)`, no shadow (the hairline is its whole edge, stuck or at rest in the flow); grid `minmax(0, 1fr) auto; gap: 12px; align-items: center`; `.tval` Big Shoulders 700 28 px tabular `--ink`; `.tnote` 14.9 px `--muted`, one line, `text-overflow: ellipsis`; `.btn.book.giant` 56 px tall, `min-width: 168px`, green (`--wa`/`--wa-ink`), glyph 22 px. The bar is the LAST child of `.result` so it comes to rest at the panel's end and never covers the footer. No ancestor of `.pricebar` (`.result`, `.panel`, `.instrument`, `.calc`, `.home`, `main`, `.site.wrap`) may set `overflow` other than `visible` |
| `.pricebar` ≥ 1024 | `position: static; margin: 12px 0 0; padding: 0; border: 0; box-shadow: none`; grid `minmax(0, 1fr) auto`; `.tval` `--fs-price` (58 px on the density tier) left-aligned; `.tnote` 16 px under it; button 64 px (60), `min-width: 260px`; `min-height: 88px` (80) so the bar never changes height between states |
| `.btn.book` | no transition; `:active` `filter: brightness(.92)`; no transform, no `Pull`, no `ClickSpark` |

Fold budget at 1366×768 (density tier, 4 or 8 destinations): 64 + 24 + 16 + 24 + 8 + 120 + 12 + 24 + 8 + 100 + 12 + 120 + 8 + 44 + 8 + 80 + 16 = **688 px** → the green button's bottom ≤ 768 with a weight chosen (3-up at 1024–1200 adds one 56 px row + 8 px with eight cells: 752). At 375: tiles start ≤ 260 px; the weight row ends inside 812 px; after the weight tap the bar sits inside the first screen with the price and the green button (722–795 measured with four destinations: the whole calculator fits, so the bar rests at the panel's end and sticks only where the result overflows, e.g. 375×667), `scrollY` still 0.

**Detailed view**: markup and behaviour as v2 §4.6, minus `Pull`/`ClickSpark`, minus the `h2.step` numeral circle (`.step-head` text only: `1 Where to?`, `2 What are you sending?`, `3 Choose a service`), plus `includes` / `notes` / `disclaimer` as plain text after the quote card (or after `.results` when there is no quote). The `Back` button stays first. `.svc` transitions ≤ 80 ms. Its Book button is only rendered with a quote (already so).

**Wording** (unchanged plain-words states): `country` → `Tap a country`; `weight` → `Now tap a weight`; `cargo` → `Over {maxKg} kg? We price it on WhatsApp` (bar note) + `Ask for a cargo rate`; `none` → `No service to {country} yet. Ask us on WhatsApp` + `Ask on WhatsApp`; `live` → `{service} · {kg} kg · {country}` under the price, `Arrives {fmtRange}` and `Includes {amount} {label}` in `.lines`.

---

## 5. Motion policy

Allowed, and nothing else: (a) `:active` on `.ctile`, `.kg`, `.opt`, `.btn` — `background: var(--paper-2)` (tiles/weights), `filter: brightness(.92)` (buttons), 0 ms; (b) colour on `:hover`/`:focus-visible`/checked state ≤ 80 ms (`--t-fast` stays 150 ms for the admin; the customer scope sets `.site { --t-fast: 80ms }`); (c) `.pricebar` first mount `fadein` 120 ms and, below 1024, `translateY(100%) → 0` 120 ms `--ease-out` (`@keyframes bar-in`, in calculator.css inside `prefers-reduced-motion: no-preference`); (d) focus rings. Forbidden: anything on scroll, anything on load, transforms on hover, count-ups, sweeps, glows, lifts, pulses, rings, staggered anything, `scroll-behavior: smooth`, canvas. Reduced motion: (c) becomes none. A QA pass finds no `animation-name` other than `none` on any element in `main` except `.pricebar`, and no `transition-duration` value > 80 ms inside `.instrument`.

---

## 6. Craft that reads human

- **Numbers**: `font-variant-numeric: tabular-nums` on `.tval`, `.opt-price`, `.ctile-from`, `.kg strong`, `table.rates`, `.reach-big`, `.foot-line`, `.step-done`; prices left-aligned in the bar, right-aligned in fixed-width table columns, so a changing width never moves a neighbour. `fmtMoney` everywhere ("PKR 5,720"; never "Rs", never "PKR5720", never "/-").
- **"from"**: always lowercase `from {fmtMoney}`; days always `{a}–{b} days` with an en dash, `{n} days` when a single value; never "starting at", "as low as", "only", "just".
- **Dates in words**: `fmtDate` ("21 Sept 2026") once, in the footer; `fmtRange` ("Sat 26 Sept – Tue 29 Sept") in `.lines`. No numeric dates anywhere customer-facing.
- **Rules, not cards**: separation by `1px solid var(--line)` hairlines and whitespace; the only bordered boxes on customer pages are controls (`--edge`) and the calculator panel from 1024. One radius for controls (`--r3`), none on rows or tables. No shadows anywhere on customer pages: the sticky bar's edge is its 1 px hairline (a shadow on it at rest in the flow read as a floating card).
- **The mark**: the `.mark` stripe appears exactly once per page, in the header (28 px on phones, 32 from 1024). The footer names the company in text. No other logo, badge, seal or watermark.
- **Display face**: Big Shoulders only for h1/h2/h3, prices and the kg numerals; `letter-spacing: -0.01em` at ≥ 40 px, `0` below; never uppercase; numerals never letter-spaced. Barlow for everything else, 17–19 px body, 16 px labels, 14.9 px floor (nothing under 14 px, measured).
- **Colour**: paper, ink, `--muted`, `--line`/`--edge`, WhatsApp green on exactly the WhatsApp controls, orange only on the chosen `.opt`'s 3 px bar and the current nav underline. No navy surface on customer pages.
- **Icons**: only where they are the affordance: flags, the WhatsApp glyph on green buttons, the phone glyph on Call, sun/moon on the toggle. `svg` count inside `main` on the home page = 0 cold and 1 live (the Book button's WhatsApp glyph); flags are `<img>`.
- **Header on phones** ≤ 64 px (56 built), WhatsApp 48×48 always visible; Call beside it when a phone number is set.
- **Text**: sentence case everywhere; `text-wrap: balance` on h1 only; no centred text; no ellipsis-hidden copy except the bar's `.tnote`.

---

## 7. QA rubric — human-made (50) + zero-friction (50)

Ship at ≥ 90 with no item under its threshold. Each item scores full or zero; reviewers may deduct 1–2 inside an item for a hesitation the threshold does not name (write it down). Measurements run in the scratchpad Playwright setup (`chromium.launch({ channel: "chrome" })`, `http://localhost:3020`, viewports 375×812 `isMobile hasTouch` and 1366×768), scripted in a `.cjs` written with the Write tool; screenshots to `../shots/` and read back. Regression gates from v2 stay in force: R1 (no customer text < 14 px, `getComputedStyle` over every text node on all pages), R4 (contrast), M1 (no horizontal scroll), M2 (targets), C1 (behaviour by hand: five readout states, detailed quote, hold panel, contact form, `/api/quotes` logged on Book), C2 (`pnpm typecheck`, `pnpm lint`, `pnpm test`, `pnpm build`, curl 200 on the five pages + 404 body).

| # | Item (points) | Pass threshold and measurement |
|---|---|---|
| H1 | No accent, one face (6) | On `/`, `/services`, `/about`, `/contact`, `/faq`, `/x`: `document.querySelectorAll(".accent, em").length === 0` in `main`; `performance.getEntriesByType("resource")` has no font whose name contains `Instrument`; every h1/h2 `font-family` starts with `"Big Shoulders"` |
| H2 | Numbers in the copy (8) | Home h1 text matches `/^\S+ to .+\.$/` and contains ≥ 2 active destination names (or `more countries`); the sub contains `PKR` and `days` (hold: `Pickup in` and `days`); no customer page text matches `/Ready to|Two ways|Three taps|See your price|anything abroad|A person answers|priced instantly|at the fastest|Good to know|Fastest|Cheapest/` |
| H3 | One surface, fewer sections (8) | Home DOM has none of `.how, .deliver, .services-home, .cta-band, .proof, .dest, .card, .n, .opt-badge, .total, .sweep, .switch, details`; `main section` count = 1 (`section.calc`) and `table.rates` count = 1; no element wider than 90 vw with a `background-color` other than `--bg` except `.site-head` and `.site-foot`; `scrollHeight` ≤ 1,600 at 375 and ≤ 1,100 at 1366 (cold, four destinations) |
| H4 | Icons only as affordances (6) | `main svg` count on `/` = 0 cold, ≤ 1 live; `header svg` ≤ 2 (phone, WhatsApp); `main svg` on `/services`, `/about`, `/faq` ≤ 2 each (the contact line's WhatsApp and phone glyphs), on `/contact` ≤ 3 (plus `.reach-big`); no `svg` inside `.step-head`, `.lines`, `.opt`, `.kg`, `.promise`, `.svc-list`, `dl.facts` |
| H5 | Rates board (8) | `table.rates` present on `/` and `/services` with `tbody tr` count = active destinations; every `td b` matches `/^PKR [\d,]+$/` (not on hold); `getComputedStyle(table).fontVariantNumeric` includes `tabular-nums`; `caption` contains `routesTitle`; each row has a `border-bottom-width` of 1 px and no `border-radius`; no `button`/`a` inside the table |
| H6 | Tiles carry the from-price and agree with the calculator (6) | Every `.ctile` text contains `from PKR`; for each destination: tap the tile, tap `1 kg`, the smallest `.opt-price` equals the tile's from-price to the rupee; when the tile's content box is ≥ 196 px wide (3-up from about 720, e.g. 768×1024) the tile also shows `· {days} days` and those are the cheapest service's days; a narrower tile hides the days, and on every width `.ctile-from` `scrollWidth ≤ clientWidth` (never ellipsized) and `.ctile-name` is one line with the dev data |
| H7 | Inner pages are lists, not cards (4) | `/services`: `.svc-list li` count = `content.services` lines, `.card` count 0; `/faq`: `details` count 0, `dd` count = faq lines, all visible; `/contact`: `.card` 0, `.reach-big` height ≥ 64 and `font-family` Big Shoulders; `/about`: `.card` 0, `.n` 0, `dl.facts` present |
| H8 | Footer and header (4) | `.site-foot` height ≤ 160 at 1366 / ≤ 360 at 375, contains `nav[aria-label="Pages"]` with 5 links and `.btn.theme`, no `h3`; `.site-head` height ≤ 64 at 375 with its `.nav` at `display: none` and no `.btn.theme` inside it; `.site-head .btn.wa` 48×48 visible at 375; `.mark` count on the page = 1 |
| F1 | Price at once (10) | After hydration (a tile tap has changed `aria-checked`), `t0 = performance.now()` before `click()` on `5 kg`; poll each `requestAnimationFrame`: `.pricebar .tval` text present at ≤ 120 ms after `t0` (dev server; ≤ 60 ms on a build) and identical in every later frame for 1,000 ms; distinct values observed = 1; the value equals `fmtMoney(row total)` of the checked `.opt` |
| F2 | No self-scroll (10) | Record `scrollY` before and 1,000 ms after each of: country tap, weight tap, service tap, second weight tap, a tap on any `.rates` row, at 375 and 1366 → every delta = 0. `grep -c "scrollBy\|scrollIntoView\|scrollTo(" src/components/calculator/*.tsx` = 0 |
| F3 | Nothing moves when the price lands (8) | `PerformanceObserver({ type: "layout-shift", buffered: true })` during the whole tap sequence: sum of `value` for entries whose `sources` lie inside `.instrument` < 0.01 (with and without `hadRecentInput`); at 1366 `.btn.book.getBoundingClientRect().top` identical (± 1) before and after: switching the service, tapping `10 kg` after `5 kg`, tapping another country; `.ctiles`, `.kgs` heights identical cold and live |
| F4 | Painted finished (6) | At the first `requestAnimationFrame` after `domcontentloaded`: every element inside `main` has computed `opacity === "1"` and `animationName === "none"` (`.pricebar` excepted); scrolling 300 px per 60 ms to the bottom on both viewports: zero elements inside the viewport with opacity < 1; `document.querySelector("canvas") === null` |
| F5 | Fast feedback (6) | For `.ctile`, `.kg`, `.opt`, `.btn.book`, `.ctile-more`: every comma-separated `transition-duration` ≤ 80 ms and `animation-duration` = `0s`; `getComputedStyle(el).transform === "none"` at rest and while hovered; `html` `scroll-behavior` = `auto` |
| F6 | Phone: nothing before the tiles (4) | At 375: visible words above the first `.ctile` ≤ 20 (the critic's tree-walk); first `.ctile` top ≤ 260; the last `.kg` bottom ≤ 812 with four destinations |
| F7 | Sticky bar where the thumb is (8) | At 375 after `Canada` + `5 kg`: `.pricebar` `position` = `sticky`, `getBoundingClientRect().bottom` ≤ `innerHeight` and, when `.result`'s bottom exceeds `innerHeight` (375×667; Safari with its bars), = `innerHeight` ± 1 (at 375×812 with four destinations the whole calculator fits the first screen and the bar rests at the panel's end, 722–795), height ≤ 80, contains `.tval` and a green `.btn.book` (`background-color` `rgb(14, 122, 63)`, height ≥ 56) with an `href` starting `https://wa.me/`; scroll to the bottom of the page: `.pricebar.bottom ≤ .site-foot.top` (nothing covers the footer); at 1366 `position` = `static` and the button's bottom ≤ 768 |
| F8 | No dead controls, no accordion (4) | Cold `/`: `[aria-disabled]` count 0, `.btn.book` count 0, `.wait` text `Tap a country`; after the country tap `Now tap a weight`; `details` count outside `[hidden]` = 0 on the quick view; `Documents · box size` opens the detailed view and `Back` returns with the country still checked |
| F9 | Lighter bundle (4) | `performance.getEntriesByType("resource")` on `/` lists no chunk whose URL or content contains `gsap`, `Magnet`, `ClickSpark`; `pnpm ls gsap` empty; first state-changing tap ≤ 1,500 ms after `navigationStart` on the dev server (informational, logged) |

---

## 8. Ownership and order of landing

- **Additions**: `src/lib/site/copy.ts` + `copy.test.ts`, `src/components/site/home/RatesBoard.tsx`, `boardRows` in `home/routes.ts` (+ tests: hold → prices null; add-ons included; doc column only when a doc rate exists) → SHELL+HOME. `CtaBand` `hours` prop, `dl.facts`, `.svc-list`, `.faq-list`, `.reach*` styles (PAGES block) → INNER. `.pricebar`, `.wait`, `.lines`, `.result`, `bar-in`, the `.site { --t-fast: 80ms }` override lives in tokens.css (SHELL+HOME) → CALCULATOR for the rest.
- **Deletions** (owner deletes; imports elsewhere must be gone first): `Steps`, `Teaser`, `RouteBoard`, `RouteRow`, `Reveal`, `fx/Pull`, `bits/Magnet`, `bits/ClickSpark` (SHELL+HOME); `ServiceCard`, `FaqItem` (INNER); `PriceReadout` may go (CALCULATOR).
- **Order**: (1) SHELL+HOME lands tokens (`--fs-h1`, `--t-fast` scope, `.accent` gone, keyframes gone), `Accent` → plain text, `Pull`/`ClickSpark`/`Reveal` → pass-through wrappers that render `children` in the same DOM shape, `copy.ts`, `RatesBoard`, and reports. (2) CALCULATOR and INNER work in parallel against those, removing every `Pull`/`ClickSpark`/`Reveal`/`Accent` import from their files and reporting. (3) SHELL+HOME deletes the pass-through files, runs `pnpm remove gsap @gsap/react`, lands the header/footer/home page, publishes the §1 seed copy on the dev DB through Admin → Website pages, and runs §7.
- **Contracts**: `Flag` props unchanged; `fromPrice(site, destId)` and `boardRows(site, holdOn)` are the only price computations outside the calculator, and both add the on-by-default add-ons exactly as `QuickRate` does — one number per fact, printed the same everywhere. `CtaBand` props `{ whatsapp, phone?, hours?, title?, sub?, quoteLink?, eyebrow? }`. `PageHead`/`SectionHead` keep old props optional. `Nav` gains `label?: string` (default `Site`); the header renders `<Nav/>` (hidden below 1024 by CSS) and the footer `<Nav label="Pages"/>`. No new content fields; `ContentForm` (SHELL+HOME) hides `stepsTitle`, `steps`, `servicesTitle`, renames "Where we deliver" → "Rates board", "Stamp band" → "Contact line (inner pages)", and states "blank = automatic" on `heroTitle`, `heroSub`, `routesNote` with the §1 templates as the hint text; the headline hint drops the asterisk sentence.
- A check failing in a file you do not own goes into your hand-off note as `open_issues`, never into that file. State anything not verified locally.
