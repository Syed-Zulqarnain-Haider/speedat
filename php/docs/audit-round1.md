# Audit round 1 — the finished surfaces

Adversarial review of the parts of the PHP port that are done and stable, scored
against [`quality-bar.md`](./quality-bar.md) §2 and the host's reality in
[`hostinger-constraints.md`](./hostinger-constraints.md).

**Surfaces audited:** `app/Core/**`, `app/Domain/Pricing/**`, `app/Domain/Auth/**`,
`db/migrations/0001_init.sql` (+ `0002`), `public/.htaccess`,
`public/assets/js/*.js`, `bin/build-release.php`, `deploy/**`.

**Method.** Every claim below was produced by running something, not by reading a
comment. Nothing in the repository was modified. The probes live in the session
scratchpad (`scratchpad/audit/`, `scratchpad/pw/`) and are quoted verbatim where
they are the evidence:

| probe | what it does |
|---|---|
| `forge-firebase.php` | generates an RSA key pair + self-signed cert, mints **35 forged ID tokens** (alg none, HS256 keyed with the public half, wrong `aud`/`iss`, expired, tampered `kid`, type confusion) and runs each through the real `FirebaseToken` |
| `core-probe.php` | Router decoding, `Response::redirect`, `Request::ip()` under spoofed headers, input caps, invalid UTF-8, CSRF token/origin matrix, `View::resolve` traversal |
| `db-probe.php` | the real local MariaDB 11.4.13 with the project's own `Db`, against `leads`-shaped tables: what `STRICT_TRANS_TABLES` turns into a 500 |
| `pricing-probe.php` | `Engine::computeWeights` against settings straight out of a published JSON document with nothing clamped |
| `pw/redirect-probe.cjs`, `pw/openredirect2.cjs` | Chrome (Playwright 1.58) following the `Location` headers the app actually emits |
| a live copy | `php -S 127.0.0.1:18777 -t public public/router.php` on the real tree, probed with curl and Chrome |
| a copied tree | `bin/build-release.php` run against a copy of `php/` with canary secrets planted under crafted filenames |

Severities: **blocker** = must not ship. **major** = a real hole or a 500 a
visitor can cause. **minor** = a weakness, a drift from the stated bar, or a
latent footgun.

---

## Summary, ranked

| # | sev | where | what |
|---|---|---|---|
| 1 | blocker | `app/Core/Response.php:49-56` (reached from `app/Http/Kernel.php:120`) | **Open redirect on the canonical 301.** An unauthenticated `GET` sends a visitor to any host. Proven in Chrome. |
| 2 | blocker | `bin/build-release.php:157-160` | **No release can be built.** The builder refuses its own tree and deletes the archive. |
| 3 | major | `app/Core/Router.php:97` | Route parameters are **decoded twice** — `../`, `/` and NUL bytes get into a `{param}` the regex forbade them from. |
| 4 | major | `app/Core/Caps.php`, `app/Core/Request.php:191` | **Invalid UTF-8 and over-long TEXT are a 500**, not a polite refusal. 12 bytes in a contact-form field does it. |
| 5 | major | `app/Domain/Pricing/Engine.php:107` + `Settings.php:55` | `volumetricDivisor: 0` in the rate document → `DivisionByZeroError` → 500 on every priced page. |
| 6 | major | `app/Core/Money.php:52-55`, `app/Domain/Pricing/Piece.php` | A **non-finite weight silently prices at the cheapest rate**. `kgToG(INF) === 0`. |
| 7 | major | `bin/build-release.php:139`, `deploy/verify-release.php:161` | The `config.php` / `*.local.php` guards are **case-sensitive in both checkers**. A file containing the whole config shipped in an archive I built. |
| 8 | major | `db/migrations/0001_init.sql:167,172,221,241,57` | Five `TEXT NOT NULL` columns with **no DEFAULT**: omit one and STRICT mode makes it a 500. |
| 9 | major | `app/Domain/Auth/PasswordSetupToken.php` | The **`password_setup` table does not exist** in any migration. Single-use and expiry have nowhere to live. |
| 10 | major | `app/Core/RateLimit.php:80-84` | The limiter **fails open**, including on login, and there is no per-account counter. §2 requires per-IP **and** per-account. |
| 11 | major | `app/Core/Request.php:162-179` | With `trust_proxy` on, `CF-Connecting-IP` is believed from **anybody** — no proxy-IP allow-list. |
| 12 | major | `bin/build-release.php:170`, `deploy/verify-release.php:199-203` | Any `.sql` under `db/migrations/` ships. A dump named `9999_customer_dump.sql` shipped in an archive I built. |
| 13 | major | `Engine` ↔ `db/migrations/0001_init.sql:193` | Engine output is unbounded; `quotes.billable_g` is `INT UNSIGNED` (max 4,294,967 kg). |
| 14 | minor | `app/Core/Session.php:93-98` | A catch-all mislabels **any** insert failure as "csrf_secret column missing" and silently downgrades the CSRF secret. |
| 15 | minor | `app/Core/App.php:126`, `:116` | `X-Frame-Options: SAMEORIGIN` and `style-src 'unsafe-inline'` both differ from §2 as written. |
| 16 | minor | `app/Core/Csrf.php:128-143` | `Origin: null` and an absent `Origin` are allowed; the token is replayable for 12 h; the anonymous secret is a plain cookie with no `__Host-` prefix. |
| 17 | minor | `public/.htaccess:59` | The deny list is extension-driven, so `index.php~`, `.save`, `.rej`, `.orig~` are served as source. |
| 18 | minor | `public/.htaccess:116` vs `:135` | `AddType application/font-woff2` vs `ExpiresByType font/woff2` — **fonts get a 0-second expiry**. |
| 19 | minor | `app/Core/Paths.php:110-119` | A `paths.logs` typo will happily put the log directory inside the web root. |
| 20 | minor | `app/Core/Log.php:62-70` | The driver message is logged verbatim; MySQL 1366 messages quote the offending bytes. |
| 21 | minor | `public/assets/js/calculator.js:68` | A dead `html:` branch on `h()` — an `innerHTML` sink waiting for its first caller. |
| 22 | minor | `app/Domain/Auth/SecretBox.php:69-78` | AES-GCM with **no AAD**: a ciphertext is not bound to the row it sits in. |
| 23 | minor | `app/Core/helpers.php:36`, `app/Core/Mail.php:132`, `app/Domain/Auth/Totp.php:100`, `app/Core/Db.php:101` | Four small footguns, listed in full below. |
| 24 | minor | `public/assets/dev-preview.html`, `public/router.php` | Reachable on the dev server and on any staging copy served from `php/public`. They do **not** ship — verified. |
| 25 | note | `app/Http/QuoteApiController.php:100`, the admin CSV `<script type="text/plain">` | Two items outside this audit's ownership that the next round must pick up. |

---

## Blockers

### 1. Open redirect on the canonical 301 — `app/Core/Response.php:49-56`

```php
public static function redirect(string $location, int $status = 303): self
{
    $safe = str_starts_with($location, '/') && !str_starts_with($location, '//')
        ? $location
        : '/';

    return (new self($status, ''))->header('Location', $safe);
}
```

Two defects in six lines.

**(a) The guard only knows about `//`.** Per the WHATWG URL standard, after the
first `/` a **backslash is a path separator too** for http(s). `/\evil.example`
therefore parses as the authority `evil.example`, and every current browser
resolves it that way.

**(b) The guard runs before the sanitiser.** `header()` strips CR and LF
*after* `redirect()` has approved the string, so `"/\n/evil.example"` passes the
check as "starts with one slash" and is then emitted as `//evil.example`.

`app/Http/Kernel.php:120` feeds `$request->path` — raw, attacker-chosen — into
this method. It defends itself against control characters
(`preg_match('/[\x00-\x1F\x7F]/')`, which closes (b) on that route) but not
against the backslash.

**Evidence — the real class:**

```
redirect("/\n/evil.example/")  emits Location: "//evil.example/"
redirect("/\t/evil.example/")  emits Location: "/\t/evil.example/"   (browser strips the tab -> //evil.example/)
redirect("/\\evil.example")    emits Location: "/\\evil.example"
redirect("//evil.example/")    emits Location: "/"                   (the only form the guard catches)
```

