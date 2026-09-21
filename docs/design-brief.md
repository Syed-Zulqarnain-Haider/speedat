# Speedat — build brief: MANIFEST (paper, ink, one orange)

This is the only design document the four engineers get. It is written against the repository as it is today (2026-09-21, branch state with `Calculator.tsx` at 875 lines and `globals.css` at 1737 lines). Every claim marked **verified** was checked in the repo or the React Bits registry before writing; do not re-litigate them, and do not read the proposals — they are superseded here.

Ground truths that shaped this brief (all verified):

- `Hero.tsx`'s static branch renders `s.text` ("3+ days") and then appends `s.suffix` ("+ days"); the server HTML on `/` right now contains `<strong>3+ days<!-- -->+ days</strong>`. Fixed in section 7.
- `/contact` has a "Open in Google Maps" **link**, not an iframe, and the CSP (`frame-src 'self'` + the Firebase auth domain) would block a Maps iframe. Nothing in this brief adds an iframe.
- React Bits `ScrollReveal` (registry source) cleans up with `ScrollTrigger.getAll().forEach(t => t.kill())` — that kills every `Reveal`/`AnimatedContent` trigger on the page under Strict Mode's double effect — and renders its paragraph inside an `<h2>`. Both are patched in section 5.
- Vendored `ShinyText` runs a permanent `useAnimationFrame` loop; vendored `ClickSpark` runs a permanent `requestAnimationFrame` loop that clears the canvas every frame even with no sparks. `Threads` caps DPR with `Math.min(window.devicePixelRatio || 1, 2)` on exactly one line. See section 5 for the minimal edits.
- next/font has `Big Shoulders` (variable, axes `opsz` 10–72 and `wght` 100–900), `Instrument Serif` (400 normal + italic), `JetBrains Mono` (100–800), `Barlow`. `Big_Shoulders_Display` does not exist in this Next version — use `Big_Shoulders`.
- `https://reactbits.dev/r/<Name>-TS-TW.json` returns 200 for Threads (dep `ogl@^1.0.11`), Magnet (no deps), ScrollReveal (gsap), DecryptedText (motion), Waves (no deps).
- The test suite (`src/**/*.test.ts`, node environment) tests `src/lib/**` only; no test pins component markup, class names, or the seed `heroTitle`. `diff.test.ts` and `repo.test.ts` do compare content shapes, so new content fields must be added to `SEED.content` (section 11).
- Destination ids in the live dev database include values like `france` (not only ISO codes). Route codes are derived, never assumed (section 7).
- The calculator's detailed-quote card already carries `id="quote"` (`Calculator.tsx` line 776). The instrument wrapper's anchor is `#quote-instrument`; nothing else may reuse `quote`.
- The 1 kg quick-rate call is exactly `priceAll(site, { destId, type: "pkg", rows: [{ kg: 1, qty: 1 }] })` (`Calculator.tsx` line 218); the route board reuses that call verbatim.

Hard constraints from the task (CSP with nonces and no runtime `<style>`, real server-rendered text first, `useMounted()` before anything client-only, one background effect per page, no invented testimonials/logos/ratings/statistics, React Compiler lint rules, no `any`, no storage in server paths, never touch `.env.local`, never start/stop the dev server, create and edit source files only with the Write/Edit tools, do not commit) apply to every line below and are not repeated per section.

---

## 1. Concept

Speedat should look like the printed manifest of a luxury air-freight house shot as a fashion campaign: warm paper, one deep navy ink, one searing customs-stamp orange, oversized condensed type with a single italic serif word, and a calculator that sits on that paper like a precision instrument whose price rolls in on an odometer. Almost nothing decorates — the type, the whitespace and the number do the work, and the only living surface on the whole site is a field of hairline threads drifting behind the headline. What a visitor keeps without meaning to: the giant headline with its one italic word, the navy readout whose digits roll to the price, and the orange stamp that says Book.

Five words for the feel: **printed · precise · warm · unhurried · certain.**

---

## 2. Design tokens

All tokens live on `:root` in `src/styles/tokens.css` (section 4). The names below that components already use **must survive with the same name** (values evolve): `--bg`, `--surface`, `--ink`, `--muted`, `--line`, `--blue`, `--red`, `--blue-soft`, `--red-soft`, `--ok`, `--ok-soft`, `--warn`, `--warn-soft`, `--gap`, `--focus`, `--wa`, `--wa-hover`, `--book`, `--hot-a`, `--hot-b`, plus the `@theme inline` mappings `--color-bg … --color-warn-soft`, `--font-sans`, `--font-display`. The font variables set by next/font change: `--font-barlow` stays; `--font-barlow-condensed` is removed (Barlow Condensed is no longer loaded); `--font-bigshoulders`, `--font-instrument`, `--font-jetbrains` are new.

The base font size moves from `body { font-size: 17px }` to `html { font-size: 17px }` so that every `rem` below is 17px-based (1rem = 17px, 0.72rem ≈ 12.2px, 1.35rem ≈ 23px). Engineers must not assume 16px.

Paste-ready block (light scheme, then the two dark blocks exactly as the file guards them today):

```css
:root {
  /* ---- paper & ink (light) ---- */
  --bg: #F5F0E7;            /* paper: page background */
  --paper-2: #EDE6D9;       /* tinted panels, zebra rows, idle readout, admin sidebar */
  --surface: #FFFDF9;       /* cards, inputs, instrument panels */
  --ink: #0A1A33;           /* text; ink fills (buttons) */
  --ink-2: #1E2D47;         /* secondary headings */
  --muted: #5A6373;         /* body-secondary, 5.3:1 on paper */
  --line: #DED5C6;          /* hairlines */
  --rule: rgba(10, 26, 51, 0.14);   /* editorial rules on paper */
  --dash: rgba(10, 26, 51, 0.30);   /* dashed borders: idle readout, stamp box */

  /* ---- the one orange ---- */
  --hot-a: #EA580C;         /* surfaces and display numerals >= 24px/700 only (3.1:1 on paper) */
  --hot-b: #B4400C;         /* gradient/hover partner of --hot-a */
  --orange-ink: #9A3412;    /* orange for small text and icons on paper, 6.4:1 */
  --blue: #C2410C;          /* legacy name: text-level accent (links, icons); >= 0.9rem/600 only */
  --red: #EA580C;           /* legacy name: hot accent, same as --hot-a */
  --blue-soft: #FBEEE4;
  --red-soft: #F8DFCF;

  /* ---- status ---- */
  --ok: #0F766E;   --ok-soft: #DDF1EC;
  --warn: #9A5B04; --warn-soft: #FBEFCF;

  /* ---- conversion ---- */
  --wa: #0E7A3F;            /* WhatsApp green: fill of every "WhatsApp us / Live chat / Open WhatsApp" button */
  --wa-hover: #0B6634;
  --wa-ink: #FFFFFF;        /* text on --wa, 5.3:1 */
  --book: #EA580C;          /* the customs stamp: fill of "Book now / Book on WhatsApp" only */
  --book-hover: #D14E0A;
  --book-ink: #0A1A33;      /* text on --book, 4.9:1 (constant; do NOT use --ink here, it flips in dark mode) */
  --focus: #EA580C;
  --gap: #F5F0E7;           /* gap colour inside the stripe mark = the surface it sits on */

  /* ---- navy sections: constant in BOTH schemes (route board, stamp band, footer ghost, readout, login side) ---- */
  --navy: #0A1A33;
  --navy-2: #122544;                        /* hover rows on navy */
  --paper-ink: #F5F0E7;                     /* text on navy, 14.8:1 */
  --paper-70: rgba(245, 240, 231, 0.72);    /* secondary text on navy, > 7:1 */
  --paper-45: rgba(245, 240, 231, 0.45);    /* disabled/decorative on navy, never for text */
  --navy-line: rgba(245, 240, 231, 0.14);   /* hairlines on navy */
  --navy-rule: rgba(245, 240, 231, 0.25);   /* the rule above a sum on navy */
  --navy-accent: #F97316;                   /* orange on navy, 6.2:1 */

  /* ---- shadows (cards get none; only instrument panels and the publish bar) ---- */
  --shadow-instrument: 0 1px 0 rgba(10, 26, 51, 0.06), 0 24px 48px -28px rgba(10, 26, 51, 0.28);
  --shadow-bar: 0 -1px 0 rgba(10, 26, 51, 0.06), 0 -16px 40px -24px rgba(10, 26, 51, 0.30);
  --glow-live: 0 0 0 4px rgba(234, 88, 12, 0.14), 0 0 32px rgba(255, 138, 61, 0.35);
  --glass: color-mix(in srgb, var(--bg) 92%, transparent);

  /* ---- type scale (html = 17px) ---- */
  --fs-hero: clamp(3.4rem, 1.4rem + 6vw, 7.75rem);    /* 54px @375, 68px @768, 104px @1366 */
  --fs-h1: clamp(2.75rem, 1.5rem + 4vw, 5.25rem);
  --fs-h2: clamp(2rem, 1.35rem + 2.2vw, 3.25rem);
  --fs-h3: clamp(1.35rem, 1.2rem + 0.6vw, 1.7rem);
  --fs-stat: clamp(2.25rem, 1.5rem + 2.5vw, 3.5rem);
  --fs-total: clamp(3rem, 1.75rem + 7vw, 5.25rem);    /* 50px @375, 84px from ~800px */
  --fs-ghost: clamp(4rem, 14vw, 13rem);
  --fs-numeral: 4.5rem;                                /* the 01/02/03 step numerals */
  --fs-lede: clamp(1.1rem, 1rem + 0.45vw, 1.3rem);
  --fs-body: 1.0625rem;
  --fs-small: 0.9rem;
  --fs-eyebrow: 0.72rem;
  --lh-display: 0.92;   --lh-h1: 0.95;   --lh-h2: 1;   --lh-h3: 1.1;   --lh-body: 1.55;   --lh-lede: 1.5;
  --ls-display: -0.025em;   --ls-h1: -0.02em;   --ls-h2: -0.015em;   --ls-mono: 0.02em;   --ls-eyebrow: 0.16em;   --ls-tab: 0.12em;

  /* ---- spacing ---- */
  --s1: 4px; --s2: 8px; --s3: 12px; --s4: 16px; --s5: 24px; --s6: 32px; --s7: 48px; --s8: 64px; --s9: 96px; --s10: 128px;
  --section: var(--s9);          /* between home sections (desktop); phone overrides to --s8 */
  --card-pad: 24px;              /* phone overrides to 20px */
  --panel-pad: 24px;             /* instrument panels; phone overrides to 16px */

  /* ---- radii (nothing above 16px anywhere) ---- */
  --r1: 4px;  --r2: 6px;  --r3: 10px;  --r4: 16px;  --r-pill: 999px;

  /* ---- layout ---- */
  --wrap: 1240px;
  --gutter: 16px;                /* 24px at >= 720, 40px at >= 1200 */
  --head-h: 56px;                /* 64px at >= 900 */
  --sidebar-w: 232px;

  /* ---- z layers ---- */
  --z-bg: 0;        /* living background inside a positioned hero */
  --z-fg: 1;        /* hero text over the background */
  --z-sticky: 5;    /* sticky table column, sticky hero column */
  --z-subnav: 15;   /* admin section tabs */
  --z-head: 20;     /* site + admin header */
  --z-pubbar: 30;   /* admin publish pill */
  --z-toast: 40;

  /* ---- motion ---- */
  --ease-out: cubic-bezier(0.22, 1, 0.36, 1);
  --ease-stamp: cubic-bezier(0.2, 0.9, 0.3, 1.2);
  --t-fast: 150ms;      /* hover, focus, colour */
  --t-print: 350ms;     /* readout paper -> ink */
  --t-odo: 600ms;       /* one odometer digit */
  --t-reveal: 700ms;    /* scroll reveals */
  --t-hero: 900ms;      /* hero word entrance */
  --t-num: 1.4s;        /* CountUp */
  --stagger-word: 60ms; --stagger-row: 40ms; --stagger-card: 80ms;

  color-scheme: light dark;
}

@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --bg: #0B1424; --paper-2: #101B2F; --surface: #121D31;
    --ink: #F1ECE2; --ink-2: #D9D3C7; --muted: #A9B1C0; --line: #26334C;
    --rule: rgba(241, 236, 226, 0.14); --dash: rgba(241, 236, 226, 0.30);
    --hot-a: #F97316; --hot-b: #C2410C; --orange-ink: #FDBA74; --blue: #FDBA74; --red: #FB923C;
    --blue-soft: #3A2416; --red-soft: #4A2413;
    --ok: #5EEAD4; --ok-soft: #10322F; --warn: #FCD34D; --warn-soft: #3B2F0B;
    --wa: #0E7A3F; --wa-hover: #0B6634; --wa-ink: #FFFFFF;
    --book: #F97316; --book-hover: #EA580C; --book-ink: #0B1424;
    --focus: #FB923C; --gap: #121D31;
    --shadow-instrument: 0 1px 0 rgba(0, 0, 0, 0.35), 0 24px 48px -28px rgba(0, 0, 0, 0.7);
    --shadow-bar: 0 -1px 0 rgba(0, 0, 0, 0.35), 0 -16px 40px -24px rgba(0, 0, 0, 0.7);
  }
}
:root[data-theme="dark"] {
  /* identical property list to the block above — copy it verbatim */
}
```

