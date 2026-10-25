# Audit round 2 — the wired application

Adversarial review of everything wired since round 1: `app/Http/**` (the public
controllers and the whole admin), `app/routes.php`, `public/index.php`, the
session and login flow end to end, the publish path, the price hold, the CSV
export, the ops endpoints, and the interaction between them. Scored against
[`quality-bar.md`](./quality-bar.md) §2 and the host's reality in
[`hostinger-constraints.md`](./hostinger-constraints.md).

Round 1's [`audit-round1.md`](./audit-round1.md) owns `app/Core/**`,
`app/Domain/{Pricing,Auth}/**`, the migrations, `.htaccess`, the shipped JS and
the release builder; its "checked and found sound" section was taken as given and
not re-run. Where a round-1 finding's *reachability* runs through a round-2
surface (the divide-by-zero of finding 5, the `billable_g` ceiling of finding
13, the CSV `<script>` note of item 25, the fail-open limiter of finding 10) it
is re-checked here from the caller's side and the result recorded below.

**Method.** Every line below was produced by running something against the live
local tree (`http://127.0.0.1:8081`, real config, real MariaDB 11.4 on 3307),
not by reading a comment. Nothing in the repository was modified. Sessions were
minted directly into the test database (`scratchpad/audit2/mint.php`) and CSRF
tokens computed from each session's own secret (`scratchpad/audit2/csrf.php`),
so the admin could be attacked as an **editor** and as an **owner** without any
password. Test artifacts (minted sessions, one forged quote, one forced version)
were deleted afterwards and the site left green.

| probe | what it does |
|---|---|
| `mint.php` / `csrf.php` | mints a `sessions` row + a matching CSRF token for any address, for editor-vs-owner and cross-session token tests |
| `hold_on.php` | flips the price hold on/off through the real `Domain\SiteDoc\Hold` so the public responses can be scraped for a leaked price |
| `totp_replay.php` | verifies a real code twice, and races two readers of the same pre-spend row, against the owner's real sealed secret |
| `publish_reject.php` | drives `Repo::publishVersion` and `Validate::errors` with a broken document to see what the publish path actually rejects |
| curl matrix | anonymous / editor / owner, valid / cross-session / absent / cross-site-Origin / `Origin: null` tokens, expired & idle & forged & malformed session cookies, the quote endpoint under every abuse, the ops trio, every error status |

Severities: **blocker** = must not ship. **major** = a real hole or a 500 a
visitor can cause. **minor** = a weakness, a drift from the stated bar, or a
latent footgun. **Nothing in round 2's surface is a blocker or a major.**

---

## Summary, ranked

> **STATUS, 2026-09-29 — BOTH FINDINGS ARE FIXED. Nothing in this document is
> open.** Checked against the code, not against memory; the note under each
> finding names the lines that now do the work. The findings themselves are
> left exactly as they were written, because what an audit is worth a year
> later is its reasoning, and a finding rewritten into its own fix reads as
> though nobody ever got it wrong.

| # | sev | status | where | what |
|---|---|---|---|---|
| 1 | minor | **fixed** | `app/Domain/SiteDoc/Repo.php`, `Validate.php` | The publish-time `Schema::firstIssue()` check runs on the **already-normalised** `SiteData`, so it can never reject; and `Validate::errors()` does not require ≥1 service. The real guard is the admin form's field allow-list, not validation. Not HTTP-reachable today; a defense-in-depth + docblock-accuracy gap. |
| 2 | minor | **fixed** | `app/Domain/Quotes/QuoteId.php`, `QuoteRejected.php`, `QuoteService.php` | `/api/quotes` rejections are **not uniform**: a malformed id, an unknown/inactive destination and a generic bad body each return a different message/status, against the stated "probing teaches nothing" goal. No sensitive or hidden data is disclosed. |

---

## Minor

### 1. The publish path's schema guard checks a document that is already valid — `app/Domain/SiteDoc/Repo.php:182-190`

> **FIXED — verified in the code on 2026-09-29.** Both halves:
>
> * `Repo::publishVersion()` now checks the **un-normalised** input first and
>   the normalised document only as a second pass —
>   `$issue = $args->raw === null ? null : Schema::firstIssue($args->raw);`
>   followed by `$issue ??= Schema::firstIssue($doc);`. A caller that hands in
>   `raw` gets a guard that can actually fire, on the bytes that arrived rather
>   than on what `SiteData::fromArray()` coerced them into.
> * `Validate::errors()` requires at least one service:
>   `if ($card->services === []) { $errs[] = 'At least one service is needed.'; }`
>
> The line numbers in this heading are the ones the finding was written
> against and no longer point at the code; the method is `publishVersion()`.