**Evidence — the live application** (`php -S … -t public public/router.php`, real
config, real routes):

```
$ curl -s -i --path-as-is 'http://127.0.0.1:18777/%5Cevil.example/'
HTTP/1.1 301 Moved Permanently
Location: /\evil.example
```

**Evidence — Chrome following it** (`scratchpad/pw/openredirect2.cjs`, an
attacker server on `127.0.0.2:80`):

```
GET http://127.0.0.1:18777/%5C127.0.0.2/
    301 http://127.0.0.1:18777/%5C127.0.0.2/  ->  Location: /\127.0.0.2
    landed on: http://127.0.0.2/   [ATTACKER SITE]
    host changed: *** YES - OFF-SITE ***
```

**What it achieves.** `https://speedat.com.pk/%5Cattacker.example/` is a link
that carries the real brand and the real domain and lands on the attacker's
page. WhatsApp is this business's main channel and it renders the speedat.com.pk
host in the preview. It needs no session, no form and no JavaScript.

**Fix** (in `Response::redirect`, so every future caller inherits it):

```php
public static function redirect(string $location, int $status = 303): self
{
    // Sanitise FIRST, then decide: the old order approved "/x" and then let
    // header() strip the newline out of "/\n/x" to leave "//x".
    $clean = str_replace(["\r", "\n", "\t", "\0"], '', $location);

    // One leading slash, and the next character may not be another
    // separator. The URL standard treats a backslash as a slash after the
    // first one, so "/\evil.example" is an authority to every browser.
    $ok = $clean !== ''
        && $clean[0] === '/'
        && ($clean[1] ?? '') !== '/'
        && ($clean[1] ?? '') !== '\\';

    return (new self($status, ''))->header('Location', $ok ? $clean : '/');
}
```

I ran that replacement against the same inputs (`scratchpad/audit/fixcheck3.php`)
so the fix is not a suggestion either:

```
input                          current guard            proposed guard
--------------------------------------------------------------------------------
"/admin"                       "/admin"                 "/admin"
"/admin?x=1"                   "/admin?x=1"             "/admin?x=1"
"/a/b/c"                       "/a/b/c"                 "/a/b/c"
"/"                            "/"                      "/"
""                             "/"                      "/"
"//evil.example"               "/"                      "/"
"/\evil.example"               "/\evil.example"         "/"
"/\\evil.example"              "/\\evil.example"        "/"
"/\/evil.example"              "/\/evil.example"        "/"
"/\n/evil.example"             "//evil.example"         "/"
"/\t/evil.example"             "/\t/evil.example"       "/"
"/\r//evil.example"            "///evil.example"        "/"
"https://evil.example"         "/"                      "/"
"/\"                           "/\"                     "/"
"/x\0y"                        "/x\0y"                  "/xy"
```

Those fifteen lines are the test to add to `tests/Core/ResponseTest.php`.
`Domain\Seo\Url::clean()` should reject a segment containing a backslash as well,
so the 301 is never built in the first place.

---

### 2. `bin/build-release.php` cannot build the current tree

Run it against a verbatim copy of `php/`:

```
Speedat - release builder
========================================================================

THE ARCHIVE FAILED ITS OWN CHECKS AND HAS BEEN DELETED:

  * db/migrations/0002_session_csrf_secret.sql is named like a credential file
```

`refusalReason()` at line 157-160 applies a credential-name heuristic to every
non-`.php` file:

```php
if ($extension !== 'php'
    && preg_match('/(service.?account|adminsdk|credential|secret|private.?key)/i', $base) === 1) {
    return 'is named like a credential file';
}
```

`0002_session_csrf_**secret**.sql` matches. The archive is written, verified,
found wanting, and `@unlink`ed. **There is no deploy artefact today**, and the
filename that breaks it is the migration the CSRF fix depends on.

`deploy/verify-release.php:176` has the same heuristic **without** `secret` in
it, so the two checkers disagree about the same file — which is how this got
committed.

**Fix.** The `.sql` rule at line 170 is already precise about migrations, so
exempt them from the name heuristic and tighten the migration rule at the same
time:

```php
// db/migrations/ has its own precise rule below; a schema file named
// "..._csrf_secret.sql" is not a credential store.
if ($extension !== 'php'
    && !str_starts_with($entry, 'db/migrations/')
    && preg_match('/(service.?account|adminsdk|credential|secret|private.?key)/i', $base) === 1) {
    return 'is named like a credential file';
}

// And only real migrations may live there - see finding 12.
if (str_starts_with($entry, 'db/migrations/')
    && preg_match('/^\d{4,}_[a-z0-9_]+\.sql$/', $base) !== 1) {
    return 'is a file in db/migrations/ that is not a numbered migration';
}
```

Then make the two lists one thing: `deploy/verify-release.php` should require
`bin/build-release.php`'s `refusalReason()` rather than restate it, or a test
should assert the two agree on a fixture list of names.

---

## Major

### 3. Route parameters are decoded twice — `app/Core/Router.php:97`

`Request::fromGlobals()` already decodes (`Request.php:55`):

```php
$path = is_string($raw) && $raw !== '' ? rawurldecode($raw) : '/';
```

and `Router::match()` decodes the captured group again:

```php
$params[$name] = rawurldecode($m[$i + 1] ?? '');
```

The class docblock promises "A `{name}` matches one path segment and never a
slash, so a route cannot be tricked into matching further than it was written
to." The second decode is what breaks that promise.

**Evidence** (`core-probe.php`, route `/doc/{slug}`):

```
/doc/%2e%2e%2f%2e%2e%2fconfig  -> 404 (the single-encoded form is caught)
/doc/%252e%252e%252fconfig     -> Request::path='/doc/%2e%2e%2fconfig'  param='../config'
/doc/a%252fb                   -> Request::path='/doc/a%2fb'           param='a/b'
/doc/x%2500.php                -> Request::path='/doc/x%00.php'        param="x\0.php"
```

**What it achieves.** Any handler that puts a route parameter into a path, a
filename, a cache key or a redirect gets `../` and `/` it was told it could not
receive. The NUL is the sharper one: PHP 8 throws a `ValueError` out of
`is_file()`/`fopen()` on a path containing `\0`, so `GET /doc/x%2500` is an
unauthenticated 500 on any route that touches the filesystem — and in older
string handling a NUL truncates. `Domain\SiteDoc` and the SEO layer are the
consumers to check.

**Fix.** Decode exactly once. The router should not decode at all:

```php
// Request::path is already decoded (Request::fromGlobals); decoding again
// gives a {param} the "one segment, never a slash" regex just excluded.
$params[$name] = $m[$i + 1] ?? '';
```

and reject the hostile shapes at the front door, in `Request::fromGlobals()`
right after the decode:

```php
if (str_contains($path, "\0")) {
    $path = '/';
}
```

Pin both in `tests/Core/RouterTest.php` with the four lines above.

---

### 4. Invalid UTF-8 and over-long TEXT are a 500 — `app/Core/Caps.php`

`Caps`'s own docblock states the goal exactly: *"a name one character too long is
… an exception out of PDO, which becomes a 500 page, which becomes 'the site is
broken' from someone who typed a long company name into a contact form."* It
closes the length half and leaves two holes.

**(a) Encoding is never checked.** `Request::post()` → `Str::limit()` →
`Caps::problems()` all pass invalid UTF-8 through untouched, and utf8mb4 +
`STRICT_TRANS_TABLES` makes it an error.

**Evidence** (`db-probe.php`, real MariaDB 11.4.13, the project's own `Db`, a
`leads`-shaped table):

```
Request::post() gave 10 bytes, valid UTF-8: NO
Caps::problems(leads, [name]) says: []
PDOException name = invalid UTF-8 straight from Request::post()
   -> SQLSTATE[22007]: 1366 Incorrect string value: '\xC3( Kha...' for column `...`.`name` at row 1
PDOException message (TEXT) = invalid UTF-8
   -> SQLSTATE[22007]: 1366 Incorrect string value: '\xC3( Kha...' for column `...`.`message`
```

The payload is `"Ali\xC3\x28 Khan"` — twelve bytes of `curl --data-binary` at the
public contact form.