Responsive token overrides, also in `tokens.css` directly under the blocks above (the only place breakpoints change a token):

```css
@media (min-width: 720px)  { :root { --gutter: 24px; } }
@media (min-width: 900px)  { :root { --head-h: 64px; } }
@media (min-width: 1200px) { :root { --gutter: 40px; } }
@media (max-width: 719px)  { :root { --section: var(--s8); --card-pad: 20px; --panel-pad: 16px; } }
```

Rules the reviewer enforces:

- Body text only `--ink` / `--ink-2` / `--muted` on `--bg` / `--surface` / `--paper-2`.
- Orange as text only via `--orange-ink` (small) or `--hot-a` at display sizes ≥ 24px and weight ≥ 700. Never `--hot-a` in a `.hint`, label or table cell.
- `--book-ink` on every orange button; `--wa-ink` on every green button; `--paper-ink` on every navy surface. Never `--ink` on `--navy` (it flips to near-white in dark mode and the readout would vanish).
- Dark mode, day one: navy sections **stay navy** and gain `border: 1px solid var(--line)` so they read as a panel on the dark page. The full paper-2 inversion is descoped.
- The `--navy*` and `--paper-*` tokens are constants; never redefine them in the dark blocks.
- Borders: hairline `1px solid var(--line)` on cards/inputs; `2px solid var(--ink)` editorial rules above headings (the `.rule` utility: `display:block; width:48px; height:2px; background:var(--ink); margin-bottom:16px`); `3px solid var(--hot-a)` left bar on every selected/active/changed state; `1px dashed var(--dash)` for the idle readout and the quote stamp box.
- Shadows: none on cards; only `--shadow-instrument` on `.panel`, `.results`, `.quote`, the login card and the admin publish pill. Hover on cards = `border-color: var(--ink)` over `--t-fast`, never a lift.
- `@theme inline` in `src/app/globals.css` keeps the `--color-*` mappings, remaps the fonts (`--font-sans: var(--font-barlow), "Helvetica Neue", Arial, sans-serif; --font-display: var(--font-bigshoulders), "Arial Narrow", Impact, sans-serif; --font-serif: var(--font-instrument), Georgia, serif; --font-mono: var(--font-jetbrains), ui-monospace, Menlo, Consolas, monospace;`) and **deletes** the `--animate-star-movement-*` variables and both `star-movement` keyframes.
- `viewport.themeColor` in `src/app/layout.tsx` becomes `#F5F0E7` (light) / `#0B1424` (dark).

---

## 3. Typography

Loaded in `src/app/layout.tsx` (FOUNDATION), replacing the Barlow Condensed import:

```ts
import { Barlow, Big_Shoulders, Instrument_Serif, JetBrains_Mono } from "next/font/google";

const barlow = Barlow({ variable: "--font-barlow", subsets: ["latin"], weight: ["400", "500", "600"], display: "swap" });
const display = Big_Shoulders({ variable: "--font-bigshoulders", subsets: ["latin"], weight: "variable", axes: ["opsz"], display: "swap" });
const serif = Instrument_Serif({ variable: "--font-instrument", subsets: ["latin"], weight: "400", style: "italic", display: "swap", preload: false });
const mono = JetBrains_Mono({ variable: "--font-jetbrains", subsets: ["latin"], weight: ["400", "500"], display: "swap", preload: false });
// <html lang="en" className={`${barlow.variable} ${display.variable} ${serif.variable} ${mono.variable}`}>
```

Only Barlow and Big Shoulders preload (they are on the LCP path); the serif and mono load on first use. Record the Big Shoulders woff2 size from the `pnpm build` output in the hand-off note.

| Family | Role | Weights used | Where | Never |
|---|---|---|---|---|
| **Big Shoulders** (variable, `font-optical-sizing: auto`) | DISPLAY | 200 (giant numerals 01/02/03, footer ghost, 404 ghost), 600 (small headings ≥ 1.35rem, instrument `h2.step`, admin h1/h2), 700 (prices, stats, brand name, admin tile values), 800 (hero h1, page h1) | `h1, h2, h3, .display, .brand-name, .stats strong, .tval, .svc-price, .opt-price, .numeral, .ghost, .tile .val` | below **1.35rem** (poster face, not a text face); all-caps headlines; buttons; table cells; body |
| **Instrument Serif** italic 400 | ACCENT | 400 italic | exactly one `*word*` per headline through the accent parser; `em.accent { font-family: var(--font-serif); font-style: italic; font-weight: 400; font-size: 1.04em; color: var(--orange-ink) }` and `#F97316` (`--navy-accent`) on navy | anything that is not the marked word |
| **Barlow** 400/500/600 | BODY | 400 body/lede, 500 inputs and option names, 600 buttons, nav, labels that are not eyebrows, route names, FAQ questions | everything not listed elsewhere | 700 (not loaded) |
| **JetBrains Mono** 400/500 | INSTRUMENT | 500 eyebrows, tabs; 400 everything else | eyebrows, readout labels, quote ids, route codes, kg chips, `.tlines`, `.status`, `.quote dl`, every numeric admin table cell, admin meta lines, the 6-digit code input | headings, buttons over 0.95rem, body copy |

Always `font-variant-numeric: tabular-nums` on: `.tval`, `.stats strong`, `.tile .val`, `.svc-price`, `.opt-price`, `.route-price`, every `td input.num`, `.grid-box input`, `.qid`, `.code`.

Scale and rules (tokens from section 2): hero `--fs-hero / --lh-display / --ls-display / 800 / max-width 11ch`; h1 `--fs-h1 / 0.95 / -0.02em / 800`; h2 `--fs-h2 / 1 / -0.015em / 700`; h3 `--fs-h3 / 1.1 / 0 / 600`; lede `--fs-lede` Barlow 400 `--muted` max-width 52ch lh 1.5; body `--fs-body / 1.55`; small `--fs-small`; eyebrow `.eyebrow { font: 500 var(--fs-eyebrow)/1.2 var(--font-mono); text-transform: uppercase; letter-spacing: var(--ls-eyebrow); color: var(--orange-ink) }` (`.on-navy .eyebrow { color: var(--navy-accent) }`). Letter-spacing rule: display ≥ 64px −0.025em; 32–64px −0.015em; < 32px 0; body 0; mono readouts +0.02em; eyebrows +0.16em.

Every section h2 and every page h1 is preceded by the same header block, in this DOM order: `<p class="eyebrow">0N — Name</p>` → `<span class="rule" aria-hidden="true"></span>` → the heading. Home sections are numbered 01–05 (01 Rate, 02 Routes, 03 How it works, 04 Services, 05 Book); inner pages carry the nav order (02 Services, 03 About, 04 Contact, 05 FAQ).

**The accent parser** (FOUNDATION, `src/lib/site/accent.ts`, pure, no `"use client"`, unit-tested in `src/lib/site/accent.test.ts`):

```ts
export interface AccentParts { before: string; accent: string; after: string }   // accent === "" means no italic
export function parseAccent(text: string): AccentParts
export interface AccentWord { text: string; accent: boolean; head?: string; tail?: string }
export function accentWords(text: string): AccentWord[]
```

`parseAccent`: `i = text.indexOf("*")`; `j = i >= 0 ? text.indexOf("*", i + 1) : -1`; `inner = j > i + 1 ? text.slice(i + 1, j) : ""`. If `inner.trim() === ""` or `inner` contains a newline → `{ before: text, accent: "", after: "" }` (the text prints exactly as typed, asterisks included). Otherwise `{ before: text.slice(0, i), accent: inner, after: text.slice(j + 1) }`; any further asterisks in `after` print literally. `accentWords` splits `before`/`accent`/`after` on whitespace and flags the accent words; if `after` starts with a non-space run (e.g. `,`), that run becomes `tail` of the last accent word; if `before` ends with a non-space run (e.g. `(`), it becomes `head` of the first accent word — so punctuation never becomes its own word. Tests: no asterisk; one lone asterisk; `**`; a normal pair; a pair followed by a third asterisk; a pair with trailing comma (tail).

`src/components/site/Accent.tsx` (server-safe, no `"use client"`): `<Accent text={s} />` → `{before}<em className="accent">{accent}</em>{after}`. Used by every h1/h2 that reads a content field.

---

## 4. CSS architecture

`src/app/globals.css` becomes only:

```css
@import "tailwindcss";
@import "../styles/tokens.css";
@import "../styles/site.css";
@import "../styles/calculator.css";
@import "../styles/admin.css";
@import "../styles/print.css";
@theme inline { /* the --color-* and --font-* mappings from section 2, nothing else */ }
```

Tailwind 4 inlines these imports, so `@media`, keyframes and custom properties inside them work unchanged. The vendored bits keep using a handful of Tailwind utilities (`relative`, `w-full`, `inline-block`, `absolute inset-0`) which Tailwind generates because `src/components/bits/**` is in the scan path.

| File | Owner | Contains | Class prefixes / selectors it owns |
|---|---|---|---|
| `src/styles/tokens.css` | FOUNDATION | `:root` + both dark blocks; base element styles (`html`, `body`, `h1–h3`, `p`, `a`, form-control reset, `:focus-visible`, `input[type=radio/checkbox]`, `[hidden]`); layout utilities; shared components; all shared keyframes (inside `@media (prefers-reduced-motion: no-preference)`) | `.sr`, `.wrap`, `.g12`, `.eyebrow`, `.rule`, `.display`, `.mono`, `.accent`, `.numeral`, `.bleed` (full-bleed helper), `.on-navy`, `.btn` and every `.btn.*` variant, `.tag`, `.notice` and `.notice.*`, `.hint`, `.meta`, `.field`, `.lab`, `.row`, base input/select/textarea skin, `.panel` (base card skin), `h2.step` (base), `.toast`, `.pull`/`.pull-in`, `.link` (growing underline), keyframes `rise`, `fadein`, `stamp`, `sweep`, `.count` (nav count pill) |
| `src/styles/site.css` | three banners | public-site layout in three labelled blocks, in this order | `/* ===== SHELL ===== */` (FOUNDATION): `.site-head`, `.brand-*`, `.mark`, `.nav`, `.head-actions`, `.site-foot`, `.foot-*`, `.ghost`, `.page-head` (PageHead), `.silk` — `/* ===== HOME ===== */` (HOME): `.calc`, `.hero*`, `.manifest`, `.jump`, `.instrument` wrapper geometry only (`#quote-instrument` min-height, column width), `.promise`, `.routes`, `.route-*`, `.steps`, `.step-*`, `.teaser`, `.hold-*` — `/* ===== PAGES ===== */` (INNER): `.page`, `.prose`, `.cards`, `.card`, `.story*`, `.drop`, `.mv`, `.values`, `.contact-sheet`, `.contact-form`, `.visit`, `.faq`, `.cta-band`, `.nf`, `.err` |
| `src/styles/calculator.css` | CALCULATOR | everything under the instrument | `.instrument .panel`, `.instrument .field`, `.modes`, `.chips`/`.chip`, `.opts`/`.opt*`, `.addons`, `.total*`, `.tval`, `.odo*`, `.col`, `.strip`, `.sym`, `.sweep`, `.tlines`, `.tnote`, `.ctas`, `.seg*`, `.units`/`.ubtn`, `.pieces`/`.piece`/`.ptitle`/`.dims`/`.addrow`, `.results`, `.stripe`, `.svcs`/`.svc*`, `.status`, `.addons-detail`, `.quote*`, `.qid`, `.stamp`, `details.how`, `.includes`, `.disclaimer`, `.sample`, `.static` |
| `src/styles/admin.css` | ADMIN | the admin app, scoped under `.admin-app` or `.login` | `.admin-app`, `.admin-side`, `.admin-top`, `.admin-main`, `.anav*`, `.admin`, `.topbar`, `.pill*`, `.dash`, `.tile*`, `.subnav`, `section.block`, `.toolbar`, `.tablewrap`, `.admin-app table/th/td` (scoped), `.grid-*`, `table.rates*`, `table.preview`, `.map-grid`, `.grid2`, `.chk`, `.inline`, `.pubbar`, `.diff`, `.hist`, `.test-out`, `.lead*`, `.ship*`, `.timeline*`, `.check*`, `.login*`, `.code`, `.hold-bar`, `.notice.hold`, `.tag.hold-tag`, `.quiet` |
| `src/styles/print.css` | FOUNDATION | the existing `@media print` block moved verbatim, plus `.admin-app { display: block } .admin-side, .admin-top, .pubbar, .subnav { display: none }` and `.hero-bg, .silk { display: none }` | — |

