# Engine parity — how three engines stay one engine

There are three copies of the Speedat pricing rules:

| Engine | Where | Who runs it |
|--------|-------|-------------|
| TypeScript | `/src/lib/pricing/*.ts` (+ `/src/lib/site/diff.ts`) | the Next.js site that is live today |
| PHP | `php/app/Domain/Pricing/*` (+ `php/app/Domain/SiteDoc/Validate.php`) | the server, on every quote it stores |
| JavaScript | `php/public/assets/js/pricing.js` | the customer's browser, as they type |

They must give the same answer to the rupee and to the character. The customer
reads the browser's number, the server stores its own, and the owner's inbox
line quotes a third — if any two disagree, somebody is quoted one price and
charged another.

What keeps them together is `php/tests/vectors/pricing.json`: **861 answers
taken from the TypeScript engine itself**, not from a description of it. The
PHP suite and the node runner each put every vector through their own engine.

> **A failing vector is a bug in the port. It is never a reason to change the
> vector.** The TypeScript is the specification until it is deleted — except
> where this file records a deliberate divergence, and there are now nine.

Beside it sits `php/tests/vectors/owner-decisions.json`: **51 hand-written
answers** for decisions taken after the TypeScript, which it therefore cannot
generate. Both runners read it with the same handlers; "Owner-decision vectors"
below says what is in it and why it is a separate file.

Two of the nine are product decisions the owner made after the TypeScript was
written, and both are DERIVED into the vector file rather than hand-edited:

- **the document rate**, removed on **2026-09-27**, and
- **skippable weight prices**, decided on **2026-09-28**: a blank grid box is
  no longer charged at the next priced kilogram.

The PHP and the JavaScript have neither; the TypeScript still has both. "The
document rate" and "Skippable weight prices" below say exactly what changed,
how, and how to reproduce it.

---

## Running the checks

```bash
cd php
php bin/test.php                 # PHP suites, then the browser engine via node
php bin/test.php --filter=PricingVectors
node tests/js/run-vectors.mjs    # the browser half on its own
```

`bin/test.php` shells out to `node` for the browser half. node is a
**development dependency only** — nothing on the Hostinger host needs it — but
a missing node is reported as a FAILURE, not a pass. `--skip-js` accepts an
unchecked browser engine and says so in the summary line.

Expected today: `17 passed, 0 failed, 940 assertions` for
`PricingVectorsTest`, and `pricing.js: 912 of 912 vectors passed` (861
generated + 51 owner-decision).

---

## Regenerating the vectors

Do this whenever the TypeScript engine changes, and then make the PHP and the
JavaScript agree with the new file. There are **four** steps now, and they are
in order: skipping the second puts the document rate back, skipping the
third puts the next-priced-kilogram fallback back, and skipping the fourth puts
the em dashes back.

```bash
cd /d/speedat
TZ=Asia/Karachi node_modules/.bin/tsx php/tools/make-vectors.ts             # 940 cases
cd php
php tests/vectors/strip-document-rate.php tests/vectors/pricing.json       # 861 cases
php tests/vectors/apply-skippable-weights.php tests/vectors/pricing.json   # 861 cases, 6 answers nulled, 5 reworded
php tests/vectors/apply-plain-dashes.php tests/vectors/pricing.json        # 6 expected strings: "—" -> "-"
php bin/test.php
```

The third, `apply-plain-dashes.php` (owner, 2026-10-11: no em dash in
customer or office text), rewrites " — " to " - " in EXPECTED strings only -
never an input, never a number.

`apply-skippable-weights.php` also carries two wording rules (2026-10-25),
which rewrite EXPECTED strings only: **W1** turns the `warnSite` gap warning's
"those parcels are charged at the next heavier priced kg" into "customers at
those weights get no price on the site and are sent to WhatsApp", and **W2**
turns the `quoteText` line "Shipping: " into "Courier: ". Without them a
regeneration brings back text neither engine writes any more.

`tests/vectors/owner-decisions.json` is not touched by any of this: it is not
generated, so regenerating cannot lose it.

The first two derivation scripts only ever REMOVE — one drops cases and keys, the other
turns six answers into `null` — and neither computes a price, so neither can
move one. Both are idempotent: running either twice changes nothing.