`Repo::publishVersion()` opens with what reads as the last line of defence:

```php
$doc   = $args->data->toArray();
$issue = Schema::firstIssue($doc);
if ($issue !== null) {
    throw new \InvalidArgumentException('refusing to publish an invalid document: ' . $issue);
}
```

and the class docblock promises it (`:14-18`): *"every write runs the document
through `Schema`, so a version the admin's own save would reject can never become
the live one, whoever publishes it."*

The guard cannot fire. `$args->data` is a `SiteData`, and `SiteData::fromArray()`
has already **coerced** whatever it was built from into a schema-valid shape, so
`$args->data->toArray()` is schema-valid by construction. Proven by handing the
publisher a document the raw schema **does** reject:

```
$ php publish_reject.php
versions before = 6
Schema::firstIssue(broken services) = SchemaIssue{ path:'services',
    message:'Invalid input: expected array, received string' }
PUBLISHED broken -> FAIL (should have thrown)          <-- it published anyway
...
versions after  = 7 (CHANGED - BAD)
```

The stored version 7 was not the invalid document — it was the *normalised* one:

```
services type in stored v7: array
services count: 0
Schema::firstIssue(stored v7) = NULL
Validate::errors(stored v7) = []      <-- a zero-service document validates clean
```

Two things follow, and both are recorded rather than alarmed:

- **The security property still holds today.** No schema-*invalid* document can
  be stored, because normalisation guarantees validity, and the HTTP layer
  cannot reach the structural fields at all: `DraftForm` is an allow-list patch
  whose own docblock says "the services list … cannot be changed by posting one",
  and the controller's `Validate::errors()` gate catches the degenerate cases the
  form *can* produce (divisor, `firstKg`, `stepKg`, `docMaxKg`, "no active
  destination", "no priced service"). Confirmed: `volumetricDivisor: 0` is caught
  by `Validate::errors()` (`YES` above), which closes the round-1 finding-5 class
  from the publish side — a divide-by-zero document cannot go live.
- **But the guarantee rests on the form, not on validation.** `Schema::firstIssue`
  here is dead as written, and `Validate::errors()` has no "≥1 service" rule
  (a zero-service document passed clean above). The day a new field is added to
  the editor whose bad value `SiteData::fromArray()` silently coerces into a
  "valid but wrong" shape that `Validate` does not know about, it goes live with
  no complaint. That is the failure the docblock claims is impossible.

**Fix.** Run `Schema::firstIssue()` on the **raw decoded array** before
`SiteData::fromArray()` (or have `publishVersion` take the raw document), so the
check sees what was actually submitted; and add a "at least one service" rule to
`Validate::errors()` so the invariant is enforced by validation rather than by
the UI's field list. Correct the docblock to say what actually gates a publish.

### 2. `/api/quotes` rejections are not uniform — `app/Domain/Quotes/QuoteId.php:55` + `QuoteService.php:88`

> **FIXED — verified in the code on 2026-09-29.** The `not_found` code is
> gone. `QuoteRejected`'s docblock now names the three answers that used to
> exist and says why they are one: a reference this site did not issue, a
> destination it does not sell to, a destination it has hidden, a service it
> does not offer and a weight that is not a number all answer `bad_request` /
> **400** / "Invalid quote". `QuoteId::parse()` carries the matching note, and
> `QuoteService::log()` refuses an inactive destination with the same code as
> an unknown one.
>
> The three codes that remain — `held`, `over_max`, `unavailable` — are product
> messages a visitor is being shown on the page anyway, not oracles.

`QuoteRejected`'s docblock states the design: *"no hint about which of several
checks failed. Whether a destination is unknown or merely hidden is not a
distinction a caller gets to learn by reading error text."* Round 1 handed this
endpoint to round 2 (item 25) to confirm.

The hidden-vs-unknown merge is correctly done — an **inactive** destination and
an **unknown** one return the identical `notFound()` (`QuoteService.php:86-90`).
But three *other* outcomes are distinguishable, measured against the live route:

```
malformed id      -> 400 {"code":"bad_request","message":"That is not a quote reference."}
unknown/inactive  -> 404 {"code":"not_found","message":"Unknown destination or service"}
other bad field   -> 400 {"code":"bad_request","message":"Invalid quote"}
```

`QuoteId::parse()` throws its own sentence (`:55`), and `QuoteService` throws
`notFound()` (`:88`) with a different status again, so a prober learns whether it
was the *id*, the *destination/service*, or *something else* that failed.

**Impact is informational only.** Every value a message speaks to is one the
caller itself supplied and already knows (its own id, its own destId); no hidden
destination, no server internal, no price is disclosed, and the endpoint carries
no authority (round 1 established the row is server-priced and rate-limited).
This is a drift from the endpoint's own stated bar, not a leak.

**Fix.** If the "teaches nothing" goal is to be met literally, collapse
`QuoteId::parse`'s message and the `notFound` case into the same
`badRequest('Invalid quote')` / 400 the other field failures use. If the
distinction is wanted for client ergonomics, soften the docblock so the two do
not contradict.

---

## Checked and found sound — do not redo this in round 3

### Session forgery, expiry and revocation — every synthetic cookie refused

Driven with real cookies against `/admin`:

```
editor session            -> 200      (control: a good session reaches the editor)
owner session             -> 200
anonymous                 -> 302 /admin/login
expired (absolute) row    -> 302      (expires_at in the past)
idle 9h (idle cap is 8h)  -> 302      (last_seen too old)
session for a non-admin   -> 302      (Guard destroys the row on the spot)
random 64-hex cookie      -> 302      (no matching row)
malformed cookie          -> 302      (fails the ^[a-f0-9]{64}$ shape before any query)
```

`Guard::admin()` re-reads `admins` on **every** request and destroys a session
whose address is no longer an admin, so a login minted before someone was removed
is not an admin the next request. Both clocks are enforced on read
(`Session::current()`), so an expired or idle row is dead before the sweeper
runs. An anonymous admin request is always a 302 to the sign-in, never a 403 and
never a 200 with a page — which is what `routes.php` and `Guard`'s docblocks
promise. A database that is down reads as "nobody is signed in", never a 500.

### CSRF is bound to the session, across accounts and origins

```
editor's token + OWNER session, on a write   -> 419 invalid token
owner's token + owner session (control)      -> 303 (accepted)
no token                                      -> 419
Origin: https://evil.example + valid token    -> 419
Origin: null + valid token                    -> 419
absent Origin + valid token                   -> accepted (documented: privacy clients)
```

The token is signed with `HMAC(app-key-derived, session.secret)`, so a token
minted under the editor's session secret is refused under the owner's session —
visitor A's token does not verify for visitor B, proven above. `Origin: null` is
refused and a cross-site Origin is refused; the Host-reflection in
`Csrf::originOk()` is not a CSRF path, because a victim's browser sends the
*target's* Host to the target and the attacker cannot change it, and the token is
the real gate regardless.

### Publishing and resuming prices are owner-only, server-side

```
editor POST /admin/rates/publish (valid CSRF)  -> 403 "Only an owner can do that."
editor POST /admin/hold  on=0 (resume)          -> 403 "Only an owner can show prices again."
editor POST /admin/hold  on=1 (hold)            -> allowed (any admin may take prices off)
```

`Guard::owner()` re-checks the role after the session and CSRF, and the hold's
resume half re-checks it inside the one handler because the form is one form.
Publish is the only publish route; there is no admin-management route in
`routes.php` at all (admins are created only by `bin/seed.php` on the CLI), so
there is nothing for an editor to escalate into. Everything else in the rates
editor is an editor's work by design (edit, save, add/remove/sort/fill/bulk,
restore, discard, test, export, hold-on), matching `CLAUDE.md`'s model.

A double-published or replayed publish is a no-op: `publishVersion` re-bases the
draft onto the new version, so `getDraft()` afterwards equals live and the next
publish reports "nothing to publish" (`Diff::count === 0`). The 12-hour
replayable CSRF token therefore cannot force a duplicate release.

### The price hold leaks no price anywhere

With the hold on, scraping every public surface:

```
home / services / about / contact / faq : 0 hits for data-from / "total" / "first" / "grid" / priceRange
JSON-LD priceRange (home)               : "" (empty, suppressed)
404 page                                : 0 price hits
response headers                        : no price in any header
the calculator rate blob                : ABSENT (HomeView builds HoldData, not CalculatorData)
```

The only blob emitted under hold is `#speedat-hold`, and its keys are
`companyName, whatsapp, message, maxKg, destinations, flagsBase, flags` — names
and a weight *limit*, no `settings`, no `rates`, no currency, no price. `HoldData`
is a separate class from `CalculatorData` "so that no price reaches the browser
is true by construction", and `Board`/`BoardView` skip `Engine::priceAll`
entirely while held. The CSV export is admin-only (anonymous → 302) and streams
the admin's own draft, which the admin may already see — it is not a hold leak.