**(b) `Caps` lists no TEXT column, and `Request::post()` counts characters.**
`Caps::WIDTHS` is VARCHAR-only by design, and the docblock says TEXT is "capped
by the request caps in `Request` instead" — but that cap is in **characters**
while `TEXT` is 65,535 **bytes**. The default 5,000-character cap is safe
(20,000 bytes worst case); any route that raises it above 16,383 is not:

```
TEXT limit check: 20000 chars / 80000 bytes
PDOException message = 20000 emoji (80000 bytes into a TEXT column)
Caps::problems for that message: []   <-- no cap exists for TEXT columns
```

For contrast, the length rule that **is** implemented works exactly as designed:

```
name = 190 emoji (VARCHAR(190) utf8mb4)   OK
name = 191 chars                          1406 Data too long
Caps::problems for 191 chars: {"name":"Name is too long: 190 characters at most, and that is 191."}
```

**Fix.** Two changes, both small.

In `Request::post()`/`query()`/`postList()`, make the value well-formed before it
is capped — the request layer is the one place every field passes through:

```php
$s = (string) $v;
if (!mb_check_encoding($s, 'UTF-8')) {
    // Not a refusal: a broken byte is a broken client, and the field's own
    // validation will still see whatever is left.
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
}
return Str::limit(trim($s), $max);
```

and add the encoding check to `Caps::problems()` so a field that is still
malformed is refused with a sentence rather than an exception:

```php
if (!mb_check_encoding($value, 'UTF-8')) {
    $problems[$column] = self::label($key) . ' contains characters we could not read. Please retype it.';
    continue;
}
```

Then extend `Caps::WIDTHS` with the TEXT columns as **byte** budgets
(`leads.message`, `leads.notes`, `shipments.notes`, `shipment_events.note`,
`versions.summary` — 60,000 bytes is a safe 65,535), and have `problems()` count
`strlen()` for those. `tests/Core/CapsTest.php` already reads `0001_init.sql` for
VARCHAR widths; teach it to read `TEXT` too so the new entries cannot drift
either.

---

### 5. `volumetricDivisor: 0` is a 500 — `app/Domain/Pricing/Engine.php:107`

`Settings::fromArray()` reads every number straight out of the published JSON
with `Num::jsNumber()` (`Settings.php:55-61`), which clamps nothing — by design,
because it is a faithful port of JavaScript's `Number()`. `computeWeights()` then
divides by one of them:

```php
$v = ($dims[0] > 0 && $dims[1] > 0 && $dims[2] > 0)
    ? (int) Money::jsRound((($dims[0] * $dims[1] * $dims[2]) / $settings->volumetricDivisor) * 1000)
    : 0;
```

**Evidence** (`pricing-probe.php`):

```
OK       control: defaults                    billableG=5000 chargeG=4800 volG=4800
INVALIDA stepKg = 0                           The weight step must be greater than 0 kg.
         ^ InvalidArgumentException at Engine.php:88
INVALIDA stepKg = "" (empty string in JSON)   The weight step must be greater than 0 kg.
DIVISION volumetricDivisor = 0                Division by zero
         ^ DivisionByZeroError at Engine.php:107
DIVISION volumetricDivisor = ""               Division by zero
         ^ DivisionByZeroError at Engine.php:107
OK       volumetricDivisor = "x" (NaN)        billableG=1000 chargeG=1000 volG=0
```

Note the asymmetry: `stepKg` **is** guarded at `Engine.php:88`;
`volumetricDivisor` is not. Both still escape as uncaught throwables.

**What it achieves.** An admin who clears the volumetric divisor box, or an
imported rate document with `"volumetricDivisor": ""`, takes every priced page to
a 500 for every visitor who enters dimensions. It is not an attack, it is one
empty field.

**Fix.** Clamp where the settings are built, so no caller has to remember —
`Settings::fromArray()`:

```php
/** A divisor or a step that is not a positive finite number is not a setting. */
private static function positive(mixed $v, float $fallback): float
{
    $n = Num::jsNumber($v);

    return is_finite($n) && $n > 0 ? $n : $fallback;
}
```

applied to `volumetricDivisor`, `stepKg`, `firstKg` and `roundTo`
(`Money::roundTo` already falls back to 1 on its own, which is the shape to
copy). Keep the `Engine.php:88` guard as the belt, and add the four cases above
to the vector tests.

---

### 6. A non-finite weight prices at the cheapest rate — `app/Core/Money.php:52-55`

```php
public static function kgToG(float $kg): int
{
    return (int) self::jsRound($kg * 1000);
}
```

`jsRound()` returns `INF` unchanged (`Money.php:32-36`, deliberate), and
`(int) INF` is `0` in PHP.

**Evidence:**

```
jsNumber("1e999") = Infinity     kgToG = 0
jsNumber("1e400") = Infinity     kgToG = 0
jsNumber("9e307") = 9.0e+307     kgToG = 0
kgToG(NAN) = 0
fmtMoney(NAN,"PKR") = PKR nan
fmtMoney(INF,"PKR") = PKR inf
```

and through the engine:

```
kg = INF as a string "1e999"   billableG=500 chargeG=0 volG=0
```

`billableG = 500` is the first-slab minimum: **the cheapest price in the card.**

`Piece::fromArray()` reads the field with the same `Num` helpers and has no
finiteness check, so `{"rows":[{"kg":"1e999"}]}` reaches the engine if the route
does not stop it first.