Rules:

1. A file may only add rules whose first selector is a prefix it owns. Shared skins (`.btn`, `.panel`, `.field`, inputs, `.notice`, `.tag`) live in `tokens.css`; other files may **override** them only under their own scope: `.instrument .panel …` (calculator), `.admin-app .panel …` / `.login .panel …` (admin), `.page .contact-form …` (INNER), `.hold-panel …` (HOME).
2. Element selectors (`table`, `th`, `td`, `h1`) outside `tokens.css` must be scoped (`.admin-app th`, `.page h2`).
3. Every transition/animation goes inside a `@media (prefers-reduced-motion: no-preference) { … }` block in its own file. Hover colour changes over `--t-fast` are the only motion allowed outside it (they are not motion).
4. No `!important` except where overriding a vendored inline style is unavoidable (document each one in a comment).
5. Each file starts with a banner comment naming the owner; `site.css` has the three banners. Engineers edit only inside their banner.
6. Delete from the old `globals.css` on migration: `.wrap.mid`, `.wrap.wide`, `.hero-animated`, `.hero-dots`, `.split-parent`, `.split-line`, `.cta-star*`, `.total.pop` + `pop` keyframe, the star-movement keyframes, `.card.accent*`.

---

## 5. React Bits inventory

Vendor with `cd /d/speedat && node scripts/fetch-reactbits.mjs Threads Magnet ScrollReveal` then `pnpm add ogl`. Vendored files are lint-ignored; wrap them, do not restyle them. Every wrapper lives in `src/components/site/fx/` (FOUNDATION) and has the same shape: `"use client"`, reads `useMounted()`, `useReducedMotion()`, and (where relevant) `useSaveData()` / `useMediaQuery()` / `useCanFx()` from `src/lib/client/motion.ts`, and returns identical server-safe markup in the fallback branch.

New hooks in `src/lib/client/motion.ts` (FOUNDATION), all `useSyncExternalStore` with a `false` server snapshot:

- `useMediaQuery(query: string): boolean` — cache `MediaQueryList` per query in a module `Map`; `subscribe` memoised with `useCallback([query])`.
- `useSaveData(): boolean` — `(navigator as Navigator & { connection?: { saveData?: boolean } }).connection?.saveData === true`; subscribe is a no-op.
- `useCanFx(): boolean` — module-level cached probe: `try { const c = document.createElement("canvas"); ok = !!(c.getContext("webgl2") ?? c.getContext("webgl")); } catch { ok = false }`; a thrown `getContext` is `false`.

| Registry name | Action | Wrapper (props) | Where | Fallback (also what SSR paints) | Vendored edits |
|---|---|---|---|---|---|
| **Threads** | NEW (dep `ogl`) | `fx/LivingBackground.tsx`: `next/dynamic(() => import("@/components/bits/Threads"), { ssr: false })`; mounts the canvas only when `mounted && !reduced && !saveData && useMediaQuery("(min-width: 720px)") && useCanFx()`; props `color={dark ? [0.95, 0.93, 0.89] : [0.04, 0.10, 0.20]}` where `dark = useMediaQuery("(prefers-color-scheme: dark)")`, `amplitude={0.8}`, `distance={0.2}`, `enableMouseInteraction={false}` | behind the home hero column only | the inline `.silk` SVG (section 7) is always rendered underneath; the canvas fades over it (`.hero-canvas { animation: fadein 600ms var(--ease-out) both }`) so nothing pops | one line: `Math.min(window.devicePixelRatio \|\| 1, 2)` → `1.5` |
| **Magnet** | NEW (no deps) | `fx/Pull.tsx` `({ children, className })`: `const cls = className ? \`pull ${className}\` : "pull"`; when `mounted && !reduced && useMediaQuery("(hover: hover) and (pointer: fine)")` → `<Magnet padding={32} magnetStrength={4} wrapperClassName={cls} innerClassName="pull-in">`; otherwise `<div className={cls}><div className="pull-in">{children}</div></div>` (same DOM depth both ways; no class-name helper exists in the repo, so build the string inline) | header "WhatsApp us", stamp-band "WhatsApp us", quick-rate "Book now", detailed "Book on WhatsApp" | the bare wrapper divs | none (its inline `display:inline-block` is blockified inside grid/flex parents; `.ctas .pull .btn, .quote .actions .pull .btn { width: 100% }` in calculator.css) |
| **ScrollReveal** | NEW (gsap present) | `fx/StoryReveal.tsx` `({ text })`: `<ScrollReveal baseOpacity={0.15} baseRotation={2} enableBlur blurStrength={6} textClassName="story-first">{text}</ScrollReveal>` | first paragraph of `content.story` on /about | `<p className="story-first">{text}</p>` — identical text | three minimal edits: (1) outer `<h2 …>` → `<div …>` and remove `my-5` (a paragraph must not be a heading); (2) inner `<p className={\`text-[clamp(1.6rem,4vw,3rem)] leading-[1.5] font-semibold ${textClassName}\`}>` → `<p className={textClassName}>`; (3) capture the tweens — `const tweens = [gsap.fromTo(...), gsap.fromTo(...), ...(enableBlur ? [gsap.fromTo(...)] : [])]` — and clean up with `tweens.forEach(t => { t.scrollTrigger?.kill(); t.kill(); })` instead of `ScrollTrigger.getAll().forEach(...)` |
| **AnimatedContent** | EXISTING, keep | `site/Reveal.tsx` (existing; FOUNDATION may add a `stagger` prop = delay multiplier) | route rows (40ms stagger, cap 8), steps (80ms), service cards, about values, FAQ rows | plain `<div>` (existing branch) | none (it kills its own `st` and `tl`) |
| **SpotlightCard** | EXISTING, keep | `site/ServiceCard.tsx` (existing), `spotlightColor="rgba(234, 88, 12, 0.14)"` | service cards (home teaser + /services) | plain `.card` (existing branch) | none |
| **CountUp** | EXISTING, keep | inside `Hero.tsx` (existing) `duration={1.4} delay={0.3}` | manifest strip numbers | the formatted text (existing branch) | none |
| **ClickSpark** | EXISTING, keep | inside `Calculator.tsx` (existing) `sparkColor="#EA580C"` | quick-rate CTAs (existing), detailed "Book on WhatsApp" (new wrap) | none needed (canvas is decorative) | idle-loop fix (verified it clears the canvas every frame forever): add `const kickRef = useRef<() => void>(() => {});` next to `sparksRef`; in the draw effect replace the two `animationId = requestAnimationFrame(draw)` lines so that the tail of `draw` reads `animationId = sparksRef.current.length ? requestAnimationFrame(draw) : 0;`, the initial call becomes `animationId = 0;` and, before the cleanup, `kickRef.current = () => { if (!animationId) animationId = requestAnimationFrame(draw); };`; in `handleClick` after `sparksRef.current.push(...newSparks)` add `kickRef.current();`. Six lines, no other change. |
| **DecryptedText** | OPTIONAL P2 (motion present) | `fx/Decrypt.tsx` `({ text })` → `<DecryptedText text={text} animateOn="view" sequential speed={40} characters="0123456789ABCDEFGHJKLMNPQRSTUVWXYZ" />`; fallback `<span>{text}</span>` | the quote id in the manifest card | static id | none. Only after the Definition of Done is green and the home route did not grow past budget. |
| **SplitText** | RETIRE | — | — | — | delete `src/components/bits/SplitText.tsx` (the hero words now animate from first paint with CSS, section 7) |
| **BlurText** | RETIRE | — | — | — | delete `src/components/bits/BlurText.tsx` (same reason) |
| **ShinyText** | RETIRE | — | — | — | delete `src/components/bits/ShinyText.tsx`; `Tagline.tsx` becomes a plain server component rendering `<span className="brand-tag">{text}</span>` (it ran a permanent rAF loop; "nothing loops") |
| **DotGrid** | RETIRE | — | — | — | delete `src/components/bits/DotGrid.tsx` and the `hero-bg`/`hero-dots` usage |
| **StarBorder** | RETIRE | — | — | — | delete `src/components/bits/StarBorder.tsx`, `src/components/site/CtaStar.tsx`, the `@theme` keyframes |
| Waves | FALLBACK ONLY | swap in for Threads inside `LivingBackground` only if the `pnpm build` route table shows `/` grew by more than 60 kB gzipped; before using it change its `window.addEventListener('touchmove', …, { passive: false })` to `{ passive: true }`; keep the ≥ 720px gate; `lineColor="rgba(10,26,51,.18)"` | — | same SVG | as stated |

Checked and rejected (do not vendor): Counter (no thousands separators — `PriceReadout` is hand-rolled), GradualBlur / MagicBento / GooeyNav / StaggeredMenu / TextPressure (inject `<style>` at runtime — CSP), PillNav (react-router-dom), CardNav (react-icons), GlareHover (only animates on mouseenter/leave — cannot fire from a state change), Silk / Beams / Galaxy / Lightning / Dither / PixelBlast / ColorBends / Hyperspeed / Ballpit (three.js). Extra npm dependency allowed: **`ogl` only.**

Hand-rolled (no library) client components, all FOUNDATION unless noted: `calculator/PriceReadout.tsx` (CALCULATOR owns it; spec in section 8), `fx/LivingBackground.tsx`, `fx/Pull.tsx`, `fx/StoryReveal.tsx`, `site/Accent.tsx`, `site/PageHead.tsx`, `site/home/HeroTitle.tsx` (HOME).

---

## 6. Shell

**Container.** `.wrap { max-width: var(--wrap); margin: 0 auto; padding: 0 var(--gutter) }` with `--gutter` 16px (< 720), 24px (720–1199), 40px (≥ 1200). `ShellWrap.tsx` renders `<div className="wrap">` always (delete the pathname switch; keep the component and its `"use client"` so the layout does not change shape). `.g12 { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 16px }` → `gap: 24px` at ≥ 1024. `body { overflow-x: clip }`. `html { scroll-padding-top: calc(var(--head-h) + 16px) }` so anchor jumps clear the sticky header. `.bleed { margin-inline: calc(50% - 50vw); padding-inline: max(var(--gutter), calc(50vw - 620px)) }` is the full-bleed helper for navy sections.

**Header** (`src/app/(site)/layout.tsx`, FOUNDATION): `<header className="site-head">` is `position: sticky; top: 0; z-index: var(--z-head); height: var(--head-h); background: var(--glass); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line)`. Layout at ≥ 900: one row, three zones — `.brand-link` (the `.mark` 32px radius 6 stripe square + `.brand-name` Big Shoulders 700 1.4rem + `.brand-tag` mono eyebrow 0.68rem `--muted`, not orange) | `<Nav />` centred (links Barlow 600 0.95rem `--muted`, hover `--ink`, current `--ink` with a 2px `--hot-a` underline offset 6px) | `.head-actions`: Call = `.btn.outline.small` (1px `--ink` border, ink text, phone glyph, number hidden < 560 as today) and "WhatsApp us" = `<Pull><a className="btn wa small">` (green fill, white text, `UI.wa` glyph). Below 900: the brand row is 56px and the nav stays the existing scrollable second row (`margin: 0 calc(var(--gutter) * -1); padding: 0 var(--gutter)`, no scrollbar). Below 640 the brand splits: FOUNDATION renders `c.name` as `first word` in `.brand-name` and the remaining words in `.brand-tag` (guarded: a single-word name renders as today), so "Speedat / International Courier" never wraps to three lines beside two buttons at 375.

