# Speedat — design brief v2: THREE TAPS (amendment to v1)

**Status.** This document AMENDS `docs/design-brief.md` (v1). Wherever the two conflict, **v2 wins**. v1 stays valid for: the palette names and every token *name*, the four fonts and how they load, the CSS file architecture and prefix ownership, the admin (untouched this round), the hard constraints (CSP with nonces, no runtime `<style>`, server-rendered LCP text, `useMounted()` before anything client-only, no invented testimonials/logos/ratings/statistics, React Compiler lint rules, no `any`, Write/Edit only, no commits, never touch `.env.local`, never start/stop the dev server).

**Why.** The owner's verdict on v1: *"the user is illiterate and does not know how to use a website — the user should be able to do the work perfectly; the texts are oversized and all other negative space."* His screenshot at 1914 px showed a six-line 110 px headline in a narrow column with empty navy either side, mono micro-eyebrows ("01 — RATE"), a readout saying "PKR 0" and service rows saying "—". And: *"don't do dark mode as default; give the option, but by default it should be white."*

Ground truths (verified in the repo and on http://localhost:3020 on 2026-09-21):

- The dev database publishes four destinations with ids `au`, `ca`, `france`, `de` (ids are NOT all ISO codes), grid pricing 1–25 kg, `maxKg` 25, `docMaxKg` 0.5, currency PKR, one add-on ("Pickup and service charges", 500, on). The seed has ten destinations with ISO ids.
- `Calculator.tsx` (891 lines) has two views behind `sp-mode` (`quick` | `detail`), remembers `sp-last` (`{destId, kg}`), and its quick view derives everything from `destId`, `kgv` (`""`, a number string, `"more"`, `"exact"`), `svc`, `addons`. `kgv === "exact"` calls `onExact()` which switches to the detailed view. `PriceReadout.tsx` renders `${currency} 0` when idle and an odometer when live. Service rows print `—` when unpriced. The route board prints `1 kg from PKR …` and a derived code (`FRA`).
- No test pins seed copy or component markup; `routes.test.ts` asserts `routeCode()` (`GB`, `FRA`) and hold behaviour; `diff.test.ts` and `repo.test.ts` compare content *shapes*. 93 tests pass today.
- Everything customer-facing is inside `ShellWrap` (`<div className="wrap">`), so a single scope class on that element can restyle the site without touching the admin.
- `flag-icons@7.5.0` (MIT) ships `flags/4x3/<iso>.svg`; it is not installed yet. CSP is `img-src 'self' blob: data:`, so local SVG files under `/public/flags/` are allowed; `style-src-attr 'unsafe-inline'` allows `next/image`'s inline style.
- `tokens.css` sets `color-scheme: light dark` and guards dark values with `@media (prefers-color-scheme: dark) :root:not([data-theme="light"])` and `:root[data-theme="dark"]`; `<html>` carries no `data-theme` today, so the OS decides. That ends.

---

## 1. The visitor

**Who.** A first-time visitor in Pakistan on a mid-range Android phone, often on someone else's recommendation, who reads little or no English (and may read little Urdu), has never filled a web form, and is fluent in three things: WhatsApp, phone calls, and country flags. He wants one number — what it costs to send a parcel to a country — and then a human. He will not scroll to find the calculator, will not read a paragraph, will not understand a tab bar, a chevron-less select, a dash, or "PKR 0".

**What he can read.** Numbers (prices, kilograms, days), a handful of nouns he has seen on shop signs (WhatsApp, Call, Express, kg), flags, icons (📍 scale, tag, phone, WhatsApp bubble), colour states (grey = not yet, green = go). He cannot be expected to read a label longer than two words or any sentence longer than eight.

**The three-tap path (the whole product).**
1. Tap a country tile (flag + name). The flag lands next to "1 Country" with a tick.
2. Tap a weight button (1 … 25 kg). The tick lands next to "2 Weight".
3. The price appears — the biggest thing on the screen — and the WhatsApp button turns green. (Tap 3 is the price arriving, not a tap.)
4. Tap **Book on WhatsApp**. Done. The prepared message already contains country, weight, service and price.

Everything else on the site exists to make him trust those four taps: short proof, how-it-works in pictures, the flags of where we deliver, and a phone number.

**Vocabulary allowed on the quote page** (UI strings; everything else must be data or a content field): Country · Weight · Your price · kg · days · Delivered by · Pickup · Fastest · Cheapest · Express / Normal (service names from data) · Tap a country · Now tap a weight · Book on WhatsApp · Call · Ask on WhatsApp · Other · Documents · Over {maxKg} kg · Cargo rate · Price at pickup · More countries · Back. No word from the v1 manifest register (manifest, instrument, readout, stamp, route, rate card, quote id) appears on screen.

**Icon per step** (from `src/components/Icons.tsx`; SHELL+HOME adds the five missing glyphs — `UI.tag`, `UI.check`, `UI.sun`, `UI.moon`, `UI.phone` — as 24 px stroke paths in the existing `base` style): Step 1 Country = `UI.pin`; Step 2 Weight = `UI.scale`; Step 3 Your price = `UI.tag` (a price tag); Book = `UI.wa`; Call = `UI.phone`. The same three icons head the "How it works" section so the page teaches the calculator before the visitor reaches it.

---

## 2. Scale, space, and what goes

### 2.1 Type scale v2 (html stays 17 px; 1rem = 17 px)

The customer scale lives on the `.site` scope (section 2.5) so the admin keeps its `:root` values pixel for pixel. Paste into `tokens.css` directly after the responsive overrides:

```css
/* v2 customer scale — scoped to the public site; the admin keeps the :root values. */
.site {
  --fs-h1: clamp(2rem, 1rem + 2.9vw, 3.6rem);        /* 34 @375 · 39 @768 · 47 @1024 · 57 @1366 · 61 @1920 */
  --fs-h2: clamp(1.65rem, 1.2rem + 1.4vw, 2.5rem);   /* 28 → 42 */
  --fs-h3: clamp(1.25rem, 1.15rem + 0.4vw, 1.5rem);  /* 21 → 25 */
  --fs-lede: clamp(1.1rem, 1.02rem + 0.35vw, 1.25rem); /* 19 → 21 */
  --fs-body: 1.0625rem;    /* 18 px */
  --fs-label: 0.95rem;     /* 16 px — every control label */
  --fs-small: 0.875rem;    /* 14.9 px — the floor; nothing customer-facing below this */
  --fs-button: 1.1rem;     /* 18.7 px — .btn.big and .btn.giant */
  --fs-price: clamp(2.6rem, 1.4rem + 4vw, 4.25rem);  /* 44 @375 · 56 @768 · 66 @1024 · 72 from 1200 */
  --fs-total: var(--fs-price);                        /* alias: calculator.css may keep using --fs-total */
  --fs-stat: clamp(1.5rem, 1.2rem + 0.9vw, 2rem);    /* proof-point numbers 25 → 34 */
  --fs-kg: 1.35rem;        /* 23 px — the number on a weight button */
  --lh-h1: 1.02; --lh-h2: 1.08; --lh-h3: 1.15; --lh-body: 1.55; --lh-lede: 1.5;
  --ls-h1: -0.01em; --ls-h2: -0.005em;
  --section: 72px;         /* between home sections; 48px below 720 */
  --gap-title: 20px;       /* title block → its content */
  --tap: 48px; --tap-big: 56px; --tap-giant: 64px;
  --wrap: 1360px;
  --card-pad: 24px;        /* 20px below 720 */
  --panel-pad: 24px;       /* 16px below 720 */
}
@media (max-width: 719px) { .site { --section: 48px; --card-pad: 20px; --panel-pad: 16px; } }
@media (min-width: 1024px) and (max-height: 820px) { .site { --fs-price: 3.4rem; } } /* 58 px: the 1366×768 fold */
```

Rules: h1 Big Shoulders 800 `max-width: 20ch; text-wrap: balance` (two lines at 1366, three at 375 with the seed copy; the admin hint says "under 45 characters"); h2 700 `max-width: 24ch`; h3 600; lede Barlow 400 `--muted` `max-width: 52ch`; body 18 px `max-width: 62ch` in cards and prose; labels Barlow 600 16 px sentence case `--ink`; **no uppercase, no letter-spacing, no mono on any customer page** (JetBrains Mono and `.eyebrow` remain admin-only; `--fs-eyebrow`, `--ls-eyebrow`, `--ls-tab`, `--ls-mono` stay in `:root` for the admin and must not be referenced from `site.css` or `calculator.css` any more). `font-variant-numeric: tabular-nums` on every price and count (Big Shoulders has no tabular figures, so prices are left-aligned so a changing width only moves the right edge).

### 2.2 Container and hero grid

`.site.wrap { max-width: 1360px }` with `--gutter` 16 (< 720), 24 (720–1199), 40 (≥ 1200): content 343 @375 · 720 @768 · 944 @1024 · 1286 @1366 · 1360 @1920. No `.bleed` navy fields on the home page; `.bleed` remains for the tinted "Where we deliver" band and the closing WhatsApp band.

Hero (`section.calc`):

| width | layout |
|---|---|
| 375–767 | one column: h1 → sub → proof row → calculator, 16 px gutters, no jump link (Step 1's tiles are inside the first 812 px) |
| 768–1023 | one column; the hero block and the calculator both full width (calculator `max-width: 780px`) |
| 1024–1365 | `grid-template-columns: minmax(0, 42fr) minmax(0, 58fr); gap: 40px; align-items: start`; hero not sticky |
| 1366–1919 | same, `gap: 56px` (columns ≈ 517 / 713) |
| ≥ 1920 | `--wrap` caps at 1360 (columns 548 / 756); the calculator `max-width: 780px` |

`.calc { padding-top: 24px }` (32 from 1024). The calculator column keeps `min-height: 520px` from 1024 (CLS guard while `useSession` settles).

### 2.3 Spacing rhythm and targets

Sections `--section` apart; inside a section `--gap-title` between title and content; cards `--card-pad`; between the calculator's steps 16 px (12 on the 1366×768 density tier). Every tappable control ≥ `--tap` 48 px in both dimensions; step controls (tiles, weight buttons, service cards) `--tap-big` 56; the WhatsApp button `--tap-giant` 64 (60 on the density tier). Button sizes are `.site`-scoped in `tokens.css` so the admin keeps its 44/36/52: `.site .btn { min-height: var(--tap); font-size: 1rem }`, `.site .btn.big { min-height: var(--tap-big); font-size: var(--fs-button) }`, new `.site .btn.giant { min-height: var(--tap-giant); font-size: var(--fs-button) } .site .btn.giant svg { width: 24px; height: 24px }`; `.btn.small` is not used on customer pages. Focus ring unchanged (3 px `--focus`, offset 2).

### 2.4 Devices removed (exact) and their replacements

| v1 device | files / selectors | v2 replacement |
|---|---|---|
| Threads living background | delete `fx/LivingBackground.tsx`, `bits/Threads.tsx`, `pnpm remove ogl`; delete `.hero-bg`, `.silk`, `.hero-canvas`, `canvas-in`, the `useCanFx` gate stays in `motion.ts` (harmless) | nothing: white page |
| Decrypt effect | delete `fx/Decrypt.tsx`, `bits/DecryptedText.tsx`, `pnpm remove motion` | plain text |
| ScrollReveal story + drop cap | delete `fx/StoryReveal.tsx`, `bits/ScrollReveal.tsx`, `.story-first::first-letter`, `.drop` | plain paragraphs |
| Word-by-word hero rise, StatCounter count-up | delete `home/HeroTitle.tsx`, `home/StatCounter.tsx`; `.hero-title .w`, `--stagger-word`, `--t-hero` usage | `<h1><Accent text={title} /></h1>`; one `rise` 500 ms on `.hero-copy` and one on `.instrument` (delay 120 ms), inside the no-preference block |
| Mono eyebrows, section codes | every `<p className="eyebrow">` on customer pages; `PageHead`'s `no`/`name`; `SectionHead`'s `eyebrow`; `.instrument-head .eyebrow`; `CtaBand`'s `eyebrow` | no eyebrow line at all; `PageHead`/`SectionHead`/`CtaBand` keep those props as optional and ignore them |
| Editorial rules | every `<span className="rule">` on customer pages; `.rule.hot` under card icons | none (the `.rule` class stays in tokens for the admin) |
| Route line above h1 | `home/eyebrow.ts`, `content.heroEyebrow` (types, schema, seed, ContentForm) | removed field (old versions restore fine: `migrate` spreads, the schema strips) |
| Manifest strip | `.manifest`, `.stats`, `.unit` styling | `.proof` row (section 3.1) |
| Jump link | `.jump`, `.jump-arrow` and the anchor in `Hero.tsx` | none |
| Route codes, navy route board | `.route-code` markup, `.routes.on-navy`, `.route-*`, `.routes-meta`, `.route-chips` | `.deliver` flag grid (3.3); `routeCode()` stays in `routes.ts` (tested) but is not rendered |
| Numerals 01/02/03 | `.numeral` in Steps and About values, `.card-no` in ServiceGrid, `.faq-n`, `h2.step .n::before "0"` | plain `1 2 3` in an ink circle (`.n`), or nothing |
| Ghost watermarks | `.ghost` in the footer, `.nf-ghost` on 404/error | none |
| Quote stamp, hazard stripe, registration marks, indicator square, rule under the price, mono cur | `.stamp` span in the detailed quote, `.stripe`, `.total::before/::after`, `.tlabel::before`, `.tval::after`, `.odo .cur` mono | plain price card |
| Mode tab bar | `.modes` tablist | a sentence link at the foot of the quick view and a Back button at the top of the detailed view (4.6); `switchMode` and `sp-mode` unchanged |
| Weight select + chips | the `<select>` in `label.field.weight`, `.chips`/`.chip` | `.kgs` button grid (4.3); `onKg` unchanged |
| Country select (≤ 8 destinations) | `label.field.dest` | `.tiles` flag grid (4.2); the select survives only as "More countries" when > 8 |
| Odometer | `.odo`, `.col`, `.strip`, `.sym`, `.w` in `PriceReadout.tsx` and calculator.css | count-up (4.4) |
| Magnet pull | keep `Pull.tsx` (pointer-only button feedback) | — |
| ClickSpark | keep (button feedback); its idle fix from v1 stays | — |
| Reveal (AnimatedContent) | keep for section reveals; gsap stays | — |

---

## 3. Home page v2 (`src/app/(site)/page.tsx`)

Order: header → **Hero + calculator** → **How it works** → **Where we deliver** → **Services** → **WhatsApp / Call band** → footer. LCP = the server-rendered h1. `Hero.tsx` loses `"use client"` (no islands remain in it).

### 3.1 Hero — `Hero.tsx`, HOME

Sources: h1 `content.heroTitle` (via `Accent`); sub `content.heroSub`; proof points `heroStats(site)` (existing function in `page.tsx`, logic unchanged; labels below); the calculator `<Calculator site={site} />` or `<HoldPanel …/>` (props unchanged); `.sample` when `!site.live`; `content.promise` + "Rates updated {date}" under the calculator as one 14.9 px `--muted` line with `CardIcons.shield` 16 px.

```
<div class="hero-copy">
  <h1><Accent text={title} /></h1>
  <p class="lede">{sub}</p>
  <ul class="proof">  ← one <li> per stat: <span class="proof-ico"><Icon/></span><strong>{value}{suffix}</strong><span>{label}</span>
</div>
<div id="quote-instrument" class="instrument"> {sample?} {calculator|hold} <p class="promise">…</p> </div>
```

`.proof`: `display: flex; flex-wrap: wrap; gap: 12px 28px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--line)`; each `li` a row of icon 24 px `--orange-ink` + `strong` Big Shoulders 700 `--fs-stat` tabular + label 16 px `--muted`. Icons by stat index: `CardIcons.globe`, `CardIcons.plane`, `CardIcons.box` (custom `content.stats` lines use the same order). Labels from `heroStats`: `countries` (was "destinations"), `days at the fastest` with value `{min}` (drop the "+"; `text: "3 days"`), `kg priced instantly` / `pickup cities` as today. Nothing counts up.

Seed copy v2 (`seed.ts`; the dev DB keeps its own copy until the reviewer publishes these through Admin → Website pages): `heroTitle: "Send anything *abroad*. See your price now."` · `heroSub: "Tap your country and the weight. Your price appears — then book on WhatsApp."` · `promise: "The price you see is the price at pickup, unless the parcel weighs or measures differently."`

### 3.2 How it works — `home/Steps.tsx`, HOME

`<section class="how">` → `<h2 id="how-title"><Accent text={content.stepsTitle} /></h2>` → `<ol class="how-list">` of `<li>`: `<span class="n" aria-hidden>1</span><span class="how-ico"><Icon/></span><h3>{title}</h3><p>{text}</p>`. Icons by index `[UI.pin, UI.scale, UI.wa]`, then `CardIcons.box`. Grid: 1 column < 720, 3 columns ≥ 720 (`gap: 24px`); each item a `--surface` card, hairline, `--r4`, `--card-pad`; `.n` 40 px ink circle, white Big Shoulders 700 22 px; icon 40 px `--orange-ink`; h3; p 18 px `--muted` `max-width: 32ch`. `Reveal` per item (stagger 0.08). Seed v2: `stepsTitle: "Three taps, one price"`; `steps`: `Tap your country | Pick where the parcel is going.` / `Tap the weight | 1 to 25 kg. Not sure? Pick the nearest — we weigh it at pickup.` / `See the price and book | Tap the green button. We reply on WhatsApp and collect from your door.` (format `Title | text` unchanged).

### 3.3 Where we deliver — `home/RouteBoard.tsx` + `RouteRow.tsx` + `routes.ts`, HOME (also rendered by /services)

`<section class="deliver bleed" aria-labelledby="deliver-title">` — `background: var(--paper-2); padding: var(--section) 0`. Inside `.wrap`: h2 `content.routesTitle`, p `.deliver-note` `content.routesNote` (18 px `--muted`, 60ch), then `<ul class="dest-grid">` — `grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px` (2 per row at 375, 8 at 1360). Each `li` → `<RouteRow destId href?>` rendering, as today, a `button` (home) or `a` (services) with class `dest` — `min-height: 72px; display: grid; grid-template-columns: 40px 1fr; gap: 6px 14px; align-items: center; padding: 14px; background: var(--surface); border: 1.5px solid var(--edge); border-radius: var(--r3); text-align: left` — containing `<Flag code={flagCode(r.name, r.id)} name={r.name} size={40} />`, `<span class="dest-name">{name}</span>` (17 px 600), `<span class="dest-price">from {price}</span>` (16 px `--ink`, only when `price`), `<span class="dest-days">{days}</span>` (14.9 px `--muted`). Hover/focus: `border-color: var(--ink)`; tap: existing behaviour (writes `sp-last.destId`, scrolls to `#quote-instrument` / navigates). Under the grid, two plain sentences computed from settings (no new fields): `Documents up to {docMaxKg} kg have their own rate.` when `docMaxKg > 0`; `Over {maxKg} kg? Ask for a cargo rate on WhatsApp.` when `maxKg > 0`. Hold: no `dest-price` anywhere (unchanged). `Reveal` stagger 0.04, cap 8. Seed v2: `routesTitle: "Where we deliver"`, `routesNote: "Price for a 1 kg parcel with the cheapest service. Pickup is added in your price."`

### 3.4 Services — `home/Teaser.tsx`, HOME (cards by INNER's `ServiceCard`)

`<section class="services-home">`: h2 `content.servicesTitle`; the first **two** `lines(content.services)` as `.cards.two` (1 column < 640, 2 from 640; `gap: 16px`/24): icon 40 px `--orange-ink`, h3, p 18 px `--muted` ≤ 60ch (no `.rule.hot`, no `.card-no`); then `<Link class="btn outline big" href="/services">All services</Link>` full width < 640, `width: auto` from 640. Seed v2 `servicesTitle: "Two ways to send"`.

### 3.5 WhatsApp / Call band — `site/CtaBand.tsx` (INNER-owned, HOME renders it)

Props become `{ whatsapp, phone?, title?, sub?, quoteLink?: boolean, eyebrow? (ignored) }`. `<section class="cta-band bleed on-navy">` stays navy (the one navy moment besides the price): h2 `content.ctaTitle` (paper), p `content.ctaSub` (`--paper-70`, 19 px), then `.cta-actions`: `<Pull><a class="btn wa giant">` `UI.wa` "WhatsApp us"` and, when `phone`, `<a class="btn giant paper" href="tel:…">` `UI.phone` "Call {phone}"`; when `quoteLink !== false` a third `<Link class="btn giant paper" href="/#quote-instrument">Get a price</Link>` (inner pages only; home passes `quoteLink={false}`). Buttons stack full width < 720, sit in a row from 720. Delete `.stamp-mark`. Seed v2: `ctaTitle: "Ready to send?"`, `ctaSub: "Tap WhatsApp or call. A person answers."`

### 3.6 Header and footer — `(site)/layout.tsx`, SHELL+HOME

Header 64 px (56 < 900): mark 32 px + `.brand-name` Big Shoulders 700 24 px + `.brand-tag` Barlow 14.9 px `--muted` sentence case (hidden < 640, where the split brand shows "Speedat" over "International Courier" in 14.9 px — the existing `has-short` branch); `<Nav/>` links Barlow 600 16 px, 48 px tall, current page underlined 2 px `--hot-a`; `.head-actions`: `[Call]` `.btn.outline` with `UI.phone` (number hidden < 560, button hidden < 480 as today) · `[WhatsApp us]` `.btn.wa` · `<ThemeToggle initial={theme} />` (section 7). Footer: no ghost; three columns (brand + address, Pages, Contact) at 16 px; `.foot-bottom` "Rates updated {date} · © {year} {name}" 14.9 px Barlow. `Tagline.tsx` unchanged in markup, restyled.

---

## 4. Calculator v2 — `src/components/calculator/*`, `src/styles/calculator.css`, CALCULATOR

**What must not change** (grep-verified hooks): `useSession("sp-mode")`, `useSession("sp-last")`, `setDestId`, `rememberKg`, `switchMode`, `onExact`, `onKg` (with its `"exact"` branch), `setKgv`, `setSvc`, `setAddons`, `setChosen`, `setType`, `switchUnits`, `setPieces`/`newRow`/`nextKey`, `setShipDateInput`, `setFrom`, `setContents`, `copyQuote`, `navigator.share`, `logQuote(quote, {...})` on both Book anchors, `waLink(...)`/`cargoText`/`quoteText` arguments, `priceAll(...)` calls, `quoteIdFor(...)`, `id="quote"`, `aria-live="polite"` on `.total` and `.status`, `hidden={mode !== …}` wrappers, `DestOptions`, `weightOptions`, `etaQuick`, the add-on checkboxes, `Toast`/`useToast`, `ClickSpark` wraps, `Pull` wraps, `.includes`/`.disclaimer`/`details.how.notes`. Class hooks that stay: `.panel.quick`, `.panel`, `.field`, `.lab`, `.opts`, `.opt`, `.opt-name`, `.opt-eta`, `.opt-price`, `.opt-badge`, `.opt-badge.price`, `.addons`, `.total` (+ `.idle`, `data-state`, `data-sum`), `.tlabel`, `.tnote`, `.tlines`, `.sum`, `.sweep`, `.ctas`, `.btn.chat`, `.btn.book`, `.seg`/`.segbtn`, `.units`/`.ubtn`, `.pieces`/`.piece`/`.ptitle`/`.pnum`/`.p-remove`, `.dims`/`.x`, `.addrow`, `.results`, `.svcs`, `.svc*`, `.status`, `.addons-detail`, `.quote`, `.qid`, `details.how`, `.actions`, `.static`, `.sample`. Removed: `.modes`, `.chips`/`.chip`, `.stripe`, `.stamp`, the quick view's two `<select>`s (the country select only when ≤ 8 destinations), `.odo*`.

### 4.1 Quick view markup (`QuickRate`)

```
<div class="panel quick">
  <h2 class="sr">Get your price</h2>
  <div class="step-head"><span class="n">1</span><UI.pin/><span class="step-name">Country</span>
    {dest ? <span class="step-done"><Flag code name size={24}/>{dest.name}<UI.check/></span> : null}</div>
  <div class="tiles" role="radiogroup" aria-label="Country">
    {tiles.map(d => <button type="button" role="radio" aria-checked={destId===d.id} class="tile" onClick={() => setDestId(d.id)}>
      <Flag code={flagCode(d.name, d.id)} name={d.name} size={32}/><span>{d.name}</span></button>)}
  </div>
  {more ? <label class="field dest"><span class="lab">More countries</span><select …existing DestOptions/onChange…/></label> : null}
  <div class="step-head"><span class="n">2</span><UI.scale/><span class="step-name">Weight</span>
    {kgv && kgv !== "more" ? <span class="step-done">{kgv} kg<UI.check/></span> : null}</div>
  <div class="kgs" role="group" aria-label="Weight">
    {KGS.map(k => <button type="button" class="kg" aria-pressed={kgv===String(k)} onClick={() => onKg(String(k))}><strong>{k}</strong><span>kg</span></button>)}
    {sets.maxKg > 0 ? <button type="button" class="kg more" aria-pressed={kgv==="more"} onClick={() => onKg("more")}><UI.box/>Over {sets.maxKg} kg</button> : null}
    <button type="button" class="kg other" onClick={() => onKg("exact")}><CardIcons.doc/>Other · documents · box size</button>
  </div>
  <div class="step-head"><span class="n">3</span><UI.tag/><span class="step-name">Your price</span></div>
  <fieldset class="opts" data-state={res?.ok ? "live" : "wait"}> …existing legend (sr-only) and .opt labels… </fieldset>
  {addonsBox}
  <div class="total …" data-state={state} …> <span class="tlabel">Your price</span> <PriceReadout …/> {sweep} {tlines} <span class="tnote">{note}</span> </div>
  <ClickSpark …><div class="ctas" data-live={!idle}>
    <Pull><a class="btn book giant" …existing href/aria-disabled/onClick…><UI.wa/>{bookLabel}</a></Pull>
    <div class="ctas-2">
      {phone ? <a class="btn outline" href="tel:…"><UI.phone/>Call</a> : null}
      <a class="btn chat outline" href="https://wa.me/…" target="_blank" rel="noopener"><UI.wa/>Ask on WhatsApp</a>
    </div>
  </div></ClickSpark>
  <p class="switch">Sending documents, a big box or many boxes? <button type="button" class="link" onClick={() => switchMode("detail")}>Get a detailed price</button></p>
</div>
```

`tiles = act.slice(0, 8)` in stored order (the admin's order is the popularity order `DestOptions` already uses); `more = act.length > 8` keeps the existing `<select>` (with `DestOptions`) under the tiles; a destination chosen there shows in `.step-done` even though no tile is checked. `KGS = [1, 2, 3, 5, 10, 15, 20, 25].filter(k => weightOptions(sets).includes(k))` (grid mode: only priced boxes; slab mode: all ≤ maxKg). `bookLabel` v2: "Book on WhatsApp" (was "Book now"; the cargo label "Ask for a cargo rate" stays). `.btn.book` is **green** on customer pages — `.instrument .btn.book { background: var(--wa); color: var(--wa-ink); border-color: var(--wa) }` in `calculator.css` (the class name and the orange `--book` tokens stay, because the admin's publish button uses them) — orange is reserved for the price stage, ticks and selection bars. `.instrument .btn.chat` becomes an outline button. `phone = site.company.phone` (render the Call button only when set).

### 4.2 Step 1 — tiles

`.tiles { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px }` → `repeat(3, 1fr)` from 560 → `repeat(4, 1fr)` from 1024. `.tile { min-height: var(--tap-big); display: flex; align-items: center; gap: 12px; padding: 0 14px; background: var(--surface); border: 1.5px solid var(--edge); border-radius: var(--r3); font: 600 1rem/1.2 var(--font-sans); color: var(--ink); text-align: left }`; `[aria-checked="true"] { background: var(--ink); color: var(--bg); border-color: var(--ink) }` (dark mode: `--bg` is the dark page, so the checked tile reads paper-on-ink both ways). Flag 32×24 (`Flag`, section 6). `.step-head { display: flex; align-items: center; gap: 10px; margin: 0 0 10px; font: 600 var(--fs-label)/1.2 var(--font-sans) }`; `.n` 32 px ink circle (white numeral, Big Shoulders 700 18 px); icon 22 px `--orange-ink`; `.step-done { margin-left: auto; display: inline-flex; align-items: center; gap: 8px; color: var(--ok); font-weight: 600 }` with `UI.check` 20 px. The block after each `.step-head` (`.tiles`, `.kgs`, `.opts`) gets `margin-bottom: 16px` (12 on the density tier).

### 4.3 Step 2 — weight buttons

`.kgs { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px }`; the two wide buttons `.kg.more, .kg.other { grid-column: span 2; min-height: var(--tap) }` (row 3 on phones); from 560 `grid-template-columns: repeat(8, 1fr)` with `.more, .other { grid-column: span 4 }` (two rows always: 8 numbers, then the two wide ones). `.kg { min-height: var(--tap-big); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0; background: var(--surface); border: 1.5px solid var(--edge); border-radius: var(--r3); color: var(--ink) }`, `strong` Big Shoulders 700 `--fs-kg` tabular, `span` 14.9 px `--muted`; `[aria-pressed="true"]` ink fill / `--bg` text / `span` inherits; `.more`, `.other` are Barlow 600 16 px rows with a 22 px glyph. "Other · documents · box size" opens the detailed view through `onKg("exact")` (unchanged: clears `kgv`, calls `onExact()`).

### 4.4 Step 3 — services, add-on, the price, the buttons

`.opts[data-state="wait"] .opt { opacity: .55 }` with the price cell **empty** (`{sp ? fmtMoney(sp.total, cur) : null}` — never `—`); `.opt` = `min-height: var(--tap-big); display: grid; grid-template-columns: 28px 1fr auto; grid-template-areas: "radio name price" "radio eta price"; gap: 2px 12px; align-items: center; padding: 12px 14px; border: 1.5px solid var(--edge); border-radius: var(--r3)`; `.opt-name` 600 17 px with `.opt-badge` as a 14.9 px pill ("Fastest" `--blue-soft`/`--orange-ink`; `.opt-badge.price` reads **"Cheapest"** `--ok-soft`/`--ok`); `.opt-eta` 14.9 px `--muted` ("3–5 days · delivered by Mon 28 Sep" — `etaQuick` unchanged); `.opt-price` Big Shoulders 700 24 px `--ink` tabular; checked: `box-shadow: inset 3px 0 0 var(--hot-a); background: var(--paper-2)`. Two services sit side by side from 560 (`.opts > div { display: grid; grid-template-columns: 1fr 1fr; gap: 8px }`), stacked below. Add-on: `.addons label` a 48 px row, 24 px checkbox `accent-color: var(--hot-a)`, 16 px text "+PKR 500 Pickup and service charges" (unchanged strings from settings).

**`.total` states** (`state` computed beside `note`; `idle` unchanged):

| `data-state` | when | `.tlabel` | number | `.tnote` (the only sentence) | Book button |
|---|---|---|---|---|---|
| `country` | `!destId` | Your price | none | **Tap a country above** | grey (`aria-disabled`) |
| `weight` | `!kgv \|\| kgv === "exact"` | Your price | none | **Now tap a weight** | grey |
| `live` | priced | Your price | count-up `fmtMoney(sum)` | `{service} · {kg} kg to {country} · delivered by {range}` (or `· {days} days`) | **green** |
| `cargo` | `kgv === "more"` or `!res.ok` | Your price | none | **Over {maxKg} kg? We price it on WhatsApp** | green, label "Ask for a cargo rate" (existing) |
| `none` | no service for this country | Your price | none | **No service to {country} yet. Ask us on WhatsApp** | grey; "Ask on WhatsApp" below is live |
| documents | handled in the detailed view (4.6) | | | | |
| on hold | `HoldPanel` replaces the calculator (unchanged behaviour) | | | | |

`.total { padding: 20px; border-radius: var(--r4) }`; idle states: `background: var(--paper-2); border: 1.5px dashed var(--edge); color: var(--ink)`, `.tnote` **Barlow 600 1.25rem** (21 px) `--ink` with a 22 px `--orange-ink` glyph before it (`UI.pin` for `country`, `UI.scale` for `weight`, `UI.wa` for `cargo`/`none`); live: `background: var(--navy); color: var(--paper-ink); border: 1px solid var(--navy-2)` (dark mode: `--line`), `box-shadow: var(--glow-live)`, `.tlabel` 16 px `--paper-70`, the number `--fs-price` Big Shoulders 700 `--paper-ink` left-aligned, `.tlines` 16 px, `.tnote` 16 px `--paper-70`. Transition of background/colour over `--t-print` inside the no-preference block; `.sweep` stays (one pass per new sum).

**`PriceReadout.tsx`** (props unchanged `{ value, currency, idle }`): `if (idle) return null;` — the instruction text is the note. Otherwise `<span class="tval"><span class="sr">{fmtMoney(value, currency)}</span><span class="num" aria-hidden="true"><span class="cur">{currency}</span> {fmtNum(shown)}</span></span>` where `shown` counts from the previous value (or `Math.round(value * 0.6)` on first paint) to `value` over 700 ms, ease-out cubic, rAF driven by React state exactly as `StatCounter` did (copy the pattern; do not import from `home/`), with a 800 ms settle timeout; `!useMounted() || useReducedMotion()` → `{fmtMoney(value, currency)}` plain. `.cur` Barlow 600 16 px `--paper-70`, 8 px before the number. The live region announces the `.sr` text once per change; the counting span is aria-hidden.

**Buttons**: `.ctas { display: grid; gap: 10px; margin-top: 14px }`; `.btn.book.giant { width: 100%; min-height: var(--tap-giant); font-size: var(--fs-button); background: var(--wa); color: var(--wa-ink); border-color: var(--wa) }`, `[aria-disabled="true"] { opacity: .45; pointer-events: none }`; `.ctas-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px }` with two 48 px outline buttons. The wow lift (section 8) plays on `.ctas[data-live="true"] .btn.book`. `.switch` 16 px `--muted` with the `.link` button in `--ink` 600 (48 px tall hit area via padding).

### 4.5 Layouts and the fold

375: everything stacks inside 16 px gutters; tiles 2-up (56 px), weights 4-up (56 px) + two wide (48 px), services stacked, readout, giant button, two half buttons. Panel padding 16.
768: tiles 3-up, weights 8 + 2, services side by side.
1024–1365 (beside the hero): tiles 4-up, panel padding 20, `--fs-price` 66 px.
**1366 × 768 density tier** (`@media (min-width: 1024px) and (max-height: 820px)`): `.calc` padding-top 24, panel padding 20, `.step-head` 28 px + 8 margin, tiles and weights 48 px, `.opt` 44 px, the add-on row 40 px, `.total` padding 16 (label 20 + number 58 + note 22), `--fs-price` 58 px, `.btn.book.giant` 60 px, 10 px between blocks. Budget with 8 tiles (two rows): 64 header + 24 + 20 + (36 + 104 + 10) + (36 + 104 + 10) + (36 + 44 + 10 + 40 + 10) + 132 + 10 + 60 = **750 px** — the green button's bottom edge sits inside 768 with a weight chosen; with four destinations (dev DB) it sits at 694. This is the acceptance measurement (section 9, U2); if a change spends those 18 px, take them from the step gaps, never from a target size.
1920: the instrument `max-width: 780px`, everything else as 1366.

### 4.6 Detailed view (`DetailedQuote`) — simplified, behaviour untouched

Top: `<button type="button" class="btn outline back" onClick={() => switchMode("quick")}>← Back to quick price</button>`. The three panels keep their markup; `h2.step` becomes the `.step-head` look (`.n` circle + words: "1 Where to?", "2 What are you sending?", "3 Choose a service"). Fields: labels sentence-case 16 px `--ink` (the `.field > span` override lives under `.instrument`), inputs 52 px, number inputs Barlow tabular (no mono), `.dims .x` `--muted`; `.seg` 56 px with 17 px text; `.ubtn` 40 px pills 16 px; hints 14.9 px. The "To" select gets `<Flag/>` 24 px beside its label once chosen (`.step-done`). `.results`: no stripe; `.svc` = the `.opt` card look (56 px, name + tag row, price 24 px, eta 14.9 px), `disabled` at 55 % with an empty price cell (the `dimText` strings move into `.status`, never into the price cell); `.status` 16 px. The `.quote` card: h2 "{service} to {country}" + `.tag` "Document rate" when `docRate` (16 px pill); `dl` 16 px rows with `dt` `--muted`; the total `dd.display` at `--fs-h2` (the inline `fontSize: "1.6rem"` is removed by CALCULATOR, who owns this file); no `.stamp`; `details.how` 14.9 px; the contents field; `.actions`: `.btn.book.giant` green "Book on WhatsApp" full width, then "Share" / "Copy" as 48 px outline buttons in a row. Documents state = `type === "doc"`: the hint under the segment reads as today; the quote card's tag says "Document rate"; nothing else differs.

### 4.7 Hold panel — `HoldPanel.tsx`, SHELL+HOME

Unchanged behaviour. Restyle: `h2.step` → `.step-head` look with `UI.clock`; `.hold-msg` 18 px `--ink` on `--paper-2` with the 3 px `--hot-a` bar; three fields (country select — `<Flag/>` beside the label once chosen; weight number input; contents) 52 px with 16 px labels; the button `.btn.wa.giant` "Ask for today's rate on WhatsApp"; the hint 16 px Barlow centred.

---

## 5. Inner pages v2 (INNER)

Shared: `PageHead({ title, lede, no?, name? })` renders `<header class="page-head"><h1><Accent/></h1>{lede && <p class="lede">}</header>` — no eyebrow, no rule; `margin-bottom: 32px`. `SectionHead({ title, lede?, id?, eyebrow? })` renders h2 (+ lede) only. `.page { padding: 32px 0 var(--section) }`. Cards `.card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r4); padding: var(--card-pad) }`, icon 40 px `--orange-ink`, h3, p 18 px ≤ 60ch; hover `border-color: var(--ink)`. Every page ends with `<CtaBand whatsapp phone title sub />` (quote link on). No `Reveal` stagger above 0.06; no other motion.

- **/services**: PageHead "Services" + `servicesLede`; `ServiceGrid` — all `content.services` as `.cards.three` (1 / 2 @640 / 3 @960) without `.card-no` or `.rule.hot`; then `<RouteBoard site holdOn href="/#quote-instrument" />` (the flag grid, titled by `routesTitle`); CtaBand.
- **/about**: PageHead `About *Speedat* …` + tagline; story as plain `<p>` 19 px/1.6 ≤ 62ch in `.page-split` (label column "Our story" h2 + "From Lahore · Faisalabad — Pakistan" 16 px; body column) — no StoryReveal, no drop cap; mission/vision as two `.card`s with h3 "Mission"/"Vision" and the text at 19 px 500; values as `<ol class="values">` rows: `.n` circle + `strong` 19 px + text 18 px `--muted`; CtaBand.
- **/contact**: PageHead "Contact us" + `contactLede`; the contact sheet as three `.card`s in `.cards.three` (WhatsApp / Call / Email): icon 40 px, the number Big Shoulders 700 `--fs-h3` tabular, one 16 px note, and a **giant** button each (`.btn.wa.giant` "Open WhatsApp", `.btn.giant.outline` "Call now", `.btn.giant.outline` "Write to us"); "Office and hours" two cards (address + the existing Google Maps **link**, no iframe; hours, pickup cities, cutoff sentence); "Send us a message" `ContactForm` (`.panel.contact-form`, 52 px inputs, 16 px labels, submit `.btn.primary.big`, the WhatsApp fallback `.btn.wa.big`; token, honeypot, validation and success card untouched); CtaBand.
- **/faq**: PageHead "Questions people ask" + `faqLede`; `.faq` = `FaqItem`s (native `<details>`, WAAPI fold kept): `summary { min-height: 56px; display: grid; grid-template-columns: 1fr 24px; gap: 16px; align-items: center; padding: 14px 0 }`, question Barlow 600 18.7 px, the `.faq-x` plus/minus 24 px `--ink`; no `.faq-n`; answer 18 px `--muted` ≤ 65ch, `padding-bottom: 18px`; `details[open] summary` colour `--orange-ink`. CtaBand.
- **404 / error**: `src/components/site/pages/NotFoundBody.tsx` (INNER, server-safe) = `<section class="page nf"><p class="nf-code">404</p><PageHead title="That page is not *here*" lede="…" /><div class="nf-actions">…</div></section>` — `.nf-code` Big Shoulders 800 `--fs-h1` `--orange-ink` (no ghost); buttons `.btn.primary.big` "Get a price" (→ `/`) and `.btn.wa.big` "WhatsApp us" (the site's `whatsapp` fetched from `getLiveSite()` by the caller). `src/app/(site)/not-found.tsx` renders it inside the site shell (reached through the existing `[...rest]` route); **new** `src/app/not-found.tsx` renders `<div class="site wrap"><NotFoundBody …/></div>` for URLs outside the site group (no header there; the WhatsApp button is the way out). `error.tsx` keeps its `useEffect` log and `reset`; same composition with "Try again" `.btn.primary.big`.

---

## 6. Flags (SHELL+HOME)

**`src/lib/site/countries.ts`** (pure, no `"use client"`, unit-tested in `countries.test.ts`):

```ts
export function normalizeCountry(s: string): string   // NFD → strip \p{M} → lowercase → strip punctuation → collapse spaces → drop leading "the "
export function flagCode(name: string, id?: string): string | null
export const COUNTRY_CODES: Record<string, string>     // normalized name or alias → ISO 3166-1 alpha-2 (lowercase)
```

`flagCode`: `COUNTRY_CODES[normalizeCountry(name)]` → else, if `id` is exactly two ASCII letters and appears among the map's values → `id.toLowerCase()` → else `null`. The map covers at least: gb (united kingdom, uk, u k, britain, great britain, england, scotland, wales, northern ireland), us (united states, usa, u s a, america, united states of america, states), ca, au, nz, ae (united arab emirates, uae, emirates, dubai, abu dhabi, sharjah), sa (saudi arabia, ksa, saudi), qa, om, kw, bh, de, fr, it, es, pt, nl (netherlands, holland), be, lu, ch, at, ie, se, no, dk, fi, pl, cz (czechia, czech republic), hu, ro, gr, cy, mt, tr (turkiye, türkiye, turkey), ru, ua, my, sg, th, id, vn, ph, cn, hk, tw, jp, kr (south korea, korea), in, bd, lk, np, mv, af, ir, iq, jo, lb, eg, ma, tn, za, ke, ng, mu, uz, kz, az, br, mx, ar. Tests: `flagCode("United Kingdom")==="gb"`, `flagCode("UK")==="gb"`, `flagCode("Türkiye")==="tr"` and `"Turkey"`, `flagCode("Dubai")==="ae"`, `flagCode("USA")==="us"`, `flagCode("Atlantis")===null`, `flagCode("Somewhere","fr")==="fr"`, `flagCode("Somewhere","france")===null`, `flagCode("France","france")==="fr"`.

**Script** `scripts/copy-flags.mjs`: `pnpm add -D flag-icons`; the script copies every `node_modules/flag-icons/flags/4x3/*.svg` to `public/flags/` (create the folder; overwrite; skip nothing), writes `public/flags/LICENSE.txt` with the flag-icons MIT notice, and prints the count. Add `"flags": "node scripts/copy-flags.mjs"` to `package.json` scripts. The copied files are committed with the round (≈ 270 files, ≈ 1.2 MB) so Vercel needs no build step; the script is idempotent.

**`src/components/site/Flag.tsx`** (server-safe):

```tsx
import Image from "next/image";
export function Flag({ code, name, size = 32 }: { code: string | null; name: string; size?: number }) {
  const h = Math.round(size * 0.75);
  if (!code) return <span className="flag flag-badge" style={{ width: size, height: h }} aria-hidden="true">{name.replace(/[^A-Za-z]/g, "").slice(0, 2).toUpperCase()}</span>;
  return <Image className="flag" src={`/flags/${code}.svg`} alt="" width={size} height={h} unoptimized />;
}
```

`alt=""` (the name is always printed beside it); `next/image` with `unoptimized` emits a plain lazy `<img>` with width/height (no CLS) and no external request; `.flag { display: block; border-radius: 3px; border: 1px solid var(--line); background: var(--paper-2); object-fit: cover; flex: none }`; `.flag-badge { display: inline-flex; align-items: center; justify-content: center; font: 700 0.875rem/1 var(--font-sans); color: var(--ink) }`. Users: calculator tiles and `.step-done` (CALCULATOR), the deliver grid (HOME), the hold panel and the detailed "To" field. **Hand-off rule**: SHELL+HOME lands `countries.ts` + `Flag.tsx` first and reports; until then CALCULATOR may ship its own `src/components/calculator/Flag.tsx` with the same props and swap the import later.

---

## 7. Theme: light by default, dark by choice (SHELL+HOME)

**Tokens.** `:root` becomes the light set and is authoritative regardless of OS preference: `--bg: #FFFFFF`, `--paper-2: #F5F1EA`, `--surface: #FFFFFF`, `--ink: #0A1A33` (18.4:1 on white), `--ink-2: #1E2D47`, `--muted: #4F5866` (7.2:1), `--line: #E6E1D8` (decorative hairlines only), **new** `--edge: #8A9099` (3.2:1 — the border of every interactive control: tiles, weight buttons, inputs, outline buttons, service cards), `--orange-ink: #9A3412` (7.3:1), `--hot-a: #EA580C` (3.6:1 — only fills, bars and text ≥ 24 px/700), `--wa: #0E7A3F` + `--wa-ink: #FFFFFF` (5.4:1), `--book`/`--book-ink` unchanged (orange; used by the admin's publish button — the customer Book button is greened by the scoped rule in 4.1), `--navy*`/`--paper-*` constants unchanged, `--glass` unchanged. `color-scheme: light` on `:root`. This white page is the one admin-visible change of the round (the owner asked for white); nothing else in the admin moves.

**Dark set**: one block only, `:root[data-theme="dark"] { … }` — the v1 dark values plus `--edge: #66708A` (3.4:1 on `--surface`), `color-scheme: dark`. **Delete** the `@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) … }` block from `tokens.css` and every `:root:not([data-theme="light"])` rule in `site.css` and `calculator.css` (each block's owner deletes its own; admin.css keeps its copies — harmless, since the attribute is always present). New dark rules use `:root[data-theme="dark"] …` only.

**Server.** `src/app/layout.tsx`: `import { cookies } from "next/headers"`; `const theme = (await cookies()).get("theme")?.value === "dark" ? "dark" : "light";` then `<html lang="en" data-theme={theme} className=…>`. `viewport` becomes `export async function generateViewport(): Promise<Viewport>` returning `{ themeColor: theme === "dark" ? "#0B1424" : "#FFFFFF", viewportFit: "cover" }`. Because the attribute is always present on the server HTML, there is no flash and no script runs before paint. `(site)/layout.tsx` reads the same cookie to seed the toggle. Reading cookies keeps every page dynamic — the site layout already reads `headers()` for the nonce, so nothing changes.

**Toggle.** `src/components/site/ThemeToggle.tsx`, `"use client"`, props `{ initial: "light" | "dark" }`, state seeded from the prop (no hydration mismatch):

```tsx
const next = theme === "dark" ? "light" : "dark";
document.documentElement.dataset.theme = next;
document.cookie = `theme=${next}; Path=/; Max-Age=31536000; SameSite=Lax${location.protocol === "https:" ? "; Secure" : ""}`;
setTheme(next);
```

Markup: `<button type="button" className="btn outline theme" aria-pressed={theme === "dark"} aria-label={theme === "dark" ? "Light mode" : "Dark mode"} title={same}>{theme === "dark" ? <UI.sun/> : <UI.moon/>}</button>` — 48 × 48 px, 22 px glyph, sits last in `.head-actions`. No localStorage, no server action, no `matchMedia` anywhere.

**Per section in dark mode**: page `#0B1424`, cards/tiles `--surface #121D31` with `--edge`; the navy price readout and the closing band keep their navy and gain `border: 1px solid var(--line)`; checked tiles/weights are `--ink` (paper) fill with dark text; flags keep their 1 px `--line` frame; WhatsApp green unchanged; orange shifts to `#F97316`/`#FDBA74` as in v1; `--glass` header follows `--bg`; the admin follows automatically. Reviewer checks every text pair ≥ 4.5:1 in both themes (DevTools CSS Overview).

---

## 8. Wow moments (three, no explanation needed)

1. **The price lands.** Trigger: `.total` enters `live` or `sum` changes. The stub prints from warm paper to navy (`background-color`/`color` 350 ms `--ease-out`), the number counts up (700 ms ease-out cubic, `fmtNum` each frame, tabular), the `.sweep` crosses once (700 ms), and 120 ms later the Book button lifts: `@keyframes lift { 0% { transform: scale(1) } 50% { transform: scale(1.03) } 100% { transform: scale(1) } }` 320 ms on `.ctas[data-live="true"] .btn.book` while its opacity goes .45 → 1 over 250 ms. Reduced motion: instant colour swap, the final number, no sweep, no lift. Why it needs no explanation: the box changes colour, a big number appears, the button turns green — three cues that all say "ready".
2. **A weight is chosen.** Trigger: `mousedown`/`touchstart` on `.kg` or `.tile` (CSS `:active`). `transform: scale(.96)` over 90 ms, back over 180 ms `--ease-out`; the chosen button fills ink with `--t-fast`; the tick in `.step-done` fades in with `fadein` 200 ms. Reduced motion: fill only. Why: it feels like a physical button.
3. **The flag arrives.** Trigger: a tile becomes `aria-checked="true"`. Its `.flag` plays `@keyframes land { from { transform: scale(1.18); opacity: .4 } to { transform: none; opacity: 1 } }` 220 ms `--ease-out`, and the same flag appears beside "1 Country" in `.step-done` (fadein 200 ms). Reduced motion: no scale. Why: a flag is the one symbol every visitor recognises, and it now sits next to the step he just finished.

All keyframes live inside `@media (prefers-reduced-motion: no-preference)` in the owning file (`calculator.css`; `lift` may live in `tokens.css` next to `rise`).

---

## 9. QA rubric (scored as the owner would; ship ≥ 95, nothing below its threshold)

| # | Item (points) | Pass threshold |
|---|---|---|
| S1 | Three taps to a price (10) | From a cold load at 375, a tester who reads nothing gets a green button with a price in exactly three taps; no scrolling before tap 1 |
| S2 | Nothing to read on the way (8) | Quote-page strings are all in the section-1 vocabulary; no sentence > 8 words on the quick view; no "PKR 0", no "—", no dashes or placeholders anywhere |
| S3 | Only one obvious next action at every moment (6) | Grey button + one instruction sentence before the price; one green button after it; no tab bar, no second select on the quick view |
| S4 | Country recognisable without reading (3) | A flag on every active destination (or the two-letter badge), in the calculator and in "Where we deliver" |
| S5 | Book carries the whole message (3) | The wa.me text contains country, weight, service, price (unchanged `quoteText`); the cargo and hold paths still open WhatsApp |
| R1 | Type sizes (8) | h1 ≤ 2 lines at 1366 and ≤ 3 at 375 with the seed copy; body 17–19 px; labels 14–16 px; **no customer text < 14 px** (audit with `getComputedStyle` over every text node on `/`, `/services`, `/about`, `/contact`, `/faq`) |
| R2 | No micro-typography (6) | Zero uppercase-mono eyebrows, codes or letter-spaced text on customer pages; JetBrains Mono not requested on `/` (Network → Font) |
| R3 | Line length (3) | Every paragraph 45–75 ch at 1366 |
| R4 | Contrast (3) | Every text pair ≥ 4.5:1 in both themes; control edges ≥ 3:1 |
| U1 | Container (5) | Content 1286 px wide at 1366 and 1360 at 1920; hero two columns ≈ 42/58; no empty navy fields |
| U2 | Fold (5) | At 1366 × 768, after choosing a country and a weight, the price number and the whole green button are inside the viewport without scrolling (measure `getBoundingClientRect().bottom ≤ 768`) |
| U3 | Rhythm (5) | Section gaps 72 px desktop / 48 phone; no block of empty space taller than 96 px anywhere on `/` |
| M1 | Phone layout (7) | At 375 × 812 every page: no horizontal scroll (`scrollWidth === innerWidth`), 16 px gutters, tiles 2-up, weights 4-up, services stacked, button 64 px |
| M2 | Targets (5) | Every control ≥ 48 × 48 (tiles, weights, services 56; Book 64); FAQ summaries 56; nav links 48 |
| M3 | Real phone pass (3) | On an Android phone in Chrome: the three taps, the native "More countries" picker (seed data), the WhatsApp hand-off opens the app |
| P1 | Calm and premium (5) | Paper/ink/one orange kept; Big Shoulders only on h1/h2/h3/prices/numbers; hairlines not shadows; no device from section 2.4 survives (grep the built HTML for `eyebrow`, `route-code`, `ghost`, `stamp`, `silk`, `odo`) |
| P2 | The three wow moments (5) | Each plays once per trigger, ≤ 700 ms, and every one has its reduced-motion fallback (emulate `prefers-reduced-motion: reduce`: no keyframes run, nothing at opacity 0) |
| C1 | Behaviour intact (4) | The v1 section-13 functionality list passes by hand: quick rate (all five readout states), detailed quote, hold panel, route/dest tap selects the country, contact form, `/api/quotes` logged on Book, admin publish of the new seed copy shows on the site |
| C2 | Checks (3) | `pnpm typecheck`, `pnpm lint`, `pnpm test` green (93 + `countries.test.ts`); `pnpm build` green; `curl` 200 on `/`, `/services`, `/about`, `/contact`, `/faq`, `/does-not-exist` (404 body) |
| C3 | Performance and CSP (3) | Lighthouse mobile on `/`: LCP is the h1, CLS < 0.05; no `ogl`/`motion` chunk; no console CSP report on any page; `data-theme` present in the server HTML; no `<canvas>` except ClickSpark's |

Scoring: an item scores full points or, when it fails its threshold, zero; reviewers may deduct 1–2 points inside an item for a hesitation the threshold does not name (write it down). Anything that would make the visitor stop and think is a deduction, however pretty.

---

## 10. Ownership notes for this round

- Additions to the map: `src/components/Icons.tsx` (new glyphs) → SHELL+HOME; `package.json`/`pnpm-lock.yaml` (`pnpm add -D flag-icons`, `pnpm remove ogl motion`) → SHELL+HOME; `src/lib/site/countries.ts` + `countries.test.ts`, `scripts/copy-flags.mjs`, `public/flags/**`, `src/components/site/{Flag,ThemeToggle}.tsx` → SHELL+HOME; `src/components/site/pages/NotFoundBody.tsx` and `src/app/not-found.tsx` → INNER; `src/components/calculator/Flag.tsx` (temporary) → CALCULATOR.
- Contracts: `.site` on `ShellWrap`'s div (SHELL+HOME) is the scope every customer override hangs from; `.instrument` stays the calculator's root; `Flag` props `{ code, name, size? }`; `CtaBand` props as in 3.5; `PageHead`/`SectionHead` keep old props optional; `--edge`, `--tap*`, `--fs-price`, `.btn.giant` come from tokens.css and are available from SHELL+HOME's first commit.
- Order of landing: SHELL+HOME tokens + `.site` scope + `Flag` first (green), then the other two in parallel. A check failing in a file you do not own goes into your hand-off note as `open_issues`, not into that file.
- Copy: no new content fields this round; the seed defaults in sections 3.1–3.5 are published on the dev DB through Admin → Website pages by the reviewer. `heroEyebrow` is removed from `Content`, `ContentSchema`, `SEED.content` and `ContentForm`; the headline hint adds "Keep it under 45 characters — two short sentences read best."