**What it achieves.** A quote for the minimum price on an arbitrarily heavy
shipment, and a `quotes` row that says so. Money, not availability — which is
why it outranks the 500s below it. Whether it is reachable today depends on
`Domain\Quotes\QuoteInput` (another agent's file, not audited here): round 2
must confirm. The engine should refuse it regardless, because "the caller checks"
is how this class of bug survives a refactor.

Two neighbours in the same table, both unbounded rather than wrong:

```
kg = 1e18            billableG=3875820019684212500
qty = 1e9 pieces     billableG=1000000000000
```

**Fix.** Refuse non-finite input where a `Piece` is built, and cap it:

```php
// Pricing/Piece.php - a weight that is not a finite number is not a weight.
private static function read(array $a, string $key): float
{
    $n = Num::field($a[$key] ?? null) ?? NAN;

    return is_finite($n) ? $n : NAN;   // NAN is already "the form left it empty"
}
```

`NAN` is the right sentinel because `computeWeights` already skips a row whose
`kg` is not a number, and `Num`'s docblock defines NAN as exactly that state. Add
an upper bound too — `min($n, 100000.0)` for kg, `min($qty, 1000)` for qty — so
finding 13 cannot happen either.

---

### 7. The `config.php` / `*.local.php` guards are case-sensitive — proven to leak

`bin/build-release.php:139`:

```php
if ($base === 'config.php' || str_ends_with($base, '.local.php')) {
    return 'is real configuration (secrets), not the sample';
}
```

`deploy/verify-release.php:161` restates it with the same flaw:
`'no *.local.php override' => '#\.local\.php$#'` — no `i` modifier.

**Evidence.** I copied the tree, wrote a `config/config.php` containing
`CANARY-APP-KEY-ZZZ` and `CANARY-DB-PASSWORD`, planted three crafted filenames,
and built. The builder reported success:

```
Verified: opens, every entry matches its source, and nothing on the deny
list is inside it.
```

Then I searched the finished archive for the canary:

```
entries carrying the canary secret:
  app/Core/Overrides.php (99 bytes)
  app/Core/Settings.LOCAL.php (99 bytes)
  db/migrations/9999_customer_dump.sql (99 bytes)

named probes present in archive?
  config/config.php                       absent      <- the exact name IS caught
  app/Core/Overrides.php                  PRESENT
  app/Core/Settings.LOCAL.php             PRESENT
  db/migrations/9999_customer_dump.sql    PRESENT
  public_html/assets/dev-preview.html     absent
  public_html/router.php                  absent
```

`Settings.LOCAL.php` is the finding: a file whose entire content is the
production config, under a name the guard was written to catch, in a release the
builder declared clean. (`Overrides.php` is the documented trade-off — the `.php`
credential heuristic is deliberately off, for the good reason given at line
152-156 — but see the fix.) `Config.php` could not be tested here because this
machine's filesystem is case-insensitive and would not let it coexist with
`config.php`; the code is case-sensitive, so a build on the Linux staging host
would ship it.

**Fix.** Normalise before matching, in both files:

```php
$lower = strtolower($base);
if ($lower === 'config.php' || str_ends_with($lower, '.local.php')) {
```

and `'#\.local\.php$#i'`, `'#(^|/)config\.php$#i'` in `verify-release.php`.

Then close the class of bug rather than the instance: a **content** check costs
one `str_contains` per file and catches all three canaries and anything else
shaped like the config, which no name rule ever will.

```php
// After collecting, before writing: no shipped file may contain the live
// configuration. Compared against config/config.php's own values, so this
// cannot be fooled by a rename, a copy or a symlink.
$live = is_file($root . '/config/config.php') ? (string) file_get_contents($root . '/config/config.php') : '';
foreach (['app.key' => 'key', 'db.pass' => 'pass'] as $label => $needle) { /* extract the literals */ }
foreach ($files as $entry => $absolute) {
    $body = (string) file_get_contents($absolute);
    foreach ($secretLiterals as $secret) {
        if ($secret !== '' && strlen($secret) > 8 && str_contains($body, $secret)) {
            fwrite(STDERR, "$entry contains a value from config/config.php\n");
            exit(EXIT_FAILED);
        }
    }
}
```

**Related, same file.** `addTree()` (line 238) uses `RecursiveDirectoryIterator`
with no link handling, and `$item->isFile()` is true for a symlink to a file, so
`addFile()` reads through it — and the CRC check at line 514 compares against the
same link, so it passes. A link named like ordinary source ships the file it
points at. I could not create one here (`mklink` needs elevation on this box and
a directory junction is caught by the basename rule), so this is a code-level
finding, not a demonstrated one. Add `if ($item->isLink()) { $skipped[] = …; continue; }`
and assert `str_starts_with((string) realpath($absolute), $root)`.

---

### 8. Five `TEXT NOT NULL` columns with no DEFAULT — `db/migrations/0001_init.sql`

`leads.message:167`, `leads.notes:172`, `shipments.notes:221`,
`shipment_events.note:241`, `versions.summary:57`. `Db::insert()` builds the
column list from the data map it is given, so a route that omits one of these
produces an error, not a default.

**Evidence** (`db-probe.php`):

```
OK           control: every NOT NULL column supplied
PDOException message omitted   -> SQLSTATE[HY000]: 1364 Field 'message' doesn't have a default value
PDOException notes omitted     -> SQLSTATE[HY000]: 1364 Field 'notes' doesn't have a default value
OK           message => null   (NULL is refused differently; an explicit '' is what the app must send)
```

The schema comment at line 165 is aware of it — *"TEXT has no DEFAULT on every
supported engine, so the application always supplies `''` rather than relying on
one"* — which makes the guarantee a convention, and a convention is what breaks
when the next route is written.

**Fix.** MariaDB 11 and MySQL 8 both accept a DEFAULT on TEXT
(`DEFAULT ('')` — the parenthesised expression form). Add a migration:

```sql
ALTER TABLE leads           MODIFY message TEXT NOT NULL DEFAULT ('');
ALTER TABLE leads           MODIFY notes   TEXT NOT NULL DEFAULT ('');
ALTER TABLE shipments       MODIFY notes   TEXT NOT NULL DEFAULT ('');
ALTER TABLE shipment_events MODIFY note    TEXT NOT NULL DEFAULT ('');
ALTER TABLE versions        MODIFY summary TEXT NOT NULL DEFAULT ('');
```

If the host refuses that (verify on staging first — it is exactly the kind of
thing hPanel's MariaDB build can differ on), the fallback is a test: enumerate
every `NOT NULL` column without a default from `0001_init.sql` and assert each
repository's insert map names it. That is the same trick `CapsTest` already
plays, so the machinery exists.

---

### 9. `password_setup` is documented but does not exist

`app/Domain/Auth/PasswordSetupToken.php:26` specifies the storage and the atomic
spend:

```sql
UPDATE password_setup SET used_at = NOW()
 WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
```

There is no such table:

```
$ grep -rn "password_setup" --include=*.php --include=*.sql .
./app/Domain/Auth/PasswordSetupToken.php:26:   UPDATE password_setup SET used_at = NOW()
```

`0001_init.sql` has `admins.password_hash` and nothing else; `0002` adds only
`sessions.csrf_secret`. The class's logic is correct and tested in isolation
(finding list below), but single-use, expiry and the atomic spend are all
properties of a row that has no home — so the reset flow either does not exist
yet or will fail at its first query.

§2 requires *"Login, password-reset and 2FA endpoints are rate-limited … with a
uniform error message and no user enumeration"*, which presumes the flow exists.

**Fix.** A migration, and index it the way it is looked up:

```sql
CREATE TABLE IF NOT EXISTS password_setup (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email      VARCHAR(190) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME              DEFAULT NULL,
    issued_by  VARCHAR(190) NOT NULL DEFAULT '',
    ip_hash    CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    PRIMARY KEY (token_hash),
    KEY password_setup_email_idx (email, created_at),
    KEY password_setup_expiry_idx (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Sweep it in `bin/cron.php` beside `Session::sweep()`, and add `password_setup.email`
and `.issued_by` to `Caps::WIDTHS` so finding 4 does not arrive with it.

---

### 10. The rate limiter fails open, including on login — `app/Core/RateLimit.php:80-84`

```php
} catch (\Throwable $e) {
    Log::exception('rate_limit_unavailable', $e, ['bucket' => $bucket]);

    return new self(true, 0, $limit, $retryAfter);
}
```

The reasoning in the docblock is sound for the quote form — *"a rate limiter that
takes the site down when it breaks has done more damage than the traffic it was
meant to shape"* — and it is a faithful port of `limits.ts`. It is the wrong
trade for the login bucket, where the thing being rate-limited is password
guessing.

The failure is not hypothetical on this host. `hostinger-constraints.md` §4 puts
the ceiling at **50 MySQL connections**, and calls it "the tightest number". Under
connection pressure, `Db::pdo()` throws, `hit()` returns `ok = true`, and the
login endpoint has no limit at all — exactly when the site is under load.

Second half of the same finding: §2 requires per-IP **and per-account** limits,
and there is no per-account counter. `hit()` takes an `$ip` string and hashes it
with `Ip::hash()`, so the primitive exists — nothing calls it with an account
key. A single attacker rotating IPs against one known admin address is
unlimited.

**Fix.** Give the caller the choice, and default the auth buckets to closed:

```php
/**
 * Buckets that must NOT let a request through when the counter is
 * unreachable. Anything protecting a credential belongs here: "the database
 * blinked" is not a reason to allow unlimited password guesses.
 */
private const FAIL_CLOSED = ['login', 'login_account', 'totp', 'password_reset'];
```

and in the catch:

```php
if (in_array($bucket, self::FAIL_CLOSED, true)) {
    return new self(false, $limit + 1, 0, $retryAfter);
}
```

Then add the per-account call beside the per-IP one at the login route, keyed on
the address rather than the IP — `RateLimit::enforce('login_account', …)` with
`Str::email($email)` in place of the IP, since `Ip::hash()` is a keyed hash of an
arbitrary string and does not care what it is given. `rate_limits.k` is
`VARCHAR(160)` and the key is `bucket + ':' + 32 hex` (measured: 38 characters
for `login`), so there is room.

---

### 11. `trust_proxy` trusts a header from anybody — `app/Core/Request.php:162-179`

```php
if (self::trustProxy()) {
    $cf = $this->header('cf-connecting-ip');
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
        return $cf;
    }
    ...
```

**Evidence** (`core-probe.php`, headers `CF-Connecting-IP: 5.6.7.8`,
`X-Forwarded-For: 1.2.3.4, 9.9.9.9`):

```
trust_proxy=false -> ip() = 127.0.0.1
trust_proxy=true  -> ip() = 5.6.7.8   (attacker-chosen: CF-Connecting-IP is a plain header)
```

The default (`false`) is right and the docblock's reasoning is right. The gap is
that turning the flag on trusts the header regardless of **who sent it**. Behind
Cloudflare the origin IP is discoverable, and a request straight to it carries
whatever `CF-Connecting-IP` the attacker chose — so every per-IP limit becomes
per-attacker-chosen-string, i.e. unlimited. Preferring `CF-Connecting-IP` over
`X-Forwarded-For` is correct (Cloudflare *appends* to XFF, so its left-most entry
is client-supplied even behind the proxy) and does not help here.

**Fix.** Trust the header only when `REMOTE_ADDR` is a proxy:

```php
/**
 * Whose forwarding headers we believe. `app.proxy_ips` holds CIDRs - the
 * Cloudflare ranges, or the load balancer. Empty means "believe nobody",
 * which is what trust_proxy=false already meant.
 */
private function fromTrustedProxy(): bool
{
    foreach (Config::arr('app.proxy_ips') as $cidr) {
        if (is_string($cidr) && self::inRange($this->remoteAddr, $cidr)) {
            return true;
        }
    }

    return false;
}
```

and require both: `if (self::trustProxy() && $this->fromTrustedProxy())`. Add
`app.proxy_ips` to `config.sample.php` with the Cloudflare ranges commented out
and a line saying that `trust_proxy` does nothing without it.

---

### 12. Any `.sql` in `db/migrations/` ships

`bin/build-release.php:170` allows it by directory, and lines 303-305 add
everything the glob finds without consulting `refusalReason()` at all:

```php
foreach (glob($root . '/db/migrations/*.sql') ?: [] as $migration) {
    $files['db/migrations/' . basename($migration)] = str_replace('\\', '/', $migration);
}
```

`deploy/verify-release.php:199-203` has the identical rule. Demonstrated in
finding 7: `db/migrations/9999_customer_dump.sql`, whose content was the config
file, shipped in an archive both checkers would call clean.

**What it achieves.** The comment at line 171 states the stake precisely — *"a
data dump of this site is the customer list"*. A dump saved into that directory
(which is where a developer debugging a migration would naturally put it) ships
to the host and, if the tree is ever extracted in the wrong place, is
downloadable.

**Fix.** The numbered-migration pattern in finding 2's snippet, in both files.

---

### 13. Engine output can exceed `quotes.billable_g`

`db/migrations/0001_init.sql:193` declares `billable_g INT UNSIGNED NOT NULL` —
4,294,967,295 g, i.e. 4,294 kg. `settings.maxKg` defaults to 70 kg, but
`computeWeights()` runs **before** any `maxKg` check, and it is unbounded
(finding 6's evidence: `billableG=3875820019684212500` for `kg=1e18`,
`1000000000000` for a million-piece quantity). MariaDB's response is not a
truncation:

```
REFUSED {"w":5000000000} -> SQLSTATE[22003]: 1264 Out of range value for column 'w' at row 1
```

so a quote whose billable weight overflows the column is a 500 at insert time,
not a refusal at input time. `QuoteApiController` documents "IT NEVER RETURNS
500" and catches `Throwable` into a 503, so today the visible symptom is a 503
and a `quote_log_failed` line rather than a stack trace — which is the right
behaviour for an unexpected error and the wrong behaviour for an input that
should have been refused with a sentence.

**Fix.** The `Piece` clamp from finding 6, plus `Caps`-style bounds for the
integer columns so the refusal happens in the domain: `billable_g` must fit
`INT UNSIGNED`, and `leads.weight_g` (same type, `:169`) too.

---

## Minor

### 14. `Session::start()`'s catch-all hides real failures — `app/Core/Session.php:93-98`

```php
try {
    Db::insert('sessions', $row + ['csrf_secret' => Str::token(32)]);
} catch (\Throwable) {
    Log::warn('session_csrf_secret_column_missing', ['at' => 'start']);
    Db::insert('sessions', $row);
}
```

The intent (survive a host that imported `0001` and not `0002`) is right; the
`\Throwable` is too wide. **Any** insert failure — a duplicate key, a too-long
`email` (finding 4), a full disk — is logged as a missing column and retried, and
the retry then produces a session whose CSRF secret is the weaker derived
fallback at `:258-262` that the docblock itself calls "the fallback and not the
design". The log line points at the wrong cause, so the real fault is invisible.

**Fix.** Catch only the column error and let everything else out:

```php
} catch (\PDOException $e) {
    // 1054 is ER_BAD_FIELD_ERROR: the column really is not there. Anything
    // else is a failure this method must not swallow.
    if (($e->errorInfo[1] ?? 0) !== 1054) {
        throw $e;
    }
    Log::warn('session_csrf_secret_column_missing', ['at' => 'start']);
    Db::insert('sessions', $row);
}
```

Same change in `sessionSecret()` at `:246-256`.

### 15. Two deliberate deviations from §2, both worth recording

- `app/Core/App.php:126` sends `X-Frame-Options: SAMEORIGIN`; §2 asks for `DENY`.
  `frame-ancestors 'none'` is also set and is what every current browser
  honours, so the practical exposure is nil — but `public/.htaccess:98` says
  `SAMEORIGIN` too, and on a request that dies before PHP sets its headers the
  `.htaccess` value is the only one. Make both `DENY`, or amend §2 and say why.
- `app/Core/App.php:116` sends `style-src 'self' 'unsafe-inline'`; §2 says "no
  `unsafe-inline`". The code's reasoning is correct and specific (a nonce on
  `style-src` makes `'unsafe-inline'` be ignored, and the v3 design uses style
  attributes). This is a documentation defect, not a code one: §2 should carry
  the exception so the next audit does not raise it again.

### 16. CSRF: what the token does and does not cover — `app/Core/Csrf.php`

Measured behaviour (`core-probe.php`):

```
anonymous CSRF secret is a COOKIE the client keeps: speedat_ct = b24bfa07…
A's token verifies for visitor B (different cookie): no
A's token verifies when the cookie is ALSO A's:      YES
A's token accepted a second time (no one-time use):  YES