`UI.wa` (FOUNDATION adds it to `src/components/Icons.tsx`): a 24×24 stroke glyph of a rounded speech bubble with a small handset inside, drawn as original paths in the same `base` style as `UI.chat` — not the trademarked logo. Glyph colour is `currentColor` everywhere: white on green buttons, `--book-ink` on orange buttons (green on orange fails contrast, so the "green glyph on orange" idea is dropped; the word "WhatsApp" in the label carries the recognition).

**Nav** (`Nav.tsx`): unchanged markup; `aria-current` stays; the header nav keeps its own current-page underline rule and does not use `.link`. The growing-underline `.link` utility (`background-image: linear-gradient(currentColor, currentColor); background-size: 0 1px → 100% 1px; background-position: 0 100%; background-repeat: no-repeat; transition: background-size var(--t-fast)`) is for in-copy links, footer links and "All services →".

**Buttons** (`tokens.css`): `.btn` 44px, radius `--r2`, padding 0 16px, Barlow 600 0.95rem, surface bg, hairline, ink text; `.btn.small` 36px; `.btn.big` 52px 1.05rem; `.btn.outline` transparent bg, 1px `--ink`; `.btn.primary` `--ink` fill, `--bg` text; `.btn.wa` and `.btn.chat` (same look, the class name `chat` stays in Calculator.tsx) `--wa` fill, `--wa-ink` text, border `--wa`, hover `--wa-hover`; `.btn.book` `--book` fill, `--book-ink` text, border `--book`, hover `--book-hover` with border `--hot-b`; `.btn.book[aria-disabled="true"], .btn:disabled { opacity: .45; pointer-events: none }`; `.btn.danger` `--red` text. Hover on neutral/outline: `border-color: var(--ink)`; on fills: `filter: brightness(.94)`. Focus: the global `:focus-visible` 3px `--focus` offset 2px — do not override it anywhere. `.btn svg { width: 18px; height: 18px }`.

**Footer** (`layout.tsx`): `footer.site-foot { position: relative; overflow: clip; padding: var(--s8) 0 var(--s6); margin-top: var(--section); border-top: 1px solid var(--line) }`. Ghost watermark: `<span className="ghost" aria-hidden="true">{c.tagline}</span>` — `position: absolute; left: 0; bottom: -0.12em; font: 200 var(--fs-ghost)/0.9 var(--font-display); color: var(--ink); opacity: .06; white-space: nowrap; pointer-events: none; user-select: none`. Columns over it (`.foot-grid`, `1.4fr 1fr 1fr` at ≥ 640): brand (name Big Shoulders 700 1.4rem, tagline mono eyebrow, address `--muted`), Pages (Get a quote / Services / About us / Contact / FAQ as `.link`s), Contact (WhatsApp, phone, email, hours — all from `company`/`content` as today). Column headings are `.eyebrow`, not h3 (keep the `<h3>` elements for landmarks but style them as eyebrows). `.foot-bottom` keeps "Rates updated {date} · © {year} {name}" in mono 0.78rem `--muted`.

**Hold panel** (`HoldPanel.tsx`, HOME): keeps `.panel.quick.hold-panel` and every prop and handler. `.hold-msg` becomes `background: var(--paper-2); color: var(--orange-ink); border-left: 3px solid var(--hot-a); border-radius: 0 var(--r3) var(--r3) 0; padding: 14px 16px; font-weight: 500`; the `h2.step` icon stays `UI.clock` in `--orange-ink`; fields take the instrument skin from calculator.css automatically (the panel sits inside `.instrument`); the big button `.btn.wa.big` is green, full width, 56px; `.soft` keeps 75% opacity; the hint under it is mono 0.85rem centred. No price ever reaches this component (unchanged).

**404 / error** (INNER): both use `PageHead` (eyebrow "404" / "Error", h1 as today, lede as today) inside `<section className="page nf">` with a ghost numeral behind: `<span className="ghost nf-ghost" aria-hidden="true">404</span>` (display 200 `--fs-ghost`, opacity .06, top-right, clipped). The action button becomes `.btn.primary.big`; `error.tsx` keeps its `useEffect` console log and `reset` handler untouched.

---

## 7. Home page

Order on every width: header → 01 hero + instrument → 02 route board → 03 how it works → 04 services teaser → 05 stamp band → footer. The LCP element is the server-rendered h1 (or, because its words rise in, the lede/instrument — all server text). Section rhythm `--section` (96px desktop, 64px phone); inside a section 32px between the title block and its content.

Data helpers HOME writes in `src/components/site/home/`:
- `eyebrow.ts` — `heroEyebrow(site): string`: if `site.content.heroEyebrow.trim()` return it; else `${origin} → ${names}` where `origin = originCities(site.company)[0] ?? site.company.origin`, `names` = the first five **active** destinations in stored order (the same order `DestOptions` calls "Popular") joined with ` · `, plus ` · and ${n} more` when more remain.
- `routes.ts` — `routeRows(site, holdOn): RouteRowData[]` with `{ id, code, name, days: string | null, price: string | null }`: active destinations sorted `localeCompare`; `code = (id.length <= 3 ? id : id.slice(0, 3)).toUpperCase()` (label only, keyed by `id`); `days` = across `site.services`, `parseDaysRange(dest.rates[svc.id]?.days)`; take the range with the smallest lower bound and print `${lo}–${hi} days` (`${lo} days` when equal), `null` when none; `price` = `null` when `holdOn`, else `const r = priceAll(site, { destId: id, type: "pkg", rows: [{ kg: 1, qty: 1 }] })`; if `r.ok`, the minimum `total` over non-null `r.prices[svc.id]` → `fmtMoney(min, site.settings.currency)`, else `null`. A unit test `src/components/site/home/routes.test.ts` asserts, on `gridSeed()` and `SEED`, that each row's price equals the cheapest `priceAll` 1 kg total, that `price` is `null` under hold, and that `code` is `GB` for `gb` and `FRA` for `france`.

### 01 — Hero + instrument (`src/app/(site)/page.tsx`, `Hero.tsx`, `home/*`)

(a) Sources: eyebrow `heroEyebrow(site)`; h1 `content.heroTitle` through `accentWords`; sub `content.heroSub`; strip `heroStats(site)` (existing function, unchanged logic); promise line `content.promise`; the instrument is `<Calculator site={site} />` or `<HoldPanel …/>` (unchanged props); `.sample` notice when `!site.live`.

(b) Layout. `<section className="calc">` is the split: at ≥ 1024 `display: grid; grid-template-columns: minmax(0, 6fr) minmax(0, 6fr); gap: 48px; align-items: start`; `.hero` is `position: sticky; top: calc(var(--head-h) + 24px); align-self: start; max-height: calc(100vh - var(--head-h) - 24px)`; sticky is dropped under `@media (max-height: 820px)`. The instrument column `#quote-instrument.instrument { max-width: 600px; min-height: 560px }` (min-height only at ≥ 1024 — the CLS guard while `useSession` snapshots settle). Below 1024 they stack, hero first, full width inside the 16px gutters. At 375: eyebrow → h1 (54px, 3–4 lines) → lede → manifest strip (three cells in a row, hairline-separated, wrapping to 2+1 only under 340px) → "Check your rate ↓" jump link → instrument. At 1366: hero column ≈ 560px wide sticky on the left, instrument 560–600px on the right, both starting at the same top edge.

(c) Components. `Hero.tsx` stays a client component (CountUp) but its markup becomes:

```
<div class="hero">
  <div class="hero-bg" aria-hidden>            ← LivingBackground (SVG + optional canvas)
  <div class="hero-fg">
    <p class="eyebrow">{eyebrow}</p>
    <HeroTitle text={title} />                  ← server-safe h1, words in spans
    <p class="lede">{sub}</p>
    <ul class="stats manifest">…</ul>
    <a class="jump btn outline" href="#quote-instrument">Check your rate ↓</a>   ← display:none at >= 1024
  </div>
</div>
```

`home/HeroTitle.tsx` (no `"use client"`): `<h1 className="hero-title">` containing, for each `w` of `accentWords(text)` at index `i`, `<span key={i} className="w" style={{ "--i": Math.min(i, 12) } as CSSProperties}>{w.head}{w.accent ? <em className="accent">{w.text}</em> : w.text}{w.tail}</span>` followed by a literal `" "` text node (except after the last word) so the browser can wrap lines between words. CSS (HOME block): `.hero-title .w { display: inline-block }`; inside the no-preference block `.hero-title .w { animation: rise var(--t-hero) var(--ease-out) both; animation-delay: calc(var(--i) * var(--stagger-word)) }` with `@keyframes rise { from { opacity: 0; transform: translateY(24px) } to { opacity: 1; transform: none } }` in tokens.css; `.hero .lede { animation: rise var(--t-hero) var(--ease-out) 420ms both }`. This runs from first paint with zero JS and no hydration flash; SplitText/BlurText are deleted.

Manifest strip: `<li><strong>{value}</strong><span>{label}</span></li>` ×3, `.manifest { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr }`, cells separated by `border-left: 1px solid var(--line)` (none on the first), value Big Shoulders 700 `--fs-stat` tabular, label `.eyebrow` in `--muted`. **Bug fix (mandatory):** the static branch renders `s.value != null ? <>{s.value}{s.suffix}</> : s.text`, and the animated branch `<><CountUp to={s.value} duration={1.4} delay={0.3} />{s.suffix}</>`; `s.text` is only used when `value` is null. `.manifest strong { min-width: 2ch }` so CountUp does not jitter the layout while counting.

Living background: `LivingBackground` renders `.hero-bg { position: absolute; inset: -24px -40px; z-index: var(--z-bg); pointer-events: none; color: var(--ink); mask-image: radial-gradient(ellipse 75% 85% at 35% 45%, #000 35%, transparent 95%) }` (+ `-webkit-mask-image`), containing always the inline SVG

```
<svg class="silk" aria-hidden="true" viewBox="0 0 1200 600" preserveAspectRatio="none" fill="none" stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke" style="opacity:.18">
  <path d="M0 120 C 300 60, 600 200, 900 140 S 1200 100, 1200 100"/>
  <path d="M0 220 C 250 160, 550 320, 850 240 S 1200 200, 1200 220"/>
  <path d="M0 320 C 350 260, 600 420, 950 340 S 1200 300, 1200 320"/>
  <path d="M0 420 C 300 360, 650 520, 900 440 S 1200 400, 1200 420"/>
  <path d="M0 520 C 250 460, 550 600, 850 520 S 1200 480, 1200 500"/>
</svg>
```

and, when the gate in section 5 passes, `<div className="hero-canvas"><Threads …/></div>` at `position: absolute; inset: 0; opacity: .32`. `.hero-fg { position: relative; z-index: var(--z-fg) }`.

Instrument column: `<div id="quote-instrument" className="instrument"><p className="eyebrow">01 — Rate</p>{!site.live && <p className="sample">…</p>}{hold.on ? <HoldPanel …/> : <Calculator site={site} />}<p className="promise"><UI.shield… /> {content.promise}</p></div>`. There is no `UI.shield`; use `CardIcons.shield` at 16px in `--orange-ink`. `.promise { font: 400 0.85rem/1.45 var(--font-mono); color: var(--muted); display: flex; gap: 8px; margin-top: 16px; letter-spacing: var(--ls-mono) }`. The promise text is rendered only when non-empty.

(d) Fallbacks: reduced motion → words and lede static (the keyframes are inside the media block), CountUp → text, no canvas (SVG stays). Phones (< 720), save-data, no WebGL → SVG only; `ogl` is never requested there because the dynamic import sits behind the gate.

### 02 — Route board (`home/RouteBoard.tsx` server component + `home/RouteRow.tsx` client; also rendered on /services)

(a) Sources: eyebrow "02 — Routes"; h2 `content.routesTitle` via `Accent`; note `content.routesNote`; rows from `routeRows(site, holdOn)`; chips from settings only: `Documents up to {docMaxKg} kg` when `docMaxKg > 0`, `Cargo over {maxKg} kg on request` when `maxKg > 0`.

