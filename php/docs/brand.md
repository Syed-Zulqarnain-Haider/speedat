# Brand: the logo and the yellow header

Owner's decisions, 2026-09-28: use the company's own logo in the header, and
make the header **yellow, like DHL's**. This file is the spec the header change
is built to, so the next person does not have to re-derive the contrast maths.

---

## The logo

The owner supplied `speedat-logo.webp`: 2000×2000, the artwork sitting in a band
from y≈790 to y≈1140 on white. Cropped to its ink and exported with the white
knocked out, so it can sit on a coloured bar with no box around it:

| File | Size | Use |
|---|---|---|
| `public/assets/img/brand/speedat-logo.png` | 660×133 | the header mark |
| `public/assets/img/brand/speedat-logo@2x.png` | 1320×267 | retina (`srcset` 2x) |
| `public/assets/img/brand/speedat-logo.webp` | 2000×2000 | the original, kept untouched |

Rules:

- It is a **raster** logo with fine serif text ("speed against time"). Render it
  at a height where that line stays legible — 36–44 px tall in the header — and
  never stretch it; the aspect ratio is 4.96 : 1.
- Always ship the 2× version in `srcset`; on a phone screen the 1× is visibly
  soft.
- `alt` is the company name, not "logo" — it replaces the wordmark, so a screen
  reader must read *Speedat International Courier*.
- Give it explicit `width` and `height` so the header cannot jump while it loads.
- The tagline is **inside the artwork**. Do not also print "Speed Against Time"
  beside it, or the page says it twice.
- Keep it out of the print stylesheet's way: on paper the header prints small,
  and a 660 px PNG at 100 % wastes half a page.

---

## The yellow header

| Token | Value | What it is |
|---|---|---|
| `--brand-yellow` | `#FFCC00` | the bar |
| `--brand-yellow-dark` | `#E6B800` | the hairline under it, and the pressed state |
| `--ink` | `#0A1A33` | every word on the bar |

**Contrast, measured, not assumed:**

- navy `#0A1A33` on yellow `#FFCC00` → **10.6 : 1** — passes AA and AAA for body
  and for large text. This is why the nav, the phone number and the brand text
  are ink, never white.
- white on `#FFCC00` → **1.2 : 1** — fails everything. No white text on the bar,
  including inside a "ghost" button.
- The green WhatsApp button keeps its own white-on-green (`--wa`), which is
  contrast-safe **inside the button**; it does not need to relate to the yellow.
- The Call button becomes ink text on white with an ink border, so it reads as
  the quieter of the two actions on a yellow ground.

**Layout rules for the bar:**

- Full-bleed yellow, content aligned to the page column (the site already does
  this with `padding-inline: calc(50vw - 50%)`).
- A 1 px `--brand-yellow-dark` line at the bottom, so the bar has an edge on a
  white page without a shadow.
- Height: unchanged on the phone (≤ 64 px including the nav row) — the yellow
  must not buy itself more of the first screen, which is where the price lives.
- Dark mode: the bar stays yellow (it is the brand), the ink stays ink. Only the
  page behind it inverts. Check the logo's dark serif line against the yellow —
  it is the same in both themes, because the bar does not change.
- The theme toggle and any icon on the bar are ink, not "muted": muted grey on
  yellow drops to about 3 : 1 and fails.

**What must not happen** (each of these was a real regression risk in the
review): a yellow bar with the old stripe mark still on it; the logo scaled so
the tagline turns to mush; white nav text left over from the navy design; the
phone header growing a third row; or the print stylesheet inheriting a solid
yellow block.