The generator imports the real modules through the `@/` path alias, so it must
be run from the repository root where `tsconfig.json` lives. It prints a count
per group and refuses to finish if the process is not in Asia/Karachi.

**Why the time zone is pinned.** Almost everything here is time-free: delivery
estimates are pure calendar arithmetic and every case supplies its own `now`.
Two functions are not — `fmtDate` and `fmtDateTime` turn a stored timestamp
into a wall-clock date, and that depends on the zone. Asia/Karachi is the
application's own zone (`app.timezone`), the PHP suite pins it in `setUp()`,
and `tests/js/run-vectors.mjs` sets `process.env.TZ` before it touches a
`Date`. Generate in another zone and roughly a dozen vectors will bake in the
wrong hour.

When the Next.js app is finally deleted, this file's first table loses a row and
`php/tools/make-vectors.ts`, `php/tests/vectors/strip-document-rate.php` and
`php/tests/vectors/apply-skippable-weights.php` and
`php/tests/vectors/apply-plain-dashes.php` go with it. Until then those
four scripts are the only things that may write `tests/vectors/pricing.json` —
never hand-edit it.

---

## The document rate

**The owner removed the document rate from the product on 2026-09-27.** There
is no flat document price, no document weight limit, no Docs column and no
Documents option anywhere. Everything is priced by weight; somebody sending
papers is quoted like any other parcel and asks on WhatsApp if it is unusual.

What that deleted, engine side:

| TypeScript | PHP and `pricing.js` |
|---|---|
| `Settings.docMaxKg` | gone; `SiteDoc\Migrate` drops a stored key |
| `Rate.doc` | gone; `SiteDoc\Migrate` drops a stored key |
| `ShipmentType` (`"pkg"` / `"doc"`) | gone; there is one kind of shipment |
| `priceService(settings, dest, id, billableG, type, chargeG?)` | `priceService(settings, dest, id, billableG)` |
| `ServicePrice.doc`, `ServicePrice.docRate` | gone |
| `PriceResult.type`, `Quote.type`, `Quote.docRate` | gone |
| five `validateSite` / `warnSite` document messages | gone |

**No valid weight price moved.** The document branch was the only reader of the
shipment type and the only reader of the chargeable-versus-billed comparison in
`priceService()`; the next-priced-kilogram lookup, the volumetric weight, the
add-ons, the tax, the rounding and the cargo threshold are untouched. The 861
surviving vectors are the proof: every one of them is still the TypeScript
engine's own answer, unaltered, and all 861 pass in both ports.

### Why the vectors were filtered and not regenerated

`php/tools/make-vectors.ts` takes every expected answer by **calling** the
TypeScript engine in `/d/speedat/src`. That engine cannot express a
document-free document — `Settings` requires `docMaxKg`, `Rate` carries `doc`,
`priceService` takes a shipment type, `validateSite` emits document errors — and
`/d/speedat/src` is reference-only, not ours to edit. Regenerating alone
therefore reproduces the document cases exactly as they were.

So the checked-in file is **derived**: generate, then run
`php/tests/vectors/strip-document-rate.php`, which only ever REMOVES. It never
computes a price, so it cannot move one.

### The rules it applies

| | Rule |
|---|---|
| R1 | **sites**: drop `settings.docMaxKg` and every `rates.*.doc` from the site fixtures |
| R2 | **priceAll**: drop a case whose input asserts type `"doc"`; from the rest drop `input.type`, `expected.type`, and `doc` / `docRate` from each expected price |
| R3 | **priceService**: drop a case whose input asserts type `"doc"`; from the rest drop `input.type` and `input.chargeG` (neither is a parameter any more) and `doc` / `docRate` from expected |
| R4 | **quoteText**: drop a case whose quote asserts type `"doc"`; from the rest drop `quote.type` and `quote.docRate` |
| R5 | **validateSite / warnSite**: remove the document messages the engines no longer produce, matched in full; drop a case whose site fixture R7 removed |
| R6 | **dedupe**: within a group, keep the first of any `{input, expected}` that repeats |
| R7 | **fixtures**: drop any site fixture no surviving case names |