(b) Layout: `<section className="routes bleed on-navy">` — `background: var(--navy); color: var(--paper-ink); padding: var(--section) 0` (dark mode adds `border-block: 1px solid var(--line)`). Inside `.wrap`: title block (eyebrow in `--navy-accent`, `.rule` in `--paper-ink`, h2, note in `--paper-70` at `--fs-small` mono, the two chips as `.tag`s with `background: var(--navy-2); color: var(--paper-ink)`), then `<ul className="route-list">` — one column on phone, `grid-template-columns: 1fr 1fr; column-gap: 48px` at ≥ 900. Each row: `<li><RouteRow …><span class="route-code">GB</span><span class="route-name">United Kingdom</span><span class="route-days">3–5 days</span><span class="route-price">1 kg from PKR 4,500</span></RouteRow></li>` — a `button` 56px tall, full width, `display: grid; grid-template-columns: 3.5ch 1fr auto auto; gap: 16px; align-items: baseline; border-top: 1px solid var(--navy-line); background: transparent; color: inherit; text-align: left`; code mono 500 0.8rem `--paper-70`; name Barlow 600 1.05rem; days mono 0.8rem `--paper-70`; price mono 0.85rem tabular `--paper-ink`. Cells with `null` are omitted (the grid collapses the column via `auto`). Hover/focus: `background: var(--navy-2)`, code turns `--navy-accent`, transition `--t-fast`. At 375 the price wraps under the name if needed (`grid-template-columns: 3.5ch 1fr auto` and the price on row 2, column 2).

(c) `RouteRow` (`"use client"`): in the body `const raw = useSession("sp-last"); const reduced = useReducedMotion();` and `onClick={() => { setSession("sp-last", JSON.stringify({ ...safeParse(raw), destId })); document.getElementById("quote-instrument")?.scrollIntoView({ behavior: reduced ? "auto" : "smooth", block: "start" }); }}` where `safeParse` is the same try/catch-to-`{}` helper the calculator's `readRemembered` uses (copy it; do not export from Calculator.tsx). The existing `kg` is preserved and only `destId` changes; the calculator already subscribes to that key, so it re-renders with the destination selected and no new state enters it. On /services the click navigates instead: `RouteRow` takes `href?: string`; when given (`/#quote-instrument`), it renders `<a>` and only writes the session key before the navigation proceeds (no `preventDefault`). Rows reveal through `Reveal` with `delay = Math.min(i, 8) * 0.04`, `distance = 24`.

(d) Hold: `price` is `null` for every row and the price column never renders; nothing rate-shaped is serialised to the client (the rows are plain strings computed on the server). Reduced motion: plain rows.

### 03 — How it works (`home/Steps.tsx`, server)

(a) eyebrow "03 — How it works"; h2 `content.stepsTitle` via `Accent`; items `lines(content.steps)` → `parts(ln, 2)` = `[title, text]`.

(b) `<ol className="steps">` — one column on phone, three at ≥ 900 (`gap: 32px`). Each `<li>`: `<span className="numeral" aria-hidden="true">01</span>` (Big Shoulders 200 `--fs-numeral` `--orange-ink`, `line-height: .9`), `<h3>` at `--fs-h3`, `<p>` `--muted`. The numeral is `String(i + 1).padStart(2, "0")`; the list is an `<ol>` so the number is also semantic.

(c) `Reveal` per item with `delay = i * 0.08`. (d) plain list.

### 04 — Services teaser (`home/Teaser.tsx`, server + existing `ServiceCard`)

(a) eyebrow "04 — Services"; h2 `content.servicesTitle` via `Accent`; the first three `lines(content.services)` parsed exactly as `/services` does today (icon | title | text with the `isCardIcon` fallback); link "All services →" to `/services`.

(b) `.cards.three` grid (1 / 2 at ≥ 640 / 3 at ≥ 960), cards as in section 9 (`--surface`, hairline, `--r3`, padding `--card-pad`, icon 28px `--orange-ink`, an orange 2px rule under the icon that grows 24 → 48px on hover, h3 `--fs-h3`, text `--muted`). The link is a `.link` in Barlow 600 under the grid, right-aligned at ≥ 640.

(c) `ServiceCard` (SpotlightCard + AnimatedContent, existing). (d) plain cards.

### 05 — Stamp band (`site/CtaBand.tsx`, INNER-owned, rendered by HOME with props)

`<CtaBand whatsapp={c.whatsapp} title={content.ctaTitle} sub={content.ctaSub} />` — the two strings move out of the component into props (the component keeps a `whatsapp` prop and gains `title`/`sub`). `<section className="cta-band bleed on-navy">`: navy, `padding: var(--s8) 0`; inside `.wrap` a grid `1fr auto` at ≥ 720: left h2 (`--fs-h2`, via `Accent`) + p (`--paper-70`, `--fs-lede`); right the buttons — "Get a quote" `.btn.big` with `background: var(--paper-ink); color: var(--navy)` linking to `/#quote-instrument`, and `<Pull><a className="btn wa big">` "WhatsApp us" (green). A `<span className="mark stamp-mark" aria-hidden>` 96px, `--gap: var(--navy)`, `transform: rotate(-6deg)`, opacity .9, sits at the far right at ≥ 900 (hidden below). On phone the buttons stack full width.

---

## 8. Calculator

`src/components/calculator/Calculator.tsx` keeps its DOM, state, pricing, WhatsApp links, add-ons, session memory and quote logging. CALCULATOR makes exactly these JSX edits and nothing else:

1. `.total` (quick rate): replace `<div key={idle ? "idle" : String(sum)} className={`total${idle ? " idle" : " pop"}`} aria-live="polite">` with `<div className={`total${idle ? " idle" : ""}`} data-state={!destId || !kgv || kgv === "exact" ? "empty" : idle ? "cargo" : "live"} data-sum={idle ? "" : String(sum)} aria-live="polite">`. The `key` goes (the readout must roll between values, and the note no longer remounts — the text node changes, which is enough for the live region); `.pop` and its keyframe are deleted.
2. `.tval`: replace `<span className="tval">{idle ? `${cur} 0` : fmtMoney(sum, cur)}</span>` with `<PriceReadout value={idle ? 0 : sum} currency={cur} idle={idle} />`.
3. Directly after the `PriceReadout`, add `{!idle ? <i className="sweep" key={sum} aria-hidden="true" /> : null}` (the keyed element remounts on every new sum and replays the one-shot sweep).
4. Wrap the quick-rate "Book now" anchor in `<Pull>` (Live chat stays bare).
5. Wrap the detailed "Book on WhatsApp" anchor in `<Pull>` and give it the `UI.wa` glyph: `<UI.wa />Book on WhatsApp`. The whole `.actions` row is additionally wrapped in `<ClickSpark sparkColor="#EA580C" sparkSize={10} sparkRadius={22} sparkCount={10} duration={450}>` like the quick-rate CTAs.
6. In the `.quote` card, after the `<h2>`, add `<span className="stamp" aria-hidden="true">Quote {quote.id} · valid today</span>` (the `dl` row "Quote | id valid today" stays for screen readers and print).

Run `pnpm test` after; nothing in the suite touches this file, but the calculator must be exercised by hand (section 13).

**`src/components/calculator/PriceReadout.tsx`** (CALCULATOR writes it; `"use client"`; props `{ value: number; currency: string; idle: boolean }`):

- `const text = idle ? `${currency} 0` : fmtMoney(value, currency)`.
- `if (!useMounted() || useReducedMotion()) return <span className="tval">{text}</span>;` — exactly what the server paints.
- Live: `<span className="tval"><span className="sr">{text}</span><span className="odo" aria-hidden="true"><span className="cur">{currency}</span>{cols}</span></span>` where `cols` maps `fmtNum(value).split("")` and keys each column by its **distance from the right end** (`key={chars.length - 1 - i}`) so the units column keeps its element and rolls when the length changes. Digit → `<span className="col"><span className="strip" style={{ "--d": Number(ch) } as CSSProperties}>{DIGITS.map(d => <span key={d}>{d}</span>)}</span></span>`; any other char (`,`) → `<span className="sym">{ch}</span>`.
- CSS: `.odo { display: inline-flex; align-items: baseline; font: 700 var(--fs-total)/1 var(--font-display); font-variant-numeric: tabular-nums; letter-spacing: -0.02em }`, `.odo .cur { font: 400 0.95rem/1 var(--font-mono); letter-spacing: var(--ls-mono); margin-right: 10px; color: var(--paper-70); align-self: center }`, `.col { display: inline-block; height: 1em; overflow: hidden; vertical-align: top }`, `.strip { display: flex; flex-direction: column; transform: translateY(calc(var(--d) * -1em)) }`, `.strip span { height: 1em; line-height: 1 }`; in the no-preference block `.strip { transition: transform var(--t-odo) var(--ease-out) }`. The idle text (`PKR 0`) also renders through the odometer when live (one `0` column) so idle → live is a roll, not a swap.

**States of `.total`** (calculator.css):

| state | selector | look |
|---|---|---|
| idle, nothing chosen | `.total.idle[data-state="empty"]` | `background: var(--paper-2); color: var(--muted); border: 1px dashed var(--dash); box-shadow: none`; `.tlabel` mono eyebrow `--muted`; value `--muted`; note `--muted` |
| cargo / over-max / no service | `.total.idle[data-state="cargo"]` | same paper stub; `.tnote { color: var(--orange-ink); font-weight: 600 }` |
| live | `.total:not(.idle)` | `background: var(--navy); color: var(--paper-ink); border: 1px solid transparent; box-shadow: var(--glow-live)`; `.tlabel` mono eyebrow `--paper-70` reading "Total charges" (CSS `text-transform: uppercase`); value paper; a `2px × 48px` `--navy-accent` rule under the value (`.tval::after`); `.tlines` mono 0.85rem with `.sum` over a `1px solid var(--navy-rule)`; `.tnote` `--paper-70` 0.9rem |
| transition | `.total` | `transition: background-color var(--t-print) var(--ease-out), color var(--t-print), border-color var(--t-print), box-shadow var(--t-print)` inside the no-preference block — paper prints to ink while the digits roll |
| sweep | `.total .sweep` | `position: absolute; inset: 0; pointer-events: none; background: linear-gradient(105deg, transparent 40%, rgba(245,240,231,.18) 50%, transparent 60%); transform: translateX(-100%); opacity: 0`; no-preference: `animation: sweep 700ms var(--ease-out) 120ms both` with `@keyframes sweep { from { transform: translateX(-100%); opacity: 1 } to { transform: translateX(100%); opacity: 0 } }`. `.total { position: relative; overflow: hidden; border-radius: 12px; padding: 28px 24px; text-align: left; display: flex; flex-direction: column; gap: 6px }` (20px 16px on phone) |

**Everything else, by existing class (keep every name; add only those marked NEW):**

