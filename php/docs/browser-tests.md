# The browser test harness

Twelve flows that walk the finished site in a real Chrome and report pass, fail
or not-ready per check. It exists so that the whole product can be exercised in
minutes rather than an afternoon, against the local dev server or against
staging, by anyone, repeatedly, without a checklist on paper.

It is item 2 of the evidence list in [`quality-bar.md`](./quality-bar.md): *"a
full flow walk in a real browser (Playwright + Chrome), the same one the Next.js
site passed … each flow is pass/fail with screenshots."*

Everything lives in `php/tests/browser/`. Nothing here is deployed — the
Hostinger host has no Node, and this directory is not part of the release build.

---

## 1. Run it

```bash
cd php/tests/browser

node run.cjs                                  # every flow, local dev server
node run.cjs quick-rate-phone                 # one flow
node run.cjs admin-login admin-hold           # several
node run.cjs --base http://127.0.0.1:8085     # staging, through the tunnel
node run.cjs --list                           # what flows exist
node run.cjs --help
```

**Where it stands, 29 September 2026.** Two consecutive full runs against the
local dev server, with `SPEEDAT_ADMIN_EMAIL` and `SPEEDAT_ADMIN_PASSWORD` set:

```
run 1   143 passed   0 failed   11 not ready   of 154 checks in 12 flows
run 2   143 passed   0 failed   11 not ready   of 154 checks in 12 flows
```

Identical, back to back — which is the point of running it twice. The eleven
NOT READY are configuration and data facts, each naming what to set: no
`SPEEDAT_METRICS_TOKEN`, no Firebase project (so no Google button), no
`totp_enabled` on the account (so no 6-digit code), prices not held (so no hold
panel), every country has a rate (so no empty case), one page of shipments (so
no paging). None of them is a defect.

### Playwright

`php/` has no `package.json` and no `node_modules`, because the host it deploys
to has neither and a test harness is not a dependency of the product. So the
runner **borrows** a Playwright install. Point it at one:

```bash
SPEEDAT_PW_HOME=/path/to/dir/containing/node_modules  node run.cjs
```

It also finds Playwright automatically in any `node_modules` above the current
directory or above `php/tests/browser/`. It needs **Playwright 1.58 or later**
and the **installed Chrome** (`channel: "chrome"`), not a downloaded browser —
the point is to test what a customer's browser does.

If it cannot find Playwright it prints where it looked and exits 3. It never
installs anything.

### Which site

In order of precedence:

1. `--base http://…` / `-b`
2. `$SPEEDAT_BASE_URL`, or `$BASE_URL`
3. `http://127.0.0.1:8081` — the local dev server

**Local:**

```bash
powershell -ExecutionPolicy Bypass -File php/bin/local/serve.ps1
# serves http://127.0.0.1:8081
```

**Staging** (the container at `~/speedat-staging` on the company server) is not
exposed to your machine, so tunnel to it first:

```bash
ssh -N -L 8085:127.0.0.1:8085 admin_tf@110.93.205.230
# then, in another shell:
node run.cjs --base http://127.0.0.1:8085
```

Any base URL works, including one behind a tunnel on a different local port.
Pick the port on the left of the `-L` and pass that.

### Options

| | |
|---|---|
| `-b`, `--base URL` | the site to test |
| `--list` | list the flows and exit |
| `--headed` | show the browser |
| `--slow MS` | slow every action down, to watch it work |
| `--strict` | treat NOT READY as a failure (see §4) |
| `--no-shots` | write no screenshots |
| `--json FILE` | also write the whole report as JSON |
| `-v`, `--verbose` | print measurements and notes as they happen |

### Exit codes

| | |
|---|---|
| **0** | nothing failed. Some checks may be NOT READY. |
| **1** | at least one check FAILED. |
| **2** | **the site is not up.** Nothing was tested. |
| **3** | the harness could not start: no Playwright, no Chrome, bad arguments. |

Exit 2 is deliberately not 1. A dead base URL is not a failing test, and a
script that treats the two the same will one day report a green build because
nothing was running.

---

## 2. The flows

Run in this order; the cheap ones that tell you whether the deployment is sane
at all come first.