R5 matches each message in full rather than on the word "document", so a future
message that merely mentions documents cannot be swallowed by the filter. The
five are `Document weight limit must be greater than 0.`; `…: document price
must be greater than 0 or left blank.`; `…: has a document price but no
kilogram prices…`; `…: has a document price but no package prices…`; and `…:
the document price (…) is higher than the lightest package price (…)`.

### Exactly what came out — 940 → 861

| Group | Before | After | Removed |
|---|---|---|---|
| `priceAll` | 222 | 176 | 28 typed `"doc"`, plus 18 collapsed by R6 |
| `priceService` | 75 | 44 | 31 typed `"doc"` |
| `quoteText` | 10 | 8 | 2 (`uk document / no add-ons`, `uk document / pickup`) |
| every other group | 633 | 633 | none |
| **total** | **940** | **861** | **79** |

The 18 R6 collapses are not a loss of coverage. The generator adds each of nine
fractional weights three times per site — untyped, `"doc"` and `"pkg"` — and
once `type` is not a parameter the untyped case and the `"pkg"` case are the
same call with the same expected answer. Two sites × nine weights = 18
duplicates, and a duplicate proves nothing twice.

Also removed: `docMaxKg` from 25 site fixtures, a `doc` price from 478 rate
objects, 3 validation messages, and the fixture `seedNoDocPrice` — a
destination whose Express had no document price, which no surviving case names.

### It is reproducible

Re-run on 2026-09-28 from a clean generation — `tsx php/tools/make-vectors.ts`
then `php tests/vectors/strip-document-rate.php` — and the result was **byte
for byte identical** to the file in the tree. Every rule is mechanical and
order-independent, and the script rewrites the JSON with the generator's own
one-space indentation, so a diff of the vector file shows content and not a
reformat.

### The rule that went with it

The retired rule read: *the document rate is judged on the CHARGEABLE grams,
not the billed grams* — `priceService()` took both `$billableG` and `$chargeG`
and tested `min(chargeG, billableG) <= docLimitG`, because a 0.3 kg letter in
grid mode is billed as a whole kilogram and the billed kilogram alone would
lose the flat rate. It is recorded here only so nobody reintroduces it: there
is no flat rate for it to protect, `priceService()` takes the billed grams and
nothing else, and the chargeable grams are now an input to the weight maths
alone.

The hazard that came with it is **not** retired, and `QuoteApiController` still
honours it: the Next.js route trusted the client for `billableG` and `chargeG`
as two independent numbers. The PHP route recomputes both from the submitted
pieces with `Engine::computeWeights()` and keeps the browser's claim only as a
note beside the real number. Do not copy the Next.js shape.

---

## Skippable weight prices

**Owner decision, 2026-09-28:** *"if I don't want to add a price for some weight
it should say rate not available, contact on WhatsApp."*

**A blank grid box is not a price.** Until this change `gridPrice()` walked
upward from `ceil(kg)` and took the first box holding a number above zero, so an
owner who left 3 kg empty was quietly charging the **5 kg price for a 3 kg
parcel** — a number they had never typed, printed as if they had. There was also
no way to stop offering a weight: leaving the box empty offered it anyway, at
somebody else's price.

Now the box at that kilogram is read, and nothing else:

| | before | after |
|---|---|---|
| 3 kg, box blank, 5 kg priced | the 5 kg price, labelled 5 kg | **no price** — the service is not offered at 3 kg |
| 3 kg, box priced | its own price | unchanged |
| 1.2 kg, 2 kg priced | the 2 kg price | unchanged — whole-kilogram billing is not the fallback |
| box holding `0` | skipped, walked past it | **no price** — zero was never a price |
| above the cargo threshold | no price | unchanged |
| slab mode | every weight priced from `first` + `addl` | unchanged — there are no boxes to leave blank |

**Whole-kilogram billing is NOT what went.** Grid mode bills 1.2 kg as 2 kg, and
it always did; that rounding happens *before* a box is read. What went is the
walk that continued past a blank box. `tests/Pricing/SkippableWeightsTest.php`
pins both halves of that sentence.

### What the site does instead

- **Some services have the weight, others do not** — show the ones that do and
  say nothing about the others. No dash, no "PKR 0", no greyed card. In the
  detailed quote the unpriced services get no card at all.