- `.instrument` (NEW wrapper, HOME renders it): scoping root for the skin. Width per section 7.
- `.modes` (tablist): one hairline track `--paper-2`, radius `--r2`, padding 3px, 48px tall; buttons mono 500 0.78rem uppercase `letter-spacing: var(--ls-tab)` `--muted`; `[aria-selected="true"]` = `--ink` fill, `--bg` text, radius 4; `--t-fast`.
- `.panel` (`.instrument .panel`): `--surface`, hairline, `--r4`, padding `--panel-pad` (24 / 16 phone), `--shadow-instrument`, `margin-bottom: 16px`. `.panel.quick` identical.
- `h2.step`: Big Shoulders 600 `--fs-h3`, `gap: 10px`, svg 20px `--orange-ink`; `.n` (detailed quote) drops the circle: `font: 200 2.25rem/1 var(--font-display); color: var(--orange-ink); width: auto; height: auto; background: none` and the text stays "1/2/3" (render as-is; CSS `.n::before { content: "0" }` gives "01/02/03").
- `.field > span`, `.lab`: `.eyebrow` styling (mono 500 0.72rem uppercase `--orange-ink`), svg 14px `--orange-ink`; `.field` gap 8px, margin-bottom 16px.
- Inputs/selects inside `.instrument`: 52px, `--surface`, hairline, `--r2`, `padding: 0 14px`, Barlow 500 1.05rem; the select chevron stays the existing two-gradient arrow in `--muted`; number inputs and `.dims` inputs are mono 1rem tabular; `.dims .x` `--orange-ink`.
- `.chips`/`.chip`: mono 500 0.9rem, 40px, `--r1`, hairline, `--surface`; `[aria-pressed="true"]` = `--ink` fill, `--bg` text, border `--ink` (orange is reserved for price and booking).
- `.opts legend`: eyebrow. `.opt`: `--surface`, hairline, `--r3`, padding 14px 16px, grid as today; `.opt-name` Barlow 600 1.02rem; `.opt-eta` mono 0.8rem `--muted`; `.opt-price` Big Shoulders 700 1.5rem tabular `--ink` (not orange); `.opt:has(input:checked)` = `box-shadow: inset 3px 0 0 var(--hot-a); background: var(--paper-2); border-color: var(--line)`; `.opt.dim` opacity .55 and `.opt.dim .opt-price` mono 0.85rem `--muted` "—"; `.opt-badge` mono 500 0.7rem pill (ok-soft / blue-soft as today); radios `accent-color: var(--hot-a)` 20px.
- `.addons label`: mono 0.9rem, 40px rows; checkbox 20px `accent-color: var(--hot-a)`.
- `.ctas`: `grid-template-columns: 1fr 1fr; gap: 12px`; `@media (max-width: 419px) { grid-template-columns: 1fr }`. "Live chat" = `.btn.chat` (green, 52px, `UI.chat` glyph white); "Book now" = `.btn.book` 52px orange stamp inside `<Pull>`; disabled = 45% + `pointer-events: none` (existing `aria-disabled` hook). `.ctas .pull, .ctas .pull-in { display: block }`, `.ctas .pull .btn { width: 100% }`.
- `.seg`/`.segbtn` (Packages / Documents): same look as `.modes` (track + tabs; `[aria-checked="true"]` ink fill). `.units`/`.ubtn`: mono 0.8rem pills, `[aria-pressed="true"]` ink border + ink text.
- `.pieces .piece + .piece`: `border-top: 1px dashed var(--dash)`; `.ptitle .pnum` mono eyebrow; `.p-remove` = `.btn.small`.
- `.results`: `--surface`, hairline, `--r4`, `--shadow-instrument`, `overflow: hidden`; `.stripe` 6px tall (was 10); `.svc` rows: name Barlow 600 1.05rem, eta mono 0.85rem `--muted` with `strong` in `--ink`, `.svc-price` Big Shoulders 700 2rem tabular with `.cur` mono 0.8rem `--muted`; `[aria-pressed="true"]` = `box-shadow: inset 3px 0 0 var(--hot-a); background: var(--paper-2)` (replaces the `::before` bar and blue-soft); `.svc-cta` mono 0.8rem `--orange-ink`, "Chosen" in `--ok`; `.svc:disabled .svc-price.dim` mono 0.85rem `--muted`; `.status` mono 0.85rem `--muted`, `.status.warn` warn-soft/warn; `.addons-detail` as `.addons`.
- `.quote`: the manifest document — `position: relative; --surface; hairline; --r4; --shadow-instrument; padding: 24px` (20 phone); `h2` Big Shoulders 600 `--fs-h3` with `padding-right: 140px` at ≥ 480 so the stamp never overlaps; `.tag` ("Document rate") mono 0.7rem pill; `dl` mono 0.9rem, `dt` `--muted` uppercase 0.72rem eyebrow-style, `dd` `--ink`, the total `dd.display` Big Shoulders 700 1.75rem tabular (the inline `style={{ fontSize: "1.6rem" }}` stays; CSS may not remove it); `.stamp` = `position: absolute; top: 20px; right: 20px; transform: rotate(-5deg); border: 1px dashed var(--dash); border-radius: var(--r1); padding: 6px 10px; font: 500 0.7rem/1.2 var(--font-mono); letter-spacing: 0.12em; text-transform: uppercase; color: var(--orange-ink)`; on phone (< 480) it sits `position: static; display: inline-block; margin: 8px 0 0`. No-preference: `.stamp { animation: stamp 320ms var(--ease-stamp) both }` with `@keyframes stamp { from { transform: rotate(-5deg) scale(1.18); opacity: 0 } to { transform: rotate(-5deg) scale(1); opacity: 1 } }`. `.qid` mono 500 tabular `letter-spacing: .04em`. `details.how summary` mono 0.85rem `--ink`; its list mono 0.85rem `--muted`; the contents `.field` as any field; `.actions`: "Book on WhatsApp" `.btn.book.big` (56px; full width on phone via `.quote .actions .pull { flex: 1 1 100% }` below 560), "Share quote"/"Copy quote" `.btn.outline`.
- `.includes`, `.disclaimer`: mono 0.85rem `--muted`; `details.how.notes summary` mono. `.sample`: `--warn-soft` / `--warn`, `--r2`, mono 0.85rem, `margin: 0 0 16px`.
- Doc-rate state: nothing changes visually beyond the existing `.tag` and the status sentence; `h2.step .n` and the `.results` header stay.
- Keyboard: no role/attribute changes anywhere; every control keeps the global focus ring (verify the mode tabs, chips, `.segbtn`, `.ubtn`, `.svc` and `.opt` radios all show it).

Mobile layout at 375: the instrument is full width in 16px gutters; `.modes` 48px; `.panel.quick` padding 16, `--r4`; fields 52px; chips wrap in a row of five (`1 kg … 20 kg`); option rows full width; the readout full width with `--fs-total` = 50px; CTAs two columns from 420px, one below.

---

## 9. Inner pages

Shared header: `src/components/site/PageHead.tsx` (FOUNDATION, server): `<PageHead no="02" name="Services" title="Services" lede={…} />` → `<header className="page-head"><p className="eyebrow">{no} — {name}</p><span className="rule" aria-hidden="true" /><h1><Accent text={title} /></h1>{lede ? <p className="lede">{lede}</p> : null}</header>`; h1 at `--fs-h1`, lede `--fs-lede`, `margin-bottom: var(--s7)`. `<section className="page">` padding `var(--s7) 0 var(--s9)`; `.page h2` gets the eyebrow+rule block too (INNER writes `<p className="eyebrow">…</p><span className="rule" />` before each h2). No background effect on inner pages.