| Flow | What it walks |
|---|---|
| `ops-endpoints` | `/health` thin and detailed, `/api/metrics`, `/ops/opcache-reset`, the security headers, the CSP nonce, the paths that must never be served, directory listings, the real 404, the favicon, every asset the page asks for, cache-busting |
| `inner-pages-and-seo` | the five public pages: title, description, canonical, one `h1`, OG + Twitter, JSON-LD, landmarks, heading order, `alt` text, image sizing, 14px floor, 375px fit, `/sitemap.xml`, `/robots.txt`, trailing-slash 301s, `/index.php` → `/` |
| `quick-rate-desktop` | 1366×768: country → weight → price. Largest text, no count-up, nothing moves, the WhatsApp message, the quote log and the server's own recompute, switching service, a country with no rate |
| `quick-rate-phone` | 375×812: the same, plus no sideways scroll, 44px tap targets, the price on screen without scrolling, the sticky bar not covering the footer, the cargo way out |
| `detailed-quote` | pieces, add/remove, metric↔imperial, the contents line, the piece list in the message, Copy, that no Packages/Documents choice is left, and that the detailed and quick panels agree on the same shipment |
| `hold-panel` | prices held: **no price anywhere in the HTML**, the calculator absent rather than hidden, and the by-hand enquiry still works |
| `contact-form` | fields, labels, length caps, CSRF, the honeypot, bad input refused politely, a real enquiry accepted, the double submit, and **it works with JavaScript off** |
| `admin-rates-publish` | edit a draft (and it stays a draft), review, publish, see it live, restore **and publish the restore**, the CSV export, the editor's real no-JavaScript save, and that **no rate-sheet upload exists** |
| `admin-hold` | hold prices, prove the public site shows none, **restore** |
| `admin-inbox-shipments` | an enquiry arrives, is shown as text not executed, becomes one shipment (not two), picks up a timestamped event, paging, nonsense ids |
| `admin-settings-content` | edit a setting and a page's words, see them live, prove they are escaped, the FAQ's JSON-LD matching the page, **one WhatsApp number and no orphan in the structured data**, **restore** |
| `admin-login` | both methods, no user enumeration, rate limiting, a forged Google token refused, the 6-digit code, a **spent code refused**, the session cookie's flags, logout revoking the session. **Runs LAST** — see below |

### `admin-login` runs last, on purpose

It is the only flow that spends the sign-in budget deliberately: *"repeated
failures are rate limited"* makes six failed attempts, and
`Http\Admin\LoginController` limits per IP as well as per address over a
**fifteen-minute** window. Every flow arrives from `127.0.0.1`.

At its old position (order 20) it ran *before* the four flows that need a
session, emptied the bucket under them, and they died with *"login did not
produce a session cookie"* — four crashed flows, none of it about the product,
and whether it happened at all depended on how recently the suite had last been
run. It is now order 29, after them, and it spends what is left, which nothing
after it needs.

### What every flow asserts, in one place

These come from `contract.cjs`, so the twelve flows cannot drift apart on what
"right" means:

- **three taps to a price** — country, weight, the price; the third tap books it
- **the price is the largest text** on the page, and appears **instantly** with
  no count-up (measured: one distinct value, under 400 ms)
- **the page never scrolls itself**, and layout shift stays under 0.02
- **the WhatsApp link carries** destination, weight, service, price and dates
- **the quote is logged**, and the server prices it again rather than believing
  the number the browser sent
- **no console error**, no uncaught error, no failed request
- **no hydration-style mismatch** — JavaScript does not rewrite what the server
  printed (there is no hydration in PHP; this is the same bug under a new name)
- **nothing under 14 px**, and **no horizontal scroll at 375**. The floor is
  site-wide and covers `/admin` — see `quality-bar.md` §1, which is where the
  number is decided; `MIN_FONT_PX` here only measures it
- **the hold panel shows no price anywhere in the HTML** — not hidden, absent
- **every admin form carries a CSRF token** and **works without JavaScript**
- **the SEO essentials** — title, description, canonical, one `h1`, JSON-LD that
  parses, and a sitemap and robots file that answer and agree

### The no-JavaScript check names its own form

`contract.submitsWithoutJavaScript(t, path, …)` takes a `formSelector`, and the
default is `form[method=post]` — which on every signed-in admin page is the
**Sign out** button in the header. For three months "the editor works with
JavaScript off" clicked Sign out, saw the URL change, passed, and ended the
session the checks behind it were holding. Two rules now:

- a form whose action looks like a sign-out is **refused** with a message saying
  so. There is no case where signing out is the thing under test, and the damage
  it does lands several checks later where nobody looks for it;
- `expect: "saved"` makes the check assert the server **accepted** the post, not
  merely that the page changed — an error notice is a change too. Callers that
  submit a form on purpose to see it refused (the empty sign-in form) leave
  `expect` alone.

Name the real form. `form#editor` for the rates editor; for a page found at
runtime, ask the page which POST form carries the editable fields.

### Two 30-second traps the admin flows fell into