- **No service has the weight** — the readout says the rate is not available for
  that weight and offers the green WhatsApp button, carrying the country and the
  weight. The shape is the cargo path's, deliberately: it is the same
  conversation with a different reason. `calculator.js`'s `noRateText()` is the
  message builder and is shaped like `pricing.js`'s `cargoText()` beside it. It
  is not in `pricing.js`, because nothing on the server writes it.
- **The rates board** is the 1 kg parcel per service. A service whose 1 kg box
  is blank no longer borrows the 2 kg price for that column, and **its transit
  days go with the price** (corrected 2026-09-29 — the first port of this rule
  left them behind). A cell reading "3–5 days" with no figure is not "saying
  nothing about the others": it promises a delivery time for a service the
  calculator, on the same page, refuses to quote at that same kilogram. It was
  live rather than hypothetical — Germany's Express Saver carries days and no
  rate card at all, so its column advertised "3–5 days" at a price that does
  not exist, beside a readout that said there was none.

  **Under hold the days stay**, and that is why `View\Board::rows()` tests "the
  engine was asked and refused" rather than "the price is null". Under hold the
  engine is never asked; every price on the board is absent for that one
  reason, the caption says so, and the timetable is the whole of what a held
  board shows. `tests/View/RatesBoardTest.php` pins both halves, rendered.

  No engine half changed for this and no vector moved: `gridPrice()` already
  answered `null`, and only the view model was reading past it.

### What it did to the vectors — 6 answers, 0 prices moved

`php/tests/vectors/apply-skippable-weights.php` nulls an answer when the
kilogram the generator recorded is not the kilogram that was asked for, which is
exactly the signature of a walk upward. It computes nothing.

| Group | Case | Asked | The TypeScript answered | Now |
|---|---|---|---|---|
| `gridPrice` | gap falls upward | 3 kg | 5 kg / 500 | `null` |
| `gridPrice` | no threshold | 3 kg | 5 kg / 500 | `null` |
| `gridPrice` | holes at 3 | 3 kg | 5 kg / 9,000 | `null` |
| `gridPrice` | holes at 6 | 6 kg | 24 kg / 20,000 | `null` |
| `gridPrice` | zero priced box is skipped | 1 kg | 2 kg / 700 | `null` |
| `priceAll` | gridWithGaps: gb `[{"kg":3}]` | 3 kg | express 9,000 (`gridKg: 5`) | `null` for express |

`priceService` lost none: its 14 grid cases all ask for a kilogram the fixture
prices. **Every other answer in the file is untouched** — the remaining 855
grid and slab answers are still the TypeScript engine's own numbers, unaltered.

### It is reproducible

Re-run on 2026-09-28 from a clean generation: `tsx php/tools/make-vectors.ts`
then `strip-document-rate.php` reproduced the previously checked-in file **byte
for byte**, and `apply-skippable-weights.php` then produced the file in the tree.
A second run of the new script changes nothing.

### The admin is told the same rule (2026-10-25)

`Validate::warnings()` and `pricing.js` `warnSite()` used to tell the owner a
gap was "charged at the next heavier priced kg" - the rule that went - so an
owner could publish a gap on purpose believing it was priced, and customers at
that weight were sent to WhatsApp. The warning now says exactly that: "... (2
kg) - customers at those weights get no price on the site and are sent to
WhatsApp." The `gridWithGaps` vector was reworded by rule W1 above, never
hand-edited. The rates editor's own copy is `app/View/Admin/`'s.

---

## The rules that are load-bearing

Each of these is a place where an obvious-looking simplification changes the
price. All are pinned by vectors.

### 1. Integer grams, everywhere

`kgToG()` at the edges, integers in the middle, `gToKg()` only for display.
0.1 + 0.2 is not 0.3 in JavaScript and it is not 0.3 in PHP; a half-kilogram
step in floating point drifts. Every weight in `Weights` and every argument to
`priceService()` is an `int`.

### 2. base → tax → round, in that order