Origin: ''                                -> ALLOWED
Origin: 'null'                            -> ALLOWED
Origin: 'https://speedat.com.pk'          -> ALLOWED
Origin: 'HTTPS://SPEEDAT.COM.PK'          -> ALLOWED
Origin: 'https://speedat.com.pk.evil.test'-> refused
Origin: 'https://evil.test'               -> refused
Origin: 'https://speedat.com.pk/'         -> ALLOWED
```

Three observations, none of them a hole on their own:

- **`Origin: null` is allowed.** An absent Origin is a defensible trade (the
  docblock argues it well). `null` is different: it is what a sandboxed iframe
  and a `data:` document send, so it is a value an attacker can choose. Refuse
  the literal string and keep allowing absence.
- **The token is replayable for 12 hours** within one session. That is normal for
  a stateless CSRF token and fine for forms; it is not fine for an action that
  must not happen twice (publish, restore). Those need their own idempotency
  key, not a tighter CSRF token.
- **The anonymous secret is a cookie the client holds**, which would let a
  same-site attacker (a subdomain XSS, or anything that can set
  `speedat_ct`) pair their own token with a secret they forced onto the victim.
  `SameSite=Lax` closes it for cross-site POSTs — the cookie is simply not sent,
  `secret()` mints a fresh one, and the token fails — which is why this is minor
  rather than major. Name the cookie `__Host-speedat_ct` (it already has
  `Secure`, `Path=/` and no `Domain`, so it qualifies) and a network attacker
  cannot plant it over http either.

### 17. `public/.htaccess` misses editor backups without a known extension

The deny list at `:59` is extension-driven:
`\.(sql|sqlite|sqlite3|db|md|log|ini|yml|yaml|lock|sh|bak|dist|sample|twig|tpl|pem|key|p12|pfx)$`.
`index.php~`, `config.php.save`, `routes.php.rej`, `index.php.orig~` are not
matched by it and not matched by `^\.` either, so LiteSpeed serves them as plain
text — the source of a file whose live version PHP would have executed. §2 lists
"backup or editor files reachable" as a thing that must not happen.

`deploy/verify-release.php:171` has the same gap in its own list
(`.swp`, `.bak`, `.orig` but no `~`, `.save`, `.rej`, `.tmp`).

**Fix.** Add a suffix rule to both:

```apache
<FilesMatch "(~|\.(save|rej|tmp|old|copy|orig~))$">
    Require all denied