### The 2FA code is single-use and the race is safe — real owner secret

```
$ php totp_replay.php
first verifyCode  = true
replay verifyCode = false                      <-- same code, immediately: refused
race A=true B=false (exactly one true is correct)
```

`Admins::verifyCode()` = `checkCode()` then `spendStep()`, and `spendStep()` is
one atomic `UPDATE … WHERE totp_last_step IS NULL OR totp_last_step < :step`, so
two simultaneous posts of the same digits cannot both win. A wrong code advances
nothing, and the `login_code` bucket increments on every attempt (right or
wrong) before the check. Enrolment refuses to overwrite an enabled factor and
disabling requires a current code; every 2FA state change starts a fresh session
(`signIn` calls `start()`, which mints a new token and deletes the old row).

### 2FA brute-force is bounded by the first factor

`code()` rate-limits `login_code` at 6 tries per **pending login** per 15 min,
and a fresh pending login exists only after a fresh first factor
(`PendingLogin::begin` is called from `firstFactorPassed`). The first factor is
capped at `login_account` 10/15 min and `login` per-address 40/15 min, both
**fail-closed** (neither is on `RateLimit::FAIL_OPEN = ['quotes','contact']`),
so the ceiling is ≈60 code guesses per 15 min against a 10⁶ space with a
narrow window — impractical. A forged `speedat_plg` cookie cannot invent a
pending login: `current()` requires `^[a-f0-9]{32}$` **and** a row under
`plogin:<sha256>` that only `begin()` (post-first-factor) writes.

### The login flow does not enumerate and does not fixate

Google and password both refuse with wording and timing that do not separate
"not an admin", "no password set" and "wrong password"; a valid Google account
that is not an admin gets the same refusal as a bad token. No session is created
until **both** factors pass — the first factor's result lives server-side in
`app_settings` under a hash of a cookie the browser holds, never a token in the
page — so there is no pre-auth session to fixate, and `start()` mints a fresh
token regardless. The reflected `?email=` and every `err=` code render through
`e()` / the `Notice` allow-list, so neither reflects script (`login.php:94`
`value="<?= e($page->email) ?>"`).

### The quote endpoint decides every number itself

```
forged body: 20 kg express to gb, "total":1, "billableG":400, "booked":true
stored row : billable_g=20000, total=47400, booked=1        <-- forgery ignored
wrong content-type (form)      -> 400
cross-site Origin (JSON)       -> 400
2 MB body                      -> 400 (refused before json_decode)
malformed JSON                 -> 400
```

`QuoteInput` refuses to carry `billableG`/`total` as facts (kept only as an
advisory tamper-trail) and `QuoteService` derives the billed weight, price,
add-on amounts and total from the published card with the same engine the browser
ran. Over the cargo threshold is refused, a held site answers 409 before parsing,
and every failure is the project envelope — never a 500 except 503 when the write
itself fails. The round-1 `billable_g` ceiling (finding 13) is held by
`QuoteInput::MAX_GRAMS = 1_000_000` well under the column's range.

### The ops endpoints check the token first, scope it, and echo nothing

```
/api/metrics        no token -> 401 {"code":"unauthorized","message":"Bad or missing token"}
/api/metrics        wrong    -> 401
/api/metrics        right    -> 200
/api/metrics ?token=…        -> 200 (query form, as the runbook uses)
metrics token used on /api/cron -> 401     (cron needs tokens.cron; tokens are not fungible)
/ops/opcache-reset  no/wrong -> 401
/health             no token -> {"ok":true}
/health ?token=metrics       -> {"ok":true,"php":…,"env":…,"database":…}  (detail behind the token)
```

`BearerToken::matches` is `hash_equals` over the whole `Bearer <secret>` header,
refuses an empty configured secret (unconfigured ⇒ closed), caps the header at
512 bytes, and never logs or echoes the supplied value. Crucially, both
`Metrics::respond()` and `Cron::respond()` check the token **before** any work —
`fromConfig()` only constructs objects, and the `COUNT(*)` queries live in
`render()`/`run()`, which run only after the 401 gate — so there is no
unauthenticated DB-work DoS. The metrics body is aggregate counts only, no PII.
The public `/health` reveals nothing; the detail is behind `Str::secureEquals`
(hash_equals, empty-guarded).

### Injection: logs, headers, response splitting, traversal