`total = roundTo(base + tax, roundTo)`. Rounding the base first, or rounding
the tax separately, moves the answer by the rounding step. `Money::roundTo()`
implements `Math.round(n / step) * step` with **JavaScript's** rounding rule —
half rounds towards +∞, so `Math.round(-2.5) === -2` while PHP's own `round()`
would give -3. Prices are never negative, but the rule is written down rather
than left to coincidence.

### 3. A blank grid box is NOT A PRICE (owner, 2026-09-28)

`Engine::gridPrice()` reads the box at `ceil(kg)` and nothing else. Blank, zero
or above the cargo threshold all answer `null`, and that service is simply not
offered at that weight — the site says the rate is not available and sends the
customer to WhatsApp. It used to walk upward to the next priced box, which
charged a number the owner had never typed; "Skippable weight prices" above is
the whole decision.

The rounding that survives is a different rule: grid mode bills whole kilograms,
so 1.2 kg reads the **2 kg** box. That happens before a box is read.

### 4. Grid mode bills whole kilograms, whatever `stepKg` says

`Engine::effectiveSteps()` returns `1 / 1` in grid mode. Anything that explains
a rounding to the customer must read it: a 0.5 kg envelope billed as 1 kg was
never "rounded up to the next 0.5 kg".

### 5. Add-on amounts come from the document, never from the browser

A booking sends add-on **labels**. `QuoteText::resolveAddons()` looks each one
up in the published settings and takes the amount from there, in the settings'
order. Labels the document no longer has are dropped.

### 6. Three states, not two: absent, null, and "not a number"

`Num::field()` keeps `null` for an absent price and NAN for a value that is
present but unusable ("abc" in a price box). `isNum()` rejects both, but
`!== null` distinguishes them — which is how `Validate::errors()` can say
"United Kingdom Express 3 kg: price must be greater than 0 or left blank"
instead of silently treating a typo as an empty box. Collapsing NAN to null
would turn a mis-keyed price into a missing service.

### 7. Month names are a table, not a locale call

The TypeScript formats with `toLocaleDateString("en-GB", …)`, which asks ICU.
ICU spells September **"Sept"** in en-GB (and "Sep" in en-US), and it has
changed its mind between CLDR releases. Hostinger's PHP may have no intl at
all. So `Format::MONTHS` and the same table in `pricing.js` are written down
and pinned by the `fmtDay` / `fmtDate` / `fmtDateTime` vectors. The same
reasoning applies to `fmtNum()`: the thousands separators are written out
rather than taken from `Intl.NumberFormat`.

### 8. Accent folding must be the real decomposition, not a transliteration

`nameKey()` decides whether a destination name is one the site already has.
`Str::stripMarks()` uses intl when it is there and falls back to a 488-letter
table generated **from intl itself** when it is not. Letters with no canonical
decomposition — Ø, Æ, ß, Þ, Ł, Đ — are deliberately absent from that table,
because ICU leaves them alone and the `[^a-z0-9]` filter then drops them:
`nameKey("ØE")` is `"e"`, not `"oe"`. A transliteration would match the wrong
country. `testAccentFoldingWithoutTheIntlExtension` runs the whole
`nameKey` / `slug` vector set with intl switched off.

### 9. `encodeURIComponent`, not `rawurlencode`

They differ on `! * ' ( )`. `QuoteText::encodeUriComponent()` puts those five
back, so the wa.me link the server writes is byte-identical to the one the
browser writes for the same message.

### 10. JavaScript object key order, reproduced

`slabToGrid()` returns a map keyed `"1"`, `"2"`, … and the admin stores it.
JavaScript orders integer-like keys numerically ahead of everything else,
whatever the insertion order; `Num::jsKeyOrder()` does the same, so two
identical rate cards cannot serialise differently.

---

## Nine deliberate divergences

All nine are documented here because "the PHP matches the TypeScript exactly" is
otherwise a claim with an asterisk. Apart from the first, the sixth and the
last three, every one is a case no generated vector covers, and **none of them
moves a price the owner typed**: `--filter=PricingVectors` passes and the
browser half `907 of 907 vectors passed`.

1. **The document rate, removed from the product** (owner, 2026-09-27) — the
   whole section above. It is the one divergence that vectors *did* cover,
   which is why those vectors were removed rather than left to fail, and why
   the removal is spelled out case by case instead of summarised.