- **One TOTP code cannot sign in twice.** The site's replay guard spends a step
  and refuses anything not strictly newer, and four flows signing in one after
  another all landed in the same 30-second step. Use `freshTotp()` from
  `harness.cjs`, never `totp()` directly: it waits for the next step when the
  last one it issued is already spent. `adminSession()` already does.
- **The sign-in rate limit is a shared budget.** `admin-login`'s "repeated
  failures are rate limited" fails a sign-in eleven times on purpose. It runs
  **last in that flow**, after everything that needs to sign in, including the
  four admin flows behind it — a full run reaches 22 of the 40 attempts the
  per-address bucket allows in fifteen minutes (measured 2026-09-28). If you add a check that signs
  in, add it above that one, and do not run the whole gate twice inside a
  quarter of an hour.

---

## 3. Credentials

The admin flows need an account, from the environment only — never from a file
in the repository, and never a default:

```bash
export SPEEDAT_ADMIN_EMAIL=you@example.com
export SPEEDAT_ADMIN_PASSWORD=…
export SPEEDAT_ADMIN_TOTP_SECRET=…     # the base32 secret, to compute the 6-digit code
export SPEEDAT_METRICS_TOKEN=…         # for the detailed /health answer
export SPEEDAT_CRON_TOKEN=…            # for /ops/opcache-reset
```

Without them the admin flows report NOT READY and name the variable. They do not
fail: a missing password is not a defect in the product.