**Services** (`/services`): `PageHead no="02" name="Services" title="Services" lede={content.servicesLede}`. Cards: all `lines(content.services)` as `ServiceCard` in `.cards.three` (1 / 2 at ≥ 640 / 3 at ≥ 960; gap 16 / 24); `.card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r3); padding: var(--card-pad) }`, `.card .ico { width: 28px; height: 28px; color: var(--orange-ink) }`; directly after the icon the page renders `<span className="rule hot" aria-hidden="true" />` (`.rule.hot { background: var(--hot-a); width: 24px; margin: 12px 0 10px; transition: width var(--t-fast) }`, `.card:hover .rule.hot { width: 48px }`); h3 `--fs-h3`; p `--muted`; hover `border-color: var(--ink)`. The home teaser (section 7) renders the same three-element card body. Then `<RouteBoard site={site} holdOn={hold.on} eyebrow="Where we deliver" href="/#quote-instrument" />` (the page fetches `getLiveHold()` alongside `getLiveSite()`; the board's eyebrow is overridable by prop; title/note still come from content). Then `<CtaBand …>` with the content props.

**About** (`/about`): `PageHead no="03" name="About" title={`About ${company.name}`} lede={company.tagline}`. Story: `<div className="story g12">` — at ≥ 900 the left `.story-side` spans columns 1–4 and is `position: sticky; top: calc(var(--head-h) + 24px); align-self: start` holding `<p className="eyebrow">Our story</p><span className="rule"/>` and a Big Shoulders 600 `--fs-h3` "Our story" label; the right `.story-body` spans 5–12 with `lines(content.story)` paragraphs at 1.15rem/1.6 max 62ch; the first paragraph goes through `StoryReveal`, the rest are plain `<p>`. Drop cap: the CSS is `.story-first::first-letter, .drop { font: 800 4.5rem/0.8 var(--font-display); color: var(--orange-ink); float: left; margin: 6px 10px 0 0 }`. `StoryReveal` guarantees it in both branches: the fallback renders `<p className="story-first">{text}</p>` (the `::first-letter` rule applies to a plain paragraph); the animated branch renders `<div className="story-first" aria-hidden="true"><span className="drop">{text[0]}</span><ScrollReveal …>{text.slice(1)}</ScrollReveal></div><p className="sr">{text}</p>` — the split words are inline-blocks, which `::first-letter` cannot reach, so the first letter is set explicitly and the whole visual is hidden from assistive tech while the `.sr` paragraph carries the text once. Both branches show identical text. Below 900 everything stacks. Mission/vision: `.mv` two cards (`1fr 1fr` at ≥ 700) each `<p className="eyebrow">Mission</p><span className="rule"/><p className="mv-text">` at `--fs-h3` weight 500 `--ink` (not muted), `--surface`, hairline, `--r4`, padding 24. Values: `<ol className="values">` — each `li` `grid-template-columns: 4.5rem 1fr`, `border-top: 1px solid var(--line)`, padding 20px 0; numeral Big Shoulders 200 3.5rem `--orange-ink`; name Barlow 600 1.2rem; explanation `--muted`; `Reveal` per item `delay = i * 0.06`. Stamp band.

**Contact** (`/contact`): `PageHead no="04" name="Contact" title="Contact us" lede={content.contactLede}`. Contact sheet: one `.card.contact-sheet` with three rows (`.contact-row`, `border-top: 1px solid var(--line)` from the second; grid `1fr auto` at ≥ 640, stacked below): eyebrow (WhatsApp / Call / Email with the existing `CardIcons`), the number in Big Shoulders 600 `--fs-h3` tabular (`fmtPhone(co.whatsapp)`, `co.phone` with `c.phone2` or `c.hours` as the existing sub-line, `co.email`), the existing one-line note, and the action: "Open WhatsApp" `.btn.wa` (green), "Call now" and "Write to us" `.btn.outline`. "Visit us": `<p className="eyebrow">Visit us</p><span className="rule"/><h2>` then `.two` — office card (address, the existing "Open in Google Maps" **link** unchanged; **no iframe**) and hours card (hours, pickup cities, cutoff line — all from company/content/settings as today). "Write to us": `ContactForm` gets `className="panel contact-form"` and the instrument skin (`.page .contact-form input, select, textarea` 52px, `.field > span` eyebrow, submit `.btn.primary.big`, the "Or WhatsApp us" `.btn.wa`); its token, honeypot, validation and success card are untouched. Stamp band.

**FAQ** (`/faq`): `PageHead no="05" name="FAQ" title="Questions people ask" lede={content.faqLede}`. `<div className="faq">` items as an editorial index: `details { border-top: 1px solid var(--line); background: none; padding: 0 }` (last gets a bottom hairline); `summary { display: grid; grid-template-columns: 3ch 1fr auto; gap: 16px; padding: 18px 0; align-items: baseline }` with `<span className="faq-n">01</span>` mono 500 0.8rem `--orange-ink`, the question Barlow 600 1.1rem, and the `::after` glyph "+"/"–" in mono 1.1rem `--muted` at the right; `details[open] { box-shadow: inset 3px 0 0 var(--hot-a) }` with `padding-left: 16px`; the answer `--muted` max 70ch `padding: 0 0 18px calc(3ch + 16px)`. `Reveal` stagger as today (`Math.min(i, 6) * 0.05`). Stamp band.

**404 / error**: section 6.

---

## 10. Admin

Goal: a product, not a form. Same tokens and type; zero decorative motion (only `--t-fast` hover/focus). Every existing handler, action, autosave, import, publish and TOTP flow is untouched; changes are markup wrappers, class names and CSS.

**Shell** (`AdminShell.tsx`): renders

```
<div class="admin-app">
  <aside class="admin-side">        ← >= 1024 only
    <a class="brand-link" href="/admin"><span class="mark"/> <span class="brand-name">{companyName}</span><span class="eyebrow">Admin</span></a>
    <AdminNav variant="side" badges … />
    <div class="admin-user"><span class="mono">{user.email}</span><span class="tag">{user.role}</span><a class="btn outline small" href="/">View website ↗</a><SignOutButton/></div>
  </aside>
  <header class="admin-top">        ← < 1024 only: 56px bar: mark + "Admin" eyebrow + user chip + Sign out; then <AdminNav variant="top"/> as the existing scrollable row
  <main class="admin-main">{children}</main>
</div>
```

CSS: `.admin-app { min-height: 100dvh }`; at ≥ 1024 `display: grid; grid-template-columns: var(--sidebar-w) minmax(0, 1fr)`; `.admin-side { position: sticky; top: 0; height: 100dvh; background: var(--paper-2); border-right: 1px solid var(--line); padding: 20px 16px; display: flex; flex-direction: column; --gap: var(--paper-2) }` (the mark's gap colour follows the sidebar); `.admin-main { padding: 32px 40px 120px; max-width: 1320px }` (16px 16px 120px on phone). Both `.admin-side` and `.admin-top` are in the DOM; CSS shows one (`display: none` on the other), so there is no client branching.

**AdminNav** (`variant: "side" | "top"`): side items 40px, Barlow 600 0.95rem `--muted`, radius `--r2`; active = `--ink` text + `box-shadow: inset 3px 0 0 var(--hot-a)` + `--surface` background; the count pill `.count` (replaces the borrowed `.opt-badge`) mono 500 0.72rem, `--hot-a` fill, `--book-ink` text, `--r-pill`. Under "Rates", when `pathname === "/admin"`, an indented sub-list of the seven sections (`#sec-rates`, `#sec-import`, `#sec-bulk`, `#sec-test`, `#sec-settings`, `#sec-content`, `#sec-history` — the same ids AdminEditor already uses, so restore's `scrollIntoView` keeps working) as plain anchors, mono 0.78rem. `section.block { scroll-margin-top: 24px }`.

**Topbar** (per page, first child of `.admin`): `<div className="topbar"><div><p className="eyebrow">Rates</p><h1>Rates</h1></div><div className="topbar-right">…pills… <span className="meta mono">Version 12 · published today 18:06 · by name</span></div></div>`; h1 Big Shoulders 700 `--fs-h2`. Pills `.pill` (mono 500 0.72rem uppercase, `--r-pill`, 26px): `live` (ok-soft/ok), `on hold` (warn-soft/warn — replaces `.tag.hold-tag`), `sample rates` (warn), `draft edited` (blue-soft/orange-ink, shown when `changes.count > 0`). Inbox/Shipments/Security pages use the same topbar with their own eyebrow, keeping their existing right-side content ("New shipment" button, the inbox meta line).

**Dashboard strip** (top of `AdminEditor`, replacing `.admin-head`): `page.tsx` passes `newLeads={counts.new}` and `sheetsWaiting={imports.filter(i => i.status === "needs_mapping").length}` as new props. `<div className="dash">` 2-up on phone, 4-up ≥ 900: `.tile { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r4); padding: 20px; box-shadow: inset 3px 0 0 var(--tone, transparent) }` with `.tone-ok { --tone: var(--ok) }`, `.tone-warn { --tone: var(--warn) }`, `.tone-hot { --tone: var(--hot-a) }`; label `.eyebrow`, `.val` Big Shoulders 700 2.5rem tabular, `.note` mono 0.78rem `--muted`. Tiles: **Live version** (`live.version`; note `published today / yesterday / N days ago` from the existing `age`; tone ok, warn when `age >= 7`), **Prices** (`hold.on ? "On hold" : live.live ? "Live" : "Sample"`; note `hold.on ? "customers see no prices" : "customers see version N"`; tone warn / ok / warn), **New leads** (`newLeads`; note "in the inbox", the tile is a `Link` to `/admin/inbox`; tone hot when > 0), **Sheets waiting** (`sheetsWaiting`; note "need a column map"; the tile links to `#sec-import`; tone hot when > 0). The unpublished-changes count lives in the publish pill and the `draft edited` pill, not in a tile.

`HoldBar` and the notices keep their components; `.notice { border-radius: var(--r2); box-shadow: inset 3px 0 0 currentColor; padding: 12px 16px 12px 18px }` in each tone (the `.notice.info` bar is `--orange-ink`).

**Section nav `.subnav`** (AdminEditor's existing anchors; hidden at ≥ 1024 where the sidebar sub-list replaces it): `position: sticky; top: 0; z-index: var(--z-subnav); background: var(--glass); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); display: flex; gap: 0; overflow-x: auto; scrollbar-width: none`; links mono 500 0.75rem uppercase `--muted`, padding 12px 12px, `[aria-current], :hover` = `--ink` + `box-shadow: inset 0 -2px 0 var(--hot-a)`. Inbox/shipments status tabs reuse the same class (they are `<nav className="subnav">` already) and are visible at every width.

**Sections `section.block`**: `background: var(--surface); border: 1px solid var(--line); border-radius: var(--r4); padding: 24px; margin-top: 32px; border-top: 1px solid var(--line)` (the RatesTable's inline `style={{ borderTop: 0, marginTop: 10, paddingTop: 0 }}` is removed so it becomes a card like the others); header = `<p className="eyebrow">01 Rates</p>` (ADMIN adds the numbered eyebrow before each existing h2: 01 Rates, 02 Import from Excel, 03 Bulk adjust, 04 Test a price, 05 Settings, 06 Website pages, 07 History) + h2 Big Shoulders 600 `--fs-h3` + `.desc` `--muted`.

**Tables** (`.admin-app`): `.tablewrap { border-radius: 12px; border: 1px solid var(--line); background: var(--surface); overflow-x: auto }`; `th` mono 500 0.72rem uppercase `--muted` `letter-spacing: .08em`, `th.grp.express { color: var(--orange-ink) }`, `th.grp.normal { color: var(--ok) }`; `tbody tr { background: var(--surface) } tbody tr:nth-child(even) { background: var(--paper-2) }` (`tr.grid-row` keeps `--bg`); `td` 44px rows, `padding: 4px 8px`; numerics mono tabular right-aligned (`td input.num`, `.days`, `.grid-box input`); **sticky first column** `th:first-child, td:first-child { position: sticky; left: 0; background: inherit; z-index: var(--z-sticky) }` with `tr.grid-row td { position: static }`; `tr.changed td:first-child { box-shadow: inset 3px 0 0 var(--hot-a) }` (was amber); `tr.inactive td { opacity: .5 }`. **Quiet inputs**: `.admin-app td input { border-color: transparent; background: transparent; border-radius: var(--r1) }`, `:hover { border-color: var(--line) }`, `:focus { background: var(--surface); border-color: var(--ink) }`, and the shipments table's `.qid` mono 1rem. `table.preview` (import) same rules with `tr.hdr td { background: var(--paper-2); font-weight: 600 }`.

**GridEditor** (`.grid-boxes`): `repeat(auto-fill, minmax(84px, 1fr))` → 13 boxes per row at ≥ 1180 (1–13 / 14–25); `.grid-box > span` mono 0.72rem `--muted`; inputs 38px mono tabular right-aligned with the global focus ring; `.grid-head` shows the service name as a `.tag` in its colour (`express` → blue-soft/orange-ink, `normal` → ok-soft/ok) — ADMIN wraps the existing `<strong>{sv.name}</strong>` in a `.tag` with the service-id class; the quick-fill inputs stay. The "Prices ▾ / Hide prices ▴" button becomes `.btn.small.outline` and the summary hint under it mono 0.75rem.

**Forms** (`SettingsForm`, `ContentForm`, `fields.tsx`): `.admin-app .field > span` = `.eyebrow` (mono uppercase) with hints in `--muted` 0.85rem sentence case; inputs 44px; `textarea.paste` mono (exists), `textarea.long` Barlow; `.grid2 { gap: 16px 24px }`. `ContentForm` gains the new fields (section 11) under the existing headings, in this order: **Home** — Headline (hint: "Wrap one word in *asterisks* to set it in italic, e.g. Send anything *abroad*"), Line under the headline, Route line above the headline (blank = automatic), Promise line under the calculator, Numbers strip; **Routes** — Routes heading, Routes note; **How it works** — Heading, Steps (one per line `Title | text`, `Area`); **Services** — Home services heading, Services page intro, Services list; **Book band** — Heading, Line under the heading; **About page** — unchanged; **Contact page** — Intro line + existing four; **FAQ page** — Intro line + Questions.

**Inbox** (`InboxList`): keep `ul.hist` but each `li` becomes `.lead` — `display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px 24px; padding: 16px 20px; border: 1px solid var(--line); border-radius: var(--r3); background: var(--surface); margin-bottom: 10px`; `.lead.new { box-shadow: inset 3px 0 0 var(--hot-a) }` (ADMIN adds the class from `l.status === "new"`); the first line: `.tag` kind (Quote / Message, mono), name Barlow 600, quote id `.qid`, destination + weight mono `--muted`, created time mono `--muted`; message body as today; right column: the status select 40px, notes input, "Open in WhatsApp" `.btn.wa.small` (green), "Create shipment" `.btn.outline.small`. The status tabs are the `.subnav`.

**Shipments**: list page as the table above (`tr.changed` for exceptions keeps the orange bar); `ShipmentDesk` at ≥ 1024 `.ship { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); gap: 24px; align-items: start }` — details `.panel` left (instrument skin: eyebrow labels, 44px inputs, mono tracking id 1.1rem), status + timeline right; the timeline `ul.hist.timeline` becomes a vertical rail: `li { position: relative; padding-left: 28px } li::before { content: ""; position: absolute; left: 6px; top: 14px; width: 10px; height: 10px; border-radius: 50%; background: var(--dot) } li::after { position: absolute; left: 10px; top: 24px; bottom: -10px; width: 2px; background: var(--line) }` with `--dot` per status class (`.s-delivered { --dot: var(--ok) } .s-exception { --dot: var(--hot-a) } .s-customs, .s-in_transit, .s-out_for_delivery { --dot: var(--warn) } .s-booked, .s-picked_up { --dot: var(--ink) }` — ADMIN adds `className={`s-${e.status}`}` to each event `li`); "Send on WhatsApp" `.btn.wa.small`. `NewShipmentForm` `.panel` with the same skin, max-width 760.

**Security**: `TotpSetup` `.panel`; the QR block in a `.card` centred (image 200px, `--r3`, hairline); the 6-digit input `className="code"` — mono 1.5rem `letter-spacing: .4em; text-align: center; max-width: 220px` (ADMIN adds the class to the two code inputs); "Set up an authenticator app" `.btn.primary`; disable `.btn.danger.outline`. Admins list: `.hist li` rows with the email mono, role `.tag`, "2FA on" `.tag` / "2FA off" `.meta`. Deployment checks: `li.check` with `<span className="check-dot" />` 10px circle `--ok` / `--hot-a` before the label (ADMIN adds `className={c.ok ? "check ok" : "check bad"}`).

**Publish pill** (`.pubbar`): `position: fixed; bottom: 16px; left: 50%; transform: translateX(-50%); width: min(1180px, calc(100% - 32px)); background: var(--navy); color: var(--paper-ink); border-radius: var(--r4); box-shadow: var(--shadow-instrument); padding: 12px 16px; z-index: var(--z-pubbar)`; at ≥ 1024 `left: calc(50% + var(--sidebar-w) / 2); width: min(1180px, calc(100% - var(--sidebar-w) - 64px))`; the count in Big Shoulders 600 1.1rem, the meta in mono 0.78rem `--paper-70`; "Discard" = `.btn.outline` with `border-color: var(--paper-70); color: var(--paper-ink)` (`.pubbar .btn.outline`), "Review and publish" = `.btn.book` (orange, `--book-ink` text). The review box `#reviewbox .notice.info` becomes a `.card` (surface, hairline, `--r4`, padding 24) with the `.diff` list in mono 0.85rem, `.old` struck through in `--muted`, `.new` `--ink` 600, `.flag` `--warn`; "Publish version N" `.btn.book`, "Cancel" `.btn.outline`.

**Login** (`/admin/login` + `LoginForm`): the page renders `<div className="login"><aside className="login-side"><span className="mark" style={{ width: 48, height: 48 }} /><p className="login-name">{name}</p><p className="eyebrow">{tagline}</p></aside><main className="login-main"><div className="login-card">…</div></main></div>`; `.login { min-height: 100dvh; display: grid; grid-template-columns: 1fr }` → `5fr 7fr` at ≥ 900; `.login-side { background: var(--navy); color: var(--paper-ink); padding: 40px; display: flex; flex-direction: column; gap: 16px; --gap: var(--navy) }` (a 96px band with the mark and name on phone), name Big Shoulders 700 `--fs-h2`, eyebrow `--navy-accent`; `.login-card { width: min(400px, 100%); margin: auto; padding: 24px }` holding h1 "Rates admin" (Big Shoulders 700 `--fs-h2`), the lede, then `LoginForm` (`.panel` with `--shadow-instrument`): Google = `.btn.outline.big` (48px), the "or" hint mono, email/password 48px, "Sign in" = `.btn.primary.big`; MFA form identical with the `.code` input and "Continue" `.btn.primary.big`; errors `.notice.err`; "Back to the website" as a `.link`. The page's company name comes from `getLatestVersion()` when available (fall back to "Speedat International Courier" only if the DB has no version — the existing no-version path).

Print: section 4.

---

## 11. New content fields (FOUNDATION)

Every sentence a visitor reads must come from `content`. Add these to `Content` in `src/lib/site/types.ts`, `ContentSchema` in `schema.ts` (`text(n)`), `SEED.content` in `seed.ts` (defaults below, verbatim), and a `Fld`/`Area` in `ContentForm.tsx` (section 10). `migrate.ts` spreads `SEED.content` and `diff.ts` iterates `Object.keys`, so neither needs an edit — but run `pnpm test` (repo.test and diff.test compare content shapes). Old stored versions restore fine: missing keys take the seed default.

| field | schema | default (seed) | rendered where |
|---|---|---|---|
| `heroEyebrow` | `text(160)` | `""` (blank = automatic: `{origin} → {first five active destinations} · and N more`) | mono eyebrow above the home h1 |
| `promise` | `text(240)` | `The price you see here is the price at pickup, unless the parcel weighs or measures differently.` | mono line with the shield icon under the instrument |
| `routesTitle` | `text(120)` | `Where we fly from Lahore` | route board h2 (home + /services) |
| `routesNote` | `text(300)` | `Fastest service in working days. 1 kg parcel, shipping only; pickup charges are added in the quote.` | route board note |
| `stepsTitle` | `text(120)` | `Three steps, no surprises` | "How it works" h2 |
| `steps` | `text(2000)`, multiline `Title \| text` | three lines: `Price it \| Choose the destination and weight. The price on this page is the price at pickup unless the parcel weighs or measures differently.` / `Book on WhatsApp \| Tap Book, send the ready-made message, and we confirm the pickup time and receiver details in the chat.` / `Track to the door \| We share the tracking number and updates on WhatsApp until it is delivered.` | the three steps |
| `servicesTitle` | `text(120)` | `What we carry` | home services teaser h2 |
| `servicesLede` | `text(400)` | `Every service is door to door: we collect from you and deliver to the receiver. Prices are on the quote page.` | /services lede (moved out of the page) |
| `ctaTitle` | `text(120)` | `Ready to send something?` | stamp band h2 (moved out of CtaBand.tsx) |
| `ctaSub` | `text(300)` | `Get an instant price and book on WhatsApp in two minutes.` | stamp band line |
| `contactLede` | `text(400)` | `WhatsApp is the fastest way to reach us. We reply during working hours and confirm every pickup in the chat.` | /contact lede (moved out of the page) |
| `faqLede` | `text(300)` | `If yours is not here, ask us on WhatsApp.` | /faq lede (moved out of the page) |

Convention, not a field: `heroTitle` and every h1/h2 field accept one `*word*` for the italic serif accent. The seed `heroTitle` becomes `Send anything *abroad* from Lahore. Priced in seconds.` (verified: no test pins it; the live database keeps its own headline until the owner edits it, so on the dev DB the reviewer sets the asterisks through Admin → Website pages and publishes). The ContentForm hint documents the rule and that an unbalanced asterisk prints literally. Every default above is paraphrased from existing seed values or FAQ answers — nothing new is claimed, and no number, testimonial, logo or rating is introduced anywhere.

---

## 12. File ownership map

Two phases. **Phase 0 — FOUNDATION** lands first and is verified green (`pnpm typecheck && pnpm lint && pnpm test`) before anyone else edits; it is done by one engineer (recommended: the INNER engineer, whose Phase 1 scope is smallest). During Phase 1 the FOUNDATION files are frozen; a needed change is a request to that owner, not an edit. **Phase 1** — four engineers in parallel on disjoint files. Nobody edits a file outside their list; if a check fails in a file you do not own, report it in your hand-off note instead of fixing it.

**FOUNDATION (Phase 0)**
- `src/app/layout.tsx` (fonts, themeColor), `src/app/globals.css` (imports + `@theme`), `src/styles/tokens.css`, `src/styles/print.css`, the `/* ===== SHELL ===== */` block of `src/styles/site.css` (and the three banners themselves).
- `src/app/(site)/layout.tsx` (header, footer, ghost, Pull on the header button; JSON-LD and nonce untouched).
- `src/components/site/{Nav,Tagline,ShellWrap,Reveal,Accent,PageHead}.tsx`, `src/components/site/fx/{LivingBackground,Pull,StoryReveal,Decrypt}.tsx`, `src/components/Icons.tsx` (adds `UI.wa`).
- `src/components/bits/*` (vendor Threads/Magnet/ScrollReveal with the listed edits; ClickSpark idle fix; delete SplitText, BlurText, ShinyText, DotGrid, StarBorder), `src/components/site/CtaStar.tsx` (delete), `package.json`/`pnpm-lock.yaml` (`pnpm add ogl`).
- `src/lib/client/motion.ts` (new hooks), `src/lib/site/accent.ts` + `accent.test.ts`, content-field layers: `src/lib/site/{types,schema,seed}.ts` and the new fields in `src/components/admin/ContentForm.tsx` (the only admin file FOUNDATION touches; ADMIN restyles it afterwards without moving the fields).
- Skeletons so Phase 1 imports compile from minute one: `src/components/site/home/{RouteBoard,RouteRow,Steps,Teaser,HeroTitle}.tsx` and `home/{eyebrow,routes}.ts` with the final prop/function signatures from section 7 rendering plain static markup; `CtaBand.tsx` with the new `title`/`sub` props wired to content by the pages. HOME and INNER then own those files.

**HOME (Phase 1)**
- `src/app/(site)/page.tsx`, `src/components/site/Hero.tsx`, `src/components/site/home/*` (incl. `routes.test.ts`), `src/components/site/HoldPanel.tsx`, the `/* ===== HOME ===== */` block of `src/styles/site.css`.

**CALCULATOR (Phase 1)**
- `src/components/calculator/*` (Calculator.tsx edits 1–6, the new `PriceReadout.tsx`, Toast.tsx untouched), `src/styles/calculator.css`.

**INNER (Phase 1)**
- `src/app/(site)/{services,about,contact,faq}/page.tsx`, `src/app/(site)/{not-found,error}.tsx`, `src/components/site/{ServiceCard,CtaBand,ContactForm}.tsx`, the `/* ===== PAGES ===== */` block of `src/styles/site.css`.

**ADMIN (Phase 1)**
- `src/app/admin/layout.tsx`, `src/app/admin/page.tsx`, `src/app/admin/{inbox,shipments,security,login}/**/page.tsx` (never `actions.ts` / `import-actions.ts`), `src/components/admin/*` except the field additions FOUNDATION made in `ContentForm.tsx` (ADMIN may restyle around them), `src/styles/admin.css`.

**Frozen for everyone**: `src/lib/pricing/**`, `src/lib/site/{repo,live,hold,hold-shared,diff,migrate,text}.ts`, `src/lib/{auth,db,import,leads,shipments,notify,form-token}*`, `src/app/api/**`, `src/app/admin/actions.ts`, `src/app/(site)/contact/{actions,state}.ts`, `src/proxy.ts`, `tests/**`, `drizzle/**`, `.env.local`, `next.config.ts`, `eslint.config.mjs`.

Coordination rules: class names in section 8 are the contract between CALCULATOR and HOME (`.instrument` wrapper) — neither invents extra shared names; RouteBoard's props are the contract between HOME and INNER; `PageHead`, `Accent`, `Pull`, `Reveal`, `.btn.*`, `.eyebrow`, `.rule`, `.tag`, `.notice`, `.panel` are the contract between FOUNDATION and everyone. When a needed shared style is missing, add it inside your own block scoped to your own prefix rather than editing tokens.css.

---

## 13. Definition of done and QA checklist

A phase is done only when all of these hold on the running dev server (http://localhost:3020, never restarted by us):

**Checks**
- `pnpm typecheck`, `pnpm lint`, `pnpm test` all green (75 tests plus the new `accent.test.ts` and `routes.test.ts`); no `any`, no new `eslint-disable`.
- `pnpm build` succeeds; the route table shows `/` grew by ≤ 60 kB gzipped versus the pre-change build (record both numbers and the Big Shoulders woff2 size in the hand-off). If `/` grew more, the Threads → Waves swap in section 5 applies.
- `curl -s -o /dev/null -w "%{http_code}" http://localhost:3020/{,services,about,contact,faq,admin}` all 200 (`/admin/login` 307 while the dev bypass is on is expected).
- The SSR HTML of `/` contains the h1 text, the manifest values without the `+ days+ days` duplication, the `.silk` SVG, the readout text `PKR 0` (or the currency in the DB), and no `<canvas>`.

**Functionality (by hand, every one)**
- Quick rate: pick a country and 2 kg → the readout prints paper → navy and rolls to the price; the two service options price; Best price / Fastest badges; add-on toggle changes the total and shows `.tlines`; "Book now" opens `wa.me` with the message; "More than N kg" and "Exact weight…" behave as before; the note in the readout reads correctly in every state.
- Detailed quote: pieces, units switch, documents, ship date, service choose → quote card with stamp, "How this price is calculated", contents field, Book on WhatsApp (message text unchanged), Copy quote toast, Share quote where supported. `/api/quotes` still receives the log on Book (check the inbox afterwards).
- Route board: clicking a row selects that destination in the calculator and scrolls to `#quote-instrument`; on `/services` it navigates to `/#quote-instrument` with the destination preselected. Under hold (toggle in the admin) the price column is absent on both pages and the HoldPanel shows.
- Hold panel form → WhatsApp link with destination/weight/contents. Contact form: validation errors, success card, the WhatsApp fallback. Sample notice appears when `live` is false.
- Admin: autosave, discard, restore, review + publish (including go-live and resume checkboxes), hold on/off, rates table edits with undo and the orange changed-bar, GridEditor boxes and quick fill, import preview, bulk adjust, test a price, settings and website-pages forms (new fields save and publish and appear on the site), inbox status/notes/WhatsApp/create shipment, shipments new/detail/status/timeline/WhatsApp update, security TOTP begin/confirm/disable UI, sign out, login page renders both forms (MFA branch reachable by design only).

**Viewports** (Chrome device toolbar or the browser pane): 375 × 812, 768 × 1024, 1366 × 768, plus 1366 × 700 for the sticky-hero cap.
- `document.documentElement.scrollWidth === window.innerWidth` on every page at 375 (no horizontal scroll; the full-bleed navy sections and the `.nav` row are the usual culprits).
- 375: brand does not wrap to three lines; h1 ≤ 4 lines; manifest strip three cells; jump link visible; instrument full width; CTAs stack below 420; route rows readable; stamp under the quote h2, not overlapping.
- 1366: hero sticky beside the instrument, no reflow of the instrument while scrolling; route board two columns; admin sidebar + pill publish bar centred on the main column; rates table first column stays put while the kg boxes scroll.

**Motion & accessibility**
- With `prefers-reduced-motion: reduce` emulated: no canvas, static words, static CountUp numbers, static readout (plain text), no stamp/sweep, no Magnet, no ScrollReveal — every page fully readable, nothing at opacity 0.
- With the DevTools "Network → Save-Data" or a `(min-width: 720px)` false viewport: `ogl` chunk never requested (Network tab filter `ogl`/`Threads`).
- Tab through the header, nav, calculator (tabs, selects, chips, radios, add-ons, buttons), route rows, FAQ summaries, contact form, admin nav, tables and the publish pill: a visible 3px orange focus ring on each, in order, nothing trapped, Enter/Space activate the custom buttons.
- Screen reader spot-check (NVDA or VoiceOver): the price change is announced once per change; the odometer is not read digit by digit; the route rows read "code name days price"; the FAQ index numbers do not replace the question.
- Contrast (DevTools "CSS Overview" or a picker): every text pair from section 2 ≥ 4.5:1 (≥ 3:1 for `--hot-a` display numerals ≥ 24px/700); check both schemes.
- Dark scheme: paper → navy page, navy sections keep a hairline, orange buttons keep `--book-ink`, Threads (if shown) flips to the paper colour.

**Performance-minded**
- Lighthouse (mobile, on `/`): LCP element is text (h1, lede or the instrument), CLS < 0.05, no "avoid non-composited animations" over the readout, no third-party requests, fonts self-hosted (`font-src 'self'` never violated — check the console for CSP reports on every page including `/admin`).
- Console clean of hydration warnings on `/`, `/services`, `/about`, `/contact`, `/faq`, `/admin`, `/admin/inbox`, `/admin/shipments`, `/admin/security`, `/admin/login`.
- Threads pauses off-screen (scroll the hero away: the rAF stops in the Performance panel) and the ClickSpark canvas is idle when no sparks are live.

**Content honesty gate**: grep the diff for `testimonial`, `review`, `rating`, `★`, `trusted by`, `clients`, `award`, `certif` — none may appear; every number on the public site traces to destinations, `parseDaysRange`, `originCities`, `settings.docMaxKg/maxKg`, `content.stats`, `live.version` or lead/shipment counts.

Hand-off note per engineer (in the PR description or `docs/handoff-<role>.md`): what changed, the three check results, the build-table numbers, anything that could not be verified locally, and any failure in a file you do not own.