2. **A weight step of zero.** `computeWeights()` in TypeScript divides by zero,
   gets `Infinity`, and hands back `NaN` grams, which travels silently into a
   price. The PHP and the JavaScript twin both throw instead. `validateSite()`
   refuses to publish a document with `stepKg <= 0`, so this is unreachable
   from a published document; it is a loud failure for an unvalidated one. No
   vector covers it.
3. **A year below 1000 in a quote id.** `quoteId()` takes the last two digits
   of the year with `String(year).slice(2)`, which yields one character for a
   three-digit year. The PHP uses `year % 100`. Unreachable.
4. **A volumetric divisor that is not a positive finite number**
   (audit round 1, finding 5). An admin who clears the box gives the engine 0 —
   `Num::jsNumber("")` is 0, because `Number("")` is 0 in JavaScript — and one
   who types "abc" gives it NAN. Measured, with node, on the expressions the
   engine actually evaluates:

   | `volumetricDivisor` | TypeScript | PHP and `pricing.js` |
   |---|---|---|
   | `5000` | volumetric 1200 g, billed 1500 g | the same |
   | `0` or `""` | `v = Infinity` → **every weight becomes Infinity** | 5000 is used |
   | `"abc"` (NaN) | `v = NaN` → **NaN grams** | 5000 is used |
   | `-5000` | `v = -1200`, silently discarded by `max()` | 5000 is used |

   An Infinity billed weight is past every cargo threshold, so the TypeScript
   turns a 1 kg box into a freight enquiry; with `maxKg = 0` it prices
   `Infinity` instead. PHP 8 does not even get that far — it raises
   `DivisionByZeroError`, which reached the site as a 500 for every visitor who
   typed dimensions. `Settings::effectiveDivisor()` treats a value that cannot
   be divided by as *not set*, and a setting that is not set is this class's
   declared default, the same 5000 an absent JSON key already gets.
   `Settings::volumetricDivisor` still carries the admin's own value, because
   `Validate::errors()` reads it to refuse publishing (1000–10000) and the
   settings form shows it back. `pricing.js` takes the same fallback, so the
   browser's number cannot differ from the server's. Pinned by
   `tests/Pricing/EngineGuardsTest.php`.