The harness computes TOTP codes itself (RFC 6238, from node's own `crypto`), so
no authenticator app is needed and no dependency was added to get one. **If the
server's clock is wrong the code will be refused** — that is the first thing to
check when the right code does not work.

A real Google sign-in is the one thing here that is **not** automated: it needs a
real Google account in a real consent screen. `admin-login` refuses a forged
token, then reports NOT READY for the successful path and says it stays a manual
check before release.

### One sign-in per run, and why (2026-09-29)

`adminSession()` signs in **once per run** and every flow after the first
reuses that session. This is not an optimisation, it is a correctness fix.

`Http\Admin\LoginController` limits sign-in attempts per IP as well as per
address — `accountAttempts() * 4` inside a **fifteen-minute** window — and
`admin-login`'s own "repeated failures are rate limited" check deliberately
spends six of them. Every flow arrives from `127.0.0.1`, so five separate
logins plus that check emptied the bucket, and three flows crashed with *"login
did not produce a session cookie"*. That is the site's brute-force defence
working, reported as the admin being down, and whether it happened at all
depended on how recently the suite had last been run — a red check that came
and went on its own, which is the fastest way to teach people the suite means
nothing.

Two consequences worth knowing:

- The cached session is **verified, not assumed**: each flow loads `/admin` with
  it and signs in properly if that lands on the login page. A flow that signs
  out does not break the next one.
- If the limit is spent anyway — usually two full runs inside fifteen minutes —
  the flow reports **NOT READY** and says to wait out the window. It no longer
  crashes.

### Two-factor is per account, not per site

`admin-login`'s code checks need an account with `admins.totp_enabled = 1` and
`SPEEDAT_ADMIN_TOTP_SECRET` set. An account without it signs in on the password
alone, which `LoginController` does by design; the check reports NOT READY and
names what to turn on. It used to call that a failure — *"the second factor is
required on both paths"* — which is a rule the product does not have.

---

## 4. Reading a result

Three verdicts, never two:

```
  ok   the price appeared instantly, with no count-up  38.4ms, one value only: "PKR 9,850"
 FAIL  the price is the largest text on the page
        the price is NOT the largest text on the page.
          price: 34px  "PKR 9,850"
          bigger:
            48px  section.page > h1  "International courier from Lahore"
 n/r   /sitemap.xml is valid, complete and free of dev hosts
        /sitemap.xml answers 404 - Domain\Seo has not shipped
```

**`ok`** — the check ran and the contract held. The grey text is the evidence:
the actual millisecond count, the actual font size, the actual number of URLs.

**`FAIL`** — the check ran and the contract is broken. The lines under it name
the offending element, its selector path, its size, and what was expected. A
screenshot is written automatically. Somebody has to fix this.

**`n/r` (NOT READY)** — the check could **not** run, and the line under it says
which surface has not shipped: a 404, a missing `[data-calculator]`, an
environment variable, a rate that is not published. This is the verdict that
keeps the harness honest while the port is half-built. **It is never a silent
pass.** A flow whose every check is `n/r` is listed separately at the end:

```
  7 flow(s) could not run at all: hold-panel, contact-form, admin-login, …
```

### The summary

```
------------------------------------------------------------------------
  26 passed   0 failed   59 not ready   of 85 checks in 12 flows
```

`--strict` makes NOT READY a failure. **That is the release gate**: when the port
is finished, `node run.cjs --strict` must exit 0. Until then the same command
tells you exactly how much is left, which is the more useful number of the two.

### The preflight

Before Chrome starts, the runner fingerprints the deployment and prints it:

```
  site      : http://127.0.0.1:8081
  answering : / /health
  404       : /services /about /contact /faq /sitemap.xml /robots.txt /admin …
  shipped   : no calculator, no hold panel, no SEO partial
```

That is where to look first. Most `n/r` lines are already explained by it, and a
flow failing for a reason this table predicts is not a bug in the site.

### When the site is not up

```
THE SITE IS NOT UP.

  base URL : http://127.0.0.1:8081
  error    : ECONNREFUSED connect ECONNREFUSED 127.0.0.1:8081

Nothing was tested. Start the site first:
  local    : powershell -ExecutionPolicy Bypass -File php/bin/local/serve.ps1
  staging  : ssh -N -L 8085:127.0.0.1:8085 admin_tf@110.93.205.230
```

Exit 2, no flows attempted, no green ticks. The same happens if the home page
answers 5xx: a broken deployment is not a failing test.

### Diagnosing a failure

1. **Read the detail lines.** They carry the selector, the measured value and
   the expected one. Most failures need nothing else.
2. **Look at the screenshot** in `shots/`, named `<flow>--NN-FAIL-<check>.png`.
3. **Re-run just that flow** with `--headed --slow 300` and watch it.
4. **Check the preflight table.** If the surface is listed as 404 the answer is
   that it has not shipped, and the verdict should have been `n/r` — that is a
   bug in the flow's preconditions, worth fixing so the next person is not sent
   hunting.
5. **`--json report.json`** for the whole thing, including the console messages
   and failed requests each flow saw.

Two failure messages that mean something specific:

- *"Chrome does not name the resource. /favicon.ico answers 404"* — Chrome asks
  for `/favicon.ico` itself and does not tell Playwright which resource failed.
  Either declare `<link rel="icon">` or serve the file at the root.
- *"THE LIVE SITE STILL CARRIES THIS HARNESS'S EDITS"* — `admin-hold` or
  `admin-settings-content` could not undo what it changed. **Act on it now**; the
  message lists the original values.

### What a red check is allowed to mean (2026-09-29)

A suite that cries wolf is worse than no suite, because the cost is paid on the
*next* failure, the real one, which gets scrolled past. Six checks were red on
a correct site and each was fixed at the check rather than at the product. They
are listed here so nobody re-introduces them:

| it used to say | what was actually true |
| --- | --- |
| *canonical points at a dev host* (×5, plus the sitemap and robots) | the dev server printing its own address, correctly. The rule is now **"the host in the URL is the host being tested"**, which still catches a staging build canonicalising to a laptop, or a production build canonicalising to staging — on every host, with no word list to maintain |
| *the cargo enquiry does not say it is a cargo rate: ""* | `bookingHref()` took the **last** `wa.me` link in the document — the footer's plain number, which carries no `?text=`. It now takes the most specific selector that matches, and inside it a link that carries a prefilled message |
| *a valid enquiry was submitted and the page never acknowledged it* | `Http\FormToken` refusing a form returned in under three seconds. Playwright fills four fields in one. `contract.settleThinkTime()` waits out whatever is left of the site's own minimum, read from the token the page issued |
| *the honeypot website is visible*, and *no maxlength on: website* | the honeypot is hidden at `left:-9999px` inside an `aria-hidden` wrapper — the standard way, because `display:none` is the one thing a competent bot looks for. The check's idea of "hidden" was `display:none \|\| offsetParent === null` and matched neither |
| *the price is NOT the largest text on the page* (detailed panel) | the page's own `<h1>`, which is not a rival. That check is titled "in the detailed panel" and now measures inside `[data-calculator]`. **Both quick-rate flows still assert the page-wide form**, which is the strong one |
| *the review screen does not show the pending change of 8211* | the review screen prints `8,211`. Thousands separators are stripped before the comparison |

Two more, about the browser rather than the page:

- **A 4xx or 5xx on a navigation no longer fails "the browser stayed quiet".**
  Chrome logs *"Failed to load resource: the server responded with a status of
  N"* for a navigation and never says which URL. Every navigation in a flow is
  one the flow chose to make, and the flow already reads that status where it
  makes it — `contact-form` provokes 422s on purpose, `admin-settings-content`
  probes four admin paths to find which exists. They are still **printed** in
  the check's note, with method, status and path, so a page that starts
  answering 500 stays visible.
- **`noConsoleErrors(t, { allow: [...] })`** is how a flow says which refusals
  it caused on purpose. `contact-form` allows `422` and `ERR_CACHE_MISS` (Chrome
  refusing to replay a POST when the flow presses Back); `admin-login` allows
  `401|403|429`. Nothing else is tolerated.

### Exercising the calculator on its own

There used to be a shortcut here: `public/assets/dev-preview.html`, a page that
mounted the real `calculator.js` and `pricing.js` against a hand-written site
document, so the quick-rate machinery could be driven before `Views/site`
existed. **That file was deleted on 2026-09-27** (`docs/assets.md` §1). It was
plain HTML inside the web root, reachable by anyone who guessed the path, and
it carried an inline `<script>` the site's own CSP forbids;
`bin/build-release.php` now refuses any `.html` under `public_html/`, so it
cannot come back by accident. The harness has no `steps.js` to point at it
either — the file is `tests/browser/steps.cjs`, and it drives real routes.

What does that job now, which is telling a broken selector apart from a page
that has not shipped:

```
node run.cjs quick-rate-desktop -v      one flow, every measurement printed
node run.cjs --list                     what flows exist
```

A flow that cannot run says so as NOT READY, with the reason in the operator's
words — which is what the preview was really being used for.

**A word about the layout-shift number.** `measure()` observes layout shift with
`buffered: false` deliberately. With `buffered: true` the observer is handed
every shift since navigation, and the page's own load-time reflow gets charged to
the tap that produced the price: the dev preview measured CLS 0.1185 that way and
0 correctly, which is the difference between a real defect and an afternoon spent
"fixing" a result slot that was never moving.

---

## 5. Screenshots

Every run writes to `php/tests/browser/shots/`:

```
shots/
  quick-rate-desktop--01-cold.png
  quick-rate-desktop--02-priced.png
  quick-rate-phone--05-FAIL-the-price-is-the-largest-text-on-the-page.png
```

`<flow>--<nn>-<name>.png`, numbered in the order the flow took them, so the
sequence reads as the walk. A failing check screenshots itself automatically with
`FAIL-` in the name. The directory is not committed; `--no-shots` skips it.

---

## 6. The files

```
php/tests/browser/
  run.cjs           the runner: arguments, preflight, launch, report, exit code
  harness.cjs       the shared helper module - finding Playwright, the base URL,
                    the preflight probe, contexts per viewport, console capture,
                    screenshots, measure(), TOTP, the admin sign-in, the verdict
                    machinery
  contract.cjs      what "right" means: largest text, 14px floor, no sideways
                    scroll, the WhatsApp message, no price in held HTML, CSRF on
                    every form, works-without-JavaScript, the SEO essentials
  steps.cjs         the tap sequences and the selectors they use, written once.
                    The detailed panel's are in here for a reason: it has TWO
                    <select>s with "From" first, its price is `.quote dd.display`
                    and not `.tval`, and the quote card does not exist until a
                    service card has been pressed. Six checks reported six
                    defects that were not there before this file knew that.
  flows/*.cjs       one file per flow
  shots/            screenshots (not committed)
```

### Writing a flow

```js
module.exports = {
  name: "my-flow",
  order: 30,                       // run order; lower is earlier
  title: "one line, shown in --list",
  viewport: "desktop",             // or "phone"; opened before run()

  async run(t) {
    await t.open("/somewhere");     // 404 → NOT READY, 5xx → FAIL

    await t.check("a statement about the product", async () => {
      t.need(somethingExists, "why this cannot be checked yet");
      if (wrong) throw new Error("what is wrong, and what was expected");
      return "the evidence that it is right";
    });
  },
};
```

- `t.check(name, fn)` is the only way to report anything. Return a detail string
  to pass; throw to fail; call `t.need(cond, reason)` or `t.notReady(reason)` for
  NOT READY.
- `t.measure(selector)` before an interaction, `.settle()` after: click-to-text
  milliseconds, every distinct value the element held (a count-up shows as
  several), layout shift, and how far the page scrolled itself.
- `t.fetch(path)` for plain HTTP — what the server actually sent, which is what
  the SEO checks must read.
- `t.shot(name)`, `t.log(note)`, `t.probe` (the preflight), `t.page`,
  `t.context`, `t.browser`.
- Selectors belong in `steps.cjs`, assertions in `contract.cjs`. A flow should
  read as a description of the walk.

### The rule this harness is built on

A check that cannot run says so, by name. It never passes quietly. The whole
value of running this against a half-built site is that the output is the honest
difference between what exists and what was promised — and a green tick for a
page nobody has written yet would destroy that in one run.