- **Log injection:** `Log::write` emits one `json_encode` per line, so a newline
  in any reflected value is encoded as `\n` inside the JSON string and cannot
  start a new record; field names matching `pass|secret|token|…` are redacted and
  driver messages have the offending bytes stripped (round-1 finding 20 path).
- **Header injection / response splitting:** `Response::header()` strips CR and
  LF from every value, and `Location` additionally goes through
  `sameOriginPath()`, which refuses a non-`/` start, any `\x00-\x20`/`\x7F`, a
  leading `//`, any backslash, and >2000 bytes (round-1 finding 1 fixed).
  `Notice::url()` only ever emits known integer/flag/allow-listed codes through
  `http_build_query`, so no name, sentence or newline reaches a redirect. A CRLF
  in a route parameter (`SH-%0d%0a Set-Cookie:…`) produced a plain 404 with no
  injected header.
- **CSV filename:** `Csv::filename` → `Str::slug`, which reduces any input to
  `[a-z0-9-]{0,40}`; proven with CRLF/quote/traversal/NUL payloads, all of which
  collapse to safe tokens (`"…\r\nSet-Cookie: a=b"` → `onmouseover-x-set-cookie-a-b`).
- **Path traversal:** the only route parameter in the whole table is the shipment
  `{id}`, validated by `ShipmentId::normalise` against `^SH-[…]{8}$`; the router
  decodes exactly once (round-1 finding 3 fixed) and `{name}` never matches a
  slash. No route parameter is ever used to open a file.

### Admin output is escaped by construction (no stored XSS)

Every customer-controlled value on every admin screen — lead name/phone/message,
shipment customer/receiver/carrier/tracking/notes, timeline notes, the CSV in
the editor — is printed through `e()` (`inbox.php`, `shipments.php`,
`shipment.php`, `shipment-new.php`). The editor's CSV copy target is a
`<pre id="rates-csv"><?= e($page->csv) ?></pre>`, not the raw-text `<script>`
that round-1 item 25 warned about, so `</script>`/`</pre>` in a destination name
is HTML-escaped — that handoff item is resolved. No admin screen embeds a
customer-data JSON blob. The only raw `<?=` in the admin templates are
`$this->partial(...)`, booleans and fixed class/attribute fragments.

### Error paths leak nothing

```
404 (html)  -> site 404 page, status 404, no path/query/SQL/trace in the body
404 (/api)  -> {"error":{"code":"not_found","message":"That page does not exist."}}
405         -> 405 with Allow: GET
419/400/429 -> the envelope or the designed page, one safe sentence each
```

`ErrorPage` logs an `HttpError`'s `detail` (and only that) to the file and
renders one fixed sentence; a caught `Throwable` passes `null`, so nothing about
it is shown, and `Log::exception` sends the trace to the file only. The requested
path is not reflected into the nav of the error page (`Page::forError` passes an
empty nav path). No status produced a stack trace, a file path, a query or a
driver message.

### Cookies

Every cookie carries `HttpOnly; SameSite=Lax; path=/` with no opt-out, and
`Secure` whenever the request is https **or** the environment is production
(`Cookies::forRequest`). Observed on the live dev server (http, so `Secure`
correctly absent):

```
Set-Cookie: speedat_ct=…; Max-Age=86400; path=/; HttpOnly; SameSite=Lax
Set-Cookie: speedat_flash=…; Max-Age=300; path=/; HttpOnly; SameSite=Lax
```

The session cookie is 32 random bytes and the table stores only its SHA-256; the
pending-login cookie the same. `__Host-` prefixing remains the one recorded
gap (round-1 finding 16), unchanged and out of round 2's scope.

---

## Not verified here

- Round 1's owned surfaces (`Core/**`, `Pricing`, `Auth`, migrations,
  `.htaccess`, shipped JS, release builder) beyond the caller-side re-checks
  noted above.
- Anything that needs the real host: whether LiteSpeed honours `.htaccess`
  header/`php_value` directives, `open_basedir`, OPcache behaviour, and the
  MySQL connection ceiling under load. All marked **VERIFY** in
  `hostinger-constraints.md`; none settleable from this machine. (One local-only
  artefact: the PHP built-in dev server answers a raw `..%2f` path with its own
  plain-text `400 Bad request` before the app is reached — a dev-server quirk,
  not app behaviour; the app itself normalises `..` and 404s, and never uses the
  path to open a file.)
- Lighthouse and the full Playwright flow walk (quality-bar §3 items 2 and 5).
- The Google/Firebase sign-in against the real Google certificates (no project
  configured locally; `allow_password_login` is the exercised path).