5. **A weight or a dimension that is not finite** (audit round 1, finding 6).
   `{"kg": "1e999"}` is nine characters of JSON that `Number()` turns into
   `Infinity`. The TypeScript carries it: `kgToG(Infinity)` is `Infinity`, the
   shipment weighs `Infinity` grams, and the cargo threshold catches it. PHP
   cannot carry it — `(int) INF` is **0** — so before the fix the row passed
   the `kg > 0` filter, contributed zero grams, and left the quote at the first
   slab: the cheapest price in the card for an arbitrarily heavy parcel.
   `Piece::fromArray()` now reads a non-finite field as NAN ("present, but not
   a number", rule 6 above), `computeWeights()` skips such a row with
   `Num::isNum()`, and `priceAll()` filters on the same test so a shipment with
   no readable weight is answered with `reason: "weight"` — "enter a weight" —
   rather than a price. `pricing.js` applies `isNum()` in both places too, so
   the browser and the server agree; only the TypeScript still answers "cargo",
   and only the old PHP ever answered "PKR 1,000".
6. **Skippable weight prices, removed from the product** (owner, 2026-09-28) —
   the whole section above. Like the first, it is a divergence vectors *did*
   cover, which is why six of them were re-derived rather than left to fail, and
   why the change is spelled out answer by answer instead of summarised. The
   TypeScript still walks upward; the PHP and `pricing.js` read one box.
7. **Slab steps are counted from the first slab** (2026-10-25). The
   TypeScript bills `max(first, ceil(charge / step) * step)` and then prices
   `ceil((billed - first) / step)` steps. Those agree only when the first slab
   is a multiple of the step. With a 0.5 kg first slab and 1 kg steps it billed
   a 0.5 kg parcel as **1 kg with one step** (5,000 instead of 4,000) and
   1.2 kg as 2 kg with two steps, a breakdown that contradicted itself. Both
   ports now bill `charge <= first ? first : first + ceil((charge - first) /
   step) * step`, which is the old answer exactly whenever the first slab is a
   multiple of the step - every generated fixture is, and grid is 1 / 1 - so no
   generated vector moved. `weightSentence()`'s "minimum" test is anchored the
   same way. Pinned by `owner-decisions.json`.
8. **No cargo threshold means one tonne** (2026-10-25). With `maxKg` 0 the
   TypeScript prices any weight, but `QuoteInput::MAX_GRAMS` refuses to log
   over 1,000,000 g, so a 3 x 400 kg shipment was priced on the page with a
   Book link whose POST was rejected. `Engine::NO_LIMIT_MAX_G` (which
   `MAX_GRAMS` now reads) and `pricing.js` answer `overmax` above it.
   Text that names the threshold beside an overmax result reads
   `Engine::cargoLimitKg()` / `SpeedatPricing.cargoLimitKg()` (or the JS
   result's `limitKg`), which give 1000 at `maxKg` 0, never `maxKg` itself -
   otherwise a "no limit" card says "over 0 kg".
9. **Text and parsing the owner corrected** (2026-10-25), all pinned by
   `owner-decisions.json` or reworded by W1 / W2:
   - the WhatsApp text says **"Courier"**, as the on-site breakdown does
     (owner, 2026-10-05), and prints no line for an add-on whose amount is 0;
   - a shipment whose pieces are **mixed** (some charged on volume, some on
     scale weight) is no longer said to have a "volumetric weight" equal to
     the sum: "each piece is charged at the greater of its actual and
     volumetric weight (11 kg actual in total)";
   - `resolveAddons()` compares labels by **key** (`QuoteText::labelKey()` /
     `pricing.js` `labelKey()`: whitespace runs collapsed, cut to 80
     characters), because `QuoteInput` squishes and cuts what is posted and an
     exact match dropped "Pickup  and packing" from the stored total;
   - the settings parser trims with **JavaScript's** `trim()` set on both
     sides (`Engine::jsTrim()`). This one is a port bug rather than a
     divergence - the TypeScript already did it - but no generated vector had
     a no-break space in it, so it is pinned here.

---

## Owner-decision vectors

`php/tests/vectors/owner-decisions.json` has the same shape as `pricing.json`
(`sites`, `groups`, `count`) and its groups use the same handlers in
`tests/PricingVectorsTest.php` and `tests/js/run-vectors.mjs`, so each case
is held to both engines. It exists because divergences 7-9 cannot come from
the generator, and a hand-edit to `pricing.json` would be lost on the next
regeneration. Every expected value in it was worked out by hand. 23 of the original
34 fail against `pricing.js` as it was before 2026-10-25, and the six trim
cases failed only the old PHP; the rest pin the edges so a fix cannot
overreach - exactly a tonne still priced, an all-volumetric shipment keeping
its old sentence, a label posted whole still matching.

| Group | Cases | What they pin |
|---|---|---|
| `computeWeights` | 8 | first slab 0.5 / step 1 and 1.5 / 1 |
| `priceAll` | 8 | the same two cards priced; `maxKg` 0 at 1,000 kg, 1,000.1 kg and 3 x 400 kg |
| `weightSentence` | 5 | "minimum" on 0.5 / 1 and 1.5 / 1; mixed and all-volumetric pieces |
| `parts`, `addonsList`, `toNumLoose` | 6 | no-break spaces, ideographic and em spaces, a BOM |
| `resolveAddons` | 5 | double space, tab, labels over 80 characters, a cut that lands on a space |
| `addonsList` (2026-10-26) | 2 | a `separate` / `info` / `SEPARATE` line is not an add-on; the other ids keep their gaps |
| `separateCharges` | 5 | the owner's `Pickup \| 1000 \| separate`; `info`; ids by line; zero, unlabelled and `n/a` lines dropped; a misspelt `seperate` stays an off add-on; 0.4 and 0.49, which print "PKR 0", dropped and 0.5 kept (review, 2026-10-26) |
| `chargeName` | 6 | "Pickup" -> "Pickup charge"; a label already ending in charge(s) or fee is left alone; whitespace runs (double space, tab, no-break and ideographic spaces) are one space, the `labelKey()` set (review, 2026-10-26) |
| `quoteText` | 4 | a zero add-on beside a paid one; a zero add-on alone; a separate charge after `Price`, never added; a separate charge after an on add-on's `Total` |
| `validateSite`, `warnSite` (2026-10-26) | 2 | site `ownerAddonFlags`: a misspelt third column (`seperate`, `Separately`, `separate charge`, `seprate`) is a WARNING, not an error, while on/off/no/OFF/Info and an empty column are silent; a broken line's error names "on, off or separate" |

**Separate charges (owner, 2026-10-26).** "The rates shown everywhere should
only show the actual rate, not the pickup charges included. At the bottom just
add a drill down and there show 1000 pickup charges." An add-on line whose
third column is `separate` (or `info`) is left out of `addonsList()` in both
engines - so no tile, board cell, caption, calculator total, WhatsApp total,
stored total or JSON-LD offer can add it - and returned instead by
`separateCharges()` (`Engine::separateCharges()` / `SeparateCharge`), whose
only readers print it: the calculator's drill-down and one
`"<name> (separate): PKR 1,000"` line in `quoteText()` after the price. Both
runners pass the same settings' separate charges into every `quoteText` case,
which is `[]` for every generated vector, so none of them moved.

Review, 2026-10-26: a misspelt keyword is warned about (it has always meant
"off", so it is an optional tick box the customer can add, which is the
opposite of a separate charge), a separate charge is dropped when it would
PRINT as 0 (`Math.round` / `Money::jsRound`, the money formatter's rounding),
and `chargeName()` collapses whitespace runs before naming the charge.

Site names must not repeat one in `pricing.json`; both runners refuse a clash.

---

## What is covered, group by group

861 vectors in 45 groups:

| Area | Groups |
|------|--------|
| Rounding and grams | `roundTo`, `kgToG`, `gToKg` |
| Sheet and settings text | `toNumLoose`, `parts`, `addonsList` |
| Grid | `gridWeights`, `gridPrice`, `effectiveSteps`, `slabToGrid` |
| Weights | `computeWeights` — 200 cases over four documents, every whole kilogram 1–25, fractional weights, volumetric, quantities, mixed rows |
| Prices | `priceAll` — 176 cases: both modes, tax 0/3/16/17, rounding 0/1/10/50, the cargo threshold, hidden and unknown destinations, missing services and empty grids |
| Re-pricing | `priceService` — 44 cases: both modes, both services, seven billed weights each, a card whose first slab is a whole kilogram, a missing service and a partial card |
| Dates | `parseDaysRange`, `parseDays`, `workingSet`, `holidaySet`, `badHolidayLines`, `estimateDelivery` (31 cases: cutoffs, Sundays, holidays, past ship dates, a year boundary) |
| Formatting | `fmtNum`, `fmtMoney`, `fmtDay`, `fmtRange`, `fmtDate`, `fmtDateTime`, `fmtPhone`, `fmtHour`, `inputDate`, `localDateFromInput` |
| Ids and keys | `quoteId`, `quoteIdFor`, `nameKey`, `slug` |
| Customer sentences | `piecesText` (metric and imperial), `weightSentence`, `midSentence` |
| Booked quotes | `bookedTotal`, `resolveAddons`, `quoteText`, `leadSummary`, `cargoText`, `holdText`, `waLink` |
| Publishing rules | `validateSite`, `warnSite` |

`holdText` is the one builder that lives in a component rather than in `lib/`
(`src/components/site/HoldPanel.tsx`); the generator reproduces it beside the
others so the port has something to be checked against.

---

## Adding a vector

1. Add the case to `php/tools/make-vectors.ts` — input and nothing else; the
   expected answer comes from calling the real TypeScript.
2. Regenerate, **then re-run `tests/vectors/strip-document-rate.php`**. A case
   added with a shipment type is dropped by R2/R3/R4; do not add one.
3. Run `php bin/test.php`. If the PHP or the JavaScript disagrees, fix the
   port.
4. If a group is new, add a handler to `tests/PricingVectorsTest.php` and to
   `tests/js/run-vectors.mjs`. Both runners fail loudly on a group they do not
   know rather than skipping it.