</FilesMatch>
```

(and its `mod_access_compat` twin, per the file's own two rules).

**On the question of what survives `.htaccess` being ignored entirely:** the
answer here is genuinely good and worth recording so it is not re-derived. A
built release puts only `index.php` and `assets/**` in `public_html` —
`router.php` and `dev-preview.html` are both excluded (verified in finding 7) —
and `app/`, `config/`, `storage/`, `db/` and `var/` are siblings outside it. With
`.htaccess` honoured but `mod_authz_core` **and** `mod_access_compat` both absent,
no deny block applies and nothing sensitive is in the directory to deny. With
`.htaccess` ignored completely, the rewrite goes too, so every URL but `/` 404s
and `/assets/` directory listings appear — the site breaks loudly and leaks
nothing. That is the design working.

### 18. Fonts are never cached — `public/.htaccess:116` vs `:135`

```apache
AddType application/font-woff2 .woff2      # line 116
ExpiresByType font/woff2 "access plus 365 days"   # line 135
```

The type declared and the type given an expiry are different strings, so
`.woff2` falls through to `ExpiresDefault "access plus 0 seconds"` and every
self-hosted font is refetched. §1 asks for "fonts are self-hosted with
`font-display: swap`" and long expiry, and this is a measurable Lighthouse cost
on the mobile target.

**Fix.** Use the registered type (`font/woff2`, RFC 8081) in both places and keep
the legacy one as a second `ExpiresByType`:

```apache
AddType font/woff2 .woff2
...
ExpiresByType font/woff2            "access plus 365 days"
ExpiresByType application/font-woff2 "access plus 365 days"
```

### 19. `Paths::configured()` will put the logs in the web root — `app/Core/Paths.php:110-119`

`paths.storage`, `paths.logs`, `paths.cache`, `paths.tmp` and `paths.uploads` are
taken from config with no containment check, and `Paths`'s own docblock says "the
layout is the security model". A typo (`paths.logs => 'public/logs'`) makes the
structured log — which carries request paths, hashed IPs, user agents and driver
messages — downloadable. `storage/.htaccess` does not travel with it.

`Domain\Auth\GoogleCertificates::refuseWebRoot()` already solves exactly this
problem for the certificate cache and is the pattern to copy:

```php
private static function configured(string $key, string $fallback): string
{
    ...
    // A writable directory under the web root is one upload bug away from
    // being a web shell, and a log directory there is a published log.
    foreach (explode('/', trim($path, '/')) as $segment) {
        if (in_array($segment, ['public', 'public_html', 'htdocs', 'www'], true)) {
            throw new ConfigError('"' . $key . '" must not point inside the web root.');
        }
    }
```

### 20. Driver messages in the log can carry a user's bytes — `app/Core/Log.php:62-70`

`Log::exception()` writes `$e->getMessage()` verbatim. `REDACT` (`:21`) matches
**field names**, not values, so it does not apply. MySQL's 1366 and 1062 messages
quote the offending data:

```
1366 Incorrect string value: '\xC3( Kha...' for column `speedat_php_test`.`zz_probe`.`name`
```

§2 says "Nothing sensitive is logged … no customer message bodies beyond what the
inbox needs". A fragment of a name is mild; the same path would log a fragment of
a contact message. The log lives above the web root, so this is hygiene rather
than exposure.

**Fix.** Clamp the driver message where it is most likely to carry data:

```php
'errorMessage' => $e instanceof \PDOException
    ? preg_replace('/Incorrect [a-z ]*value: \'[^\']*\'/', "Incorrect value: '…'", $e->getMessage())
    : $e->getMessage(),
```

or simply `Str::limit(..., 200)` plus that one substitution.

### 21. A dead `innerHTML` sink — `public/assets/js/calculator.js:67-68`

```js
if (k === "text") el.textContent = String(v);
else if (k === "html") el.innerHTML = v;
```

Nothing passes `html:` today (`grep -n 'html:' public/assets/js/calculator.js`
returns nothing) and the only `innerHTML` write that runs is `waIcon()`'s literal
SVG at `:98`. So there is no XSS here now — and one `html: name` in a future
patch is all it takes, in a helper used on every line of the calculator.

**Fix.** Delete the branch. The two real needs are covered: `textContent` for
text and `waIcon()` for the one inline SVG. If another SVG is ever needed, add a
named `svg()` helper beside `waIcon()` rather than a generic escape hatch.

The rest of the browser JavaScript is clean on this axis — see the sound list.

### 22. `SecretBox` binds nothing to the row — `app/Domain/Auth/SecretBox.php:69-78`

`openssl_encrypt(..., $iv, $tag, '', self::TAG_LEN)` passes an empty AAD, so a
`totp_secret_enc` value is a self-contained blob: whoever can write the column
can move admin A's encrypted TOTP seed into admin B's row, and B's second factor
becomes A's phone. It needs SQL write access, which is close to game over
already, so this is depth rather than a hole — and the format is pinned to the
TypeScript's, which is the stronger constraint.

The rest of the construction checks out: AES-256-GCM, a fresh 12-byte
`random_bytes` nonce per encryption (no reuse), a 16-byte tag verified by
`openssl_decrypt` returning `false`, strict length checks on both in `split()`
(`:140`), and multi-key decrypt for rotation. `hash('sha256', $key, true)` is not
a KDF, which the docblock justifies correctly: the input is 32 bytes of
configuration, not a password.

**Fix,** if and when the format is allowed to change: pass the admin's email as
AAD and add a version prefix so old rows still open:

```php
// "v2." marks a blob whose tag also covers $context; v1 rows have no prefix
// and are still read by the loop in decrypt().
public function encrypt(string $plain, string $context = ''): string
```

### 23. Four small footguns

- **`eattr()` — `app/Core/helpers.php:36.`** `rawurlencode()` of the whole value.
  Correct for a query-string argument, wrong for the "href that is built" the
  docblock also offers it for: it encodes `:` and `/`, so `eattr($url)` inside an
  `href` produces a broken link, and a reviewer seeing `eattr` may read it as
  "this href is escaped". Rename it `equery()`, or split into `equery()` and an
  `ehref()` that validates the scheme.
- **`Mail::send()` — `app/Core/Mail.php:85,132-133.`** The newline check covers
  `$to . $subject . $this->fromAddress . $replyTo` but not `$this->fromName`,
  which is interpolated into the `From:` header. It comes from config, so it is
  not attacker-controlled; add it to the check anyway, since it costs one
  concatenation and the check is the file's stated defence.
- **`Totp::codeForStep()` — `app/Domain/Auth/Totp.php:100-105.`** The RFC 4226
  offset can be 15, so the code reads `$hmac[18]`. Safe for sha1 (20 bytes) and
  everything longer, out of bounds for a 16-byte digest — and the constructor
  accepts any `hash_hmac_algos()` entry, `md5` included. In PHP 8 that read is a
  warning, which `Bootstrap`'s error handler turns into an exception, so it is a
  500 rather than a wrong code. Restrict the constructor to
  `['sha1', 'sha256', 'sha512']`, which is also all any authenticator speaks.
- **`Db::run()`'s statement cache — `app/Core/Db.php:101-113.`** Bindings persist
  on a reused `PDOStatement`, so calling the same SQL with a *subset* of the
  parameters silently reuses the previous call's values. No current caller does
  it (the SQL always encodes its own placeholder list), but it is invisible when
  it happens. Either bind into a fresh statement or assert the parameter count
  matches. I did confirm the cache survives the failure mode it is likelier to
  hit: re-executing a cached `SELECT` with an open cursor works on MariaDB 11.4
  (probe F).

### 24. `dev-preview.html` and `router.php` are reachable outside a release

Both are excluded from a built archive (verified in finding 7), so production is
fine. They are reachable on the `php -S` dev server and on any staging copy
served straight from `php/public` — which is how the Docker staging at
`~/speedat-staging` is described.

`public/assets/dev-preview.html` says of itself that it "must never be linked
from one — it is plain HTML in public/, reachable by anyone who guesses the path,
and it carries an inline `<script>`". Its own use of URL parameters is safe
(`:476` reads `?theme` and `?view` and does nothing but toggle `hidden` and
`data-theme`) and its rate blob is invented data, so the exposure is a
throwaway harness, not a leak. `public/router.php` refuses `..`, NUL, its own
name and `.htaccess` (`:25-43`) and containment-checks with `realpath()`, which
is more care than a dev tool usually gets.

**Fix.** Delete `dev-preview.html` when the real pages render, as its own comment
asks. Until then, add `Options -Indexes` is not enough — add
`<Files "dev-preview.html">Require all denied</Files>` to `public/.htaccess` so
the staging copy is covered by the same lock as production.

### 25. Two items for the next round (outside this audit's ownership)

Flagged, not audited:

- **`app/Http/QuoteApiController.php:100`** protects a state-changing POST with
  `originOk()` only — no token. The reasoning at `:40-56` is careful and mostly
  holds: requiring `application/json` does exclude the one cross-origin request a
  browser sends without a preflight, and the per-IP limit bounds the rest. The
  residual is that `originOk()` returns true for an absent **and** a `null`
  Origin (measured, finding 16), so a non-browser client posts freely — which
  writes an attacker-chosen `dest_id`/`type`/`detail` into `quotes` at up to
  120/hour/IP. Nothing there carries authority and the price is recomputed
  server-side, so this is junk data in the admin's quote log rather than a hole.
  Worth a second opinion from whoever owns `app/Http`, and worth refusing
  `Origin: null` either way.
- **The admin CSV `<script type="text/plain">`** (`public/assets/js/admin.js:557`
  reads it with `textContent`). `ejson()` does not apply to a raw-text script
  element, and `</script>` inside a destination name closes it. Whoever owns
  `app/View/Admin` must confirm the CSV is escaped for that context — the
  minimum is replacing `</` with `<\/`, and the safer shape is a
  `type="application/json"` blob holding the CSV as a single JSON string so
  `ejson()`'s `JSON_HEX_TAG` does the work.

---

## Checked and found sound — do not redo this in round 2

### The Firebase verifier (`app/Domain/Auth/FirebaseToken.php`) — 35 forgeries, 0 accepted

`scratchpad/audit/forge-firebase.php` generates its own RSA key pair and
self-signed certificate, so the attacker genuinely holds the public half, and
runs every forgery through the real class with a stubbed `CertificateSource`.
Every one was refused for the right reason:

```
control (must PASS)                              ACCEPTED uid=uid-123 verified=yes
alg none, empty sig                              refused  Token algorithm is none, not RS256
alg none, junk sig                               refused  Token algorithm is none, not RS256
alg None (case)                                  refused  Token algorithm is None, not RS256
HS256 keyed with cert PEM                        refused  Token algorithm is HS256, not RS256
HS256 keyed with public PEM                      refused  Token algorithm is HS256, not RS256
alg as array                                     refused  Token algorithm is missing, not RS256
alg missing                                      refused  Token algorithm is missing, not RS256
RS256 signed with attacker key                   refused  Token signature does not match
payload swapped, sig kept                        refused  Token signature does not match
unknown kid                                      refused  Token was signed with an unknown key id
kid traversal (../../../../etc/passwd)           refused  Token was signed with an unknown key id
kid as array                                     refused  Token header has no key id
wrong aud                                        refused  Token audience is not this Firebase project
aud as array                                     refused  Token audience is not this Firebase project
iss http not https                               refused  Token issuer is not this Firebase project
iss with suffix (project-evil)                   refused  Token issuer is not this Firebase project
iss evil host (securetoken.google.com.evil.test) refused  Token issuer is not this Firebase project
iss trailing slash                               refused  Token issuer is not this Firebase project
expired 1h ago                                   refused  Token has expired
expired 61s ago                                  refused  Token has expired
exp as string                                    refused  Token has no usable exp claim
exp missing                                      refused  Token has no usable exp claim
exp huge float (9.9e300)                         refused  Token has no usable exp claim
iat in future                                    refused  Token was issued in the future
no iat                                           refused  Token has no usable iat claim
payload is a JSON list                           refused  Token signature does not match
four segments                                    refused  Token is not three dot-separated segments
8193 bytes                                       refused  Token is empty or implausibly long
```

Three ACCEPTED results are correct behaviour, not findings:

- `expired 30s ago` — inside the documented 60 s leeway.
- `leading whitespace` — `verify()` trims, then the signature still has to match.
- `email_verified: "true"` (string) and `email_verified: 1` — accepted as an
  *identity* with `emailVerified = false`, and `verifiedEmailFrom()`, which is
  the gate the `admins` lookup goes through, refuses both:

```
email_verified "true" string  => refused: Token has no verified email address
email_verified 1              => refused: Token has no verified email address
control                       => owner@example.com
```

The ordering the class advertises (shape → algorithm → signature → claims) is
what the code does, the signature is verified over the **original** segments, and
`base64UrlDecode()` is strict. Nothing here needs another pass.

### SQL

`EMULATE_PREPARES => false` (`Db.php:58`) means values never reach the SQL text,
and the whole tree has exactly one interpolated statement:

```
$ grep -rnE '(SELECT|INSERT INTO|UPDATE |DELETE FROM).*\$' app --include=*.php
app/Core/Db.php:181:  self::run("INSERT INTO {$quotedTable} ({$quotedCols}) VALUES ({$placeholders})", …)
```

and its three parts all come from `identifier()` (`:269-276`), which refuses
anything outside `^[A-Za-z_][A-Za-z0-9_]*$`. The one dynamic `SET` clause
(`Domain/Leads/LeadRepository.php:230`) is assembled from literal fragments with
named placeholders. No `ORDER BY` anywhere takes a caller's column.
`Audit::recent()` clamps `LIMIT`/`OFFSET` with `max`/`min` and binds them.
`Audit` really does hold only an INSERT and a SELECT.

### Sessions

No fixation: `start()` deletes any presented session first and mints a fresh
32-byte token, so a planted cookie is replaced rather than adopted. The cookie
value is never stored — the table holds its SHA-256 — and `current()` only
accepts a cookie matching `^[a-f0-9]{64}$`. Both clocks are enforced **on read**
(`:308-327`), so an expired row is dead before the sweeper runs, and the row is
deleted and the cookie cleared at the same time. `rotate()` mints a new token,
carries `created_at` (so the absolute expiry is not extended) and deletes the old
row inside a transaction, so a captured pre-rotation token is dead. Revocation is
a `DELETE`. `Cookies` (`:60-67`) gives every cookie `HttpOnly`, `SameSite=Lax`,
`Path=/` and `Secure` whenever the request is https **or** the environment is
production, with no way to opt out. A database that is down means "nobody is
signed in", never an error page.

### Passwords and the reset token

`Password` uses argon2id where available (32 MiB / t=2 / p=1, justified against
the host's RAM budget) and bcrypt cost 12 otherwise, refuses a >72-byte password
rather than letting bcrypt truncate it silently, rehashes on login, and the
timing decoy at `:145-148` is a **real** hash of a public string with a test
(`timingDecoyIsUsable()`) that proves it costs >5 ms — so "no password set" and
"wrong password" take the same time.

`PasswordSetupToken` is right in every respect except having nowhere to live
(finding 9): 32 random bytes, stored as SHA-256, `hash_equals` comparison, shape
checked before the lookup, single-use checked before expiry, and one identical
refusal sentence for all four outcomes so nothing enumerates.

### TOTP and Base32

`verify()` compares with `hash_equals`, walks the window oldest-first so the
replay guard stays monotonic, and refuses any step at or below
`$lastAcceptedStep`. The class holds no state and its docblock states the one
thing the caller must get right — `UPDATE … WHERE totp_last_step IS NULL OR
totp_last_step < :step` in the same statement that checks it. `Base32` round-trips
and refuses a character base32 cannot mean. (`decode()` will accept a length
base32 cannot produce, e.g. one leftover character, and silently drop the
fragment — harmless, since a wrong secret simply produces wrong codes.)

### Certificates

`GoogleCertificates` verifies TLS peer and host, does not follow redirects,
caps `max-age` at 24 h, filters key ids to `^[A-Za-z0-9_-]{1,128}$` *before* one
reaches a filename, requires `-----BEGIN CERTIFICATE-----` in each value,
write-then-renames the cache, and refuses a cache directory with a `public`,
`public_html`, `htdocs` or `www` segment in it. No service-account key exists
anywhere in the tree.

### Output escaping and JSON in HTML

`e()` / `View::e()` use `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`,
so invalid bytes are cleaned on the way out (verified: `e()` of the malformed
input in finding 4 is valid UTF-8). `ejson()` (`helpers.php:42-57`) carries
`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT |
JSON_INVALID_UTF8_SUBSTITUTE` exactly as §2 requires, and every
`<script type="application/json">` blob in the tree goes through it or through
the same flags (`View/CalculatorData.php:74-81`, `View/HoldData.php:61-68`,
`View/Admin/DestinationRow.php:137-139`, `Domain/Seo/JsonLd.php:102-105`) — so
`</script>` in a destination name closes nothing. `Response::json`, `Db::json`
and `Log::write` all use `JSON_INVALID_UTF8_SUBSTITUTE`, so none of them can
return `false` and print the word "false".

### Path handling

`View::resolve()` refuses everything thrown at it:

```
'site/home'                  -> renders
'../config/config'           -> Illegal template name.
'site/../../config/config'   -> Illegal template name.
'/etc/passwd'                -> Illegal template name.
"site/home\0.php"            -> Illegal template name.
'site/home.php'              -> Illegal template name.
```

`Autoloader::load()` matches the class name against a PHP-identifier pattern
before touching the disk. `Response::header()` strips CR and LF from every value
(the only gap is the ordering in `redirect()`, finding 1).

### The release builder's self-check is real

It is not a re-read of the plan. Every entry is pulled back with `statIndex()`,
run through the same `refusalReason()`, checked for an absolute or `..` path, and
**CRC-compared against its source file**; two entries are fully extracted with
`getFromName()`; the required list is confirmed with `locateName()`. On any
problem the archive is deleted rather than reported. Verified end to end:

```
files : 608   directories : 6   uncompressed : 3.79 MiB   zip : 1.58 MiB
refused: bin/export-from-nextjs.php, bin/test.php, public_html/router.php,
         public_html/assets/dev-preview.html, every .gitkeep
config/config.php  absent      router.php  absent      dev-preview.html  absent
```

`deploy/verify-release.php` is a genuinely independent second opinion: it
extracts the zip and checks the layout (`app/` beside `public_html`, no nested
`public/`, no PHP in the web root but `index.php`), the deny-all guards, and that
the writable directories carry no runtime data.

### Schema and driver

The connection is pinned correctly and `Clock`'s two-zone design holds end to
end:

```
server: 11.4.13-MariaDB-log
sql_mode: STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
time_zone: +00:00 / NOW()=2026-09-27 09:26:23 UTC_TIMESTAMP()=2026-09-27 09:26:23
```

`NOW()` and `UTC_TIMESTAMP()` agree, so every `DEFAULT CURRENT_TIMESTAMP` is UTC
as the header comment claims — on any connection that goes through `Db`. (The
residual risk is a row inserted by something that does not: a phpMyAdmin
`INSERT`, or a `mysql` client inside the staging container. Worth a line in
`docs/staging.md`.) `TIMESTAMP` is avoided throughout, which is the right call.

The MariaDB `JSON` note in the header is **true**, which I checked rather than
assumed:

```
column type as the server stores it: longtext
CHECK constraints on it: 1
refused: SQLSTATE[23000]: 4025 CONSTRAINT `zz_probe3.d` failed
```

MariaDB adds the `json_valid()` check itself, so `versions.data`,
`app_settings.v`, `quotes.detail`, `audit_log.detail` and `imports.result` cannot
hold non-JSON.

Type coercion fails closed everywhere it matters — negatives and overflows on
`INT UNSIGNED`, an out-of-range `ENUM`, a non-integer on `TINYINT(1)`, `"12abc"`
on an int — all refused, none silently truncated. `Caps::WIDTHS` mirrors the
VARCHAR widths correctly and `CapsTest` reads the migration to keep them honest.
Hex columns are `ascii_bin`, which is both smaller and exact-match. `sessions.id`
is `CHAR(64)` for a SHA-256 and `leads.quote_id` is `UNIQUE` with NULLs allowed,
as intended.

### Rate limiting, apart from finding 10

The key derivation is sound: `bucket . ':' . Ip::hash($ip)`, where `Ip::hash` is
a keyed HMAC truncated to 32 characters and is length-stable no matter what it is
handed (verified with a 100,000-character input). Measured key length for
`login` is 38 characters against a `VARCHAR(160)` column. The counter is a single
atomic `INSERT … ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(count + 1)`, so
increment-and-read cannot interleave, with a `SELECT` fallback if
`lastInsertId()` is unusable. Windows are fixed and computed from milliseconds.
The sweep is 2% of calls (`random_int(1, 1000) <= 20`), deletes windows older
than 24 h, and — the bug the docblock records — takes the **same** clock the
counting used, so an injected clock cannot delete the rows it just wrote.

### The rest of the browser JavaScript

`ui.js` `esc()` escapes `& < > " '`. `readBlob()` reads a JSON blob with
`textContent` and returns `null` on anything unparseable, so a data bug never
blanks a page. The only `innerHTML` writes in the shipped files are `ui.js:101`
(two hard-coded glyph constants) and `calculator.js:98` (a literal SVG string) —
plus the dead branch in finding 21. Everything else assigns `textContent`. The
`location.hash` reads (`ui.js:196-206`) go to `getElementById` and nowhere else.
Every `data-*` read is a server-rendered attribute that went through `e()`. The
WhatsApp links are built from `site.company.whatsapp` with
`encodeURIComponent()` on the message (`calculator.js:394`, `pricing.js:677`),
and no `href` is ever assembled from a URL parameter. `admin.js`'s autosave posts
`new FormData(form)`, which carries the `_token` field, with
`credentials: "same-origin"`.

### Mail and outbound HTTP

`Mail::send()` validates the recipient with `filter_var`, squishes the subject
and then refuses any `[\r\n]` in the header values outright — mail-header
injection is closed (finding 23 notes the one field missing from the check).
`Http::request()` refuses any scheme but http(s), does not follow redirects,
verifies the peer and the host with no flag to turn it off, and strips the query
string before logging a URL.

### Errors and headers

`Bootstrap` sets `display_errors=0` before anything else and turns every warning
into an exception, so nothing prints a notice into an HTML head or a JSON body.
`Bootstrap::run()` has no exit that does not produce a `Response`, `errorPage()`
sends no trace/query/path, and `lastResort()` splits web (500 + a plain page)
from CLI (stderr + **exit 1**), which is the difference between a cron job that
reports failure and one that mails out HTML and exits 0. `HttpError` keeps the
public sentence and the logged `detail` apart by construction. Measured caps:
`post()` 5,000 characters, `query()` 500, `userAgent()` 300, headers 20,000 in
`fromGlobals()`, request body 1 MiB, `postList()` 200 items. `postWasDiscarded()`
is a genuinely good catch — the `post_max_size` hole leaves no upload error code,
and `public/.htaccess` keeps `post_max_size` (9M) above `upload_max_filesize`
(8M) so it should never fire.

The security headers are set in PHP **and** in `.htaccess`, so a missing
`mod_headers` (which `hostinger-constraints.md` §1 flags as unverified) does not
lose them. The CSP is `default-src 'self'` with a per-request nonce for scripts,
`base-uri 'none'`, `object-src 'none'`, `frame-ancestors 'none'` and
`form-action 'self'`; `App::extra()` refuses anything from config that is not a
bare scheme/host expression, so no quoted keyword or `;` can be smuggled in.
HSTS is opt-in and https-only, for the reason given.

### The contact form

Full belt and braces, in this order: body-discarded check → `originOk()` **and**
`Csrf::check()` → honeypot (which pretends success and stores nothing) → a
render-token timing check → the rate limit. That is the shape the quote endpoint
should be measured against.

---

## Not verified here

- Anything under `app/Http`, `app/View`, `app/Views`, `app/Domain/{Leads,Quotes,
  Shipments,Notify,Ops,Seo,SiteDoc}` and `app/Vendor` — other agents' surfaces,
  in flight. Findings 6, 13 and 25 depend on them and say so.
- `app/Domain/Import/**` — dead code awaiting deletion per the owner's
  2026-09-27 decision. Not read. `Core\Upload` is audited above and is now
  unreachable product-wise; it should go with it.
- Behaviour that needs the real host: whether LiteSpeed honours `mod_headers`,
  whether `.htaccess` `php_value` lines apply, `open_basedir`, the actual
  MariaDB version and the MySQL connection ceiling. All are marked **VERIFY** in
  `hostinger-constraints.md` and none can be settled from this machine.
- Lighthouse, and the full browser flow walk (§3 items 2 and 5).
- The `password_setup` flow end to end — there is nothing to test until finding 9
  is fixed.
