# Speedat — the PHP port

The public quote site and the rates admin, rewritten in plain PHP so they run
on the Hostinger shared hosting the business already pays for: PHP 8.2 + MySQL,
Apache with `.htaccess`, hPanel cron. **No Composer, no `vendor/`, no build
step, no Node on the server.** Deployment is "upload files, import one `.sql`,
add one cron job".

This tree lives beside the Next.js app in the same repository. The Next.js app
under `/src` and `/app` keeps running and keeps serving customers until the PHP
version replaces it; nothing in here touches it.

- **Machine setup, the local database, the dev server, php.ini** →
  [`bin/local/README-local.md`](bin/local/README-local.md)
- **What each domain folder is for and which TypeScript it ports** →
  [`app/Domain/README.md`](app/Domain/README.md)
- **The design that must be preserved** → `/docs/design-brief-v3.md` and
  `/src/styles/*.css` in the Next.js tree. Phase 3 ports the CSS across
  unchanged. Nothing here is a redesign.

---

## The layout, and why it is this shape

```
php/
  public/       ← the ONLY directory the internet can reach
    index.php       front controller: bootstrap, route, render, send
    .htaccess       rewrite to index.php, deny what must not be downloaded,
                    security headers, asset caching
    router.php      dev-server twin of the .htaccess rewrite; NOT uploaded
    assets/         css/ img/ flags/ (phase 3 fills these)
                    js/pricing.js — the browser pricing engine, twin of
                    app/Domain/Pricing (see docs/engine-parity.md)
  app/          application code — never served, only included
    Core/           the machinery: Bootstrap, Config, Router, Request,
                    Response, Db, Session, Csrf, RateLimit, Audit, View,
                    Mail, Http, Log, Clock, Money, Str, Ip, Cookies, Paths,
                    Caps (the schema's column widths, mirrored once)
    Domain/         the business: Pricing, SiteDoc, Import, Leads,
                    Shipments, Admins, Auth  (see app/Domain/README.md)
    Views/          plain PHP templates — layouts, partials, pages, errors
    routes.php      every URL the site answers, in one file
  config/
    config.sample.php   committed, empty
    config.php          the real one: NEVER committed, NEVER in public/
  storage/        cache/ logs/ tmp/ uploads/ — writable, denied by .htaccess
  db/migrations/  0001_init.sql, applied by bin/migrate.php
  bin/            migrate.php, seed.php, cron.php, test.php, local/
  tests/          the test runner's suites (no PHPUnit)
    vectors/        golden answers taken from the TypeScript engine
    js/             the node runner that checks assets/js/pricing.js
  tools/          make-vectors.ts — regenerates the golden vectors; deleted
                  with the Next.js app, never shipped to the host
  docs/           engine-parity.md, hostinger-constraints.md
  var/            scratch space for anything that is not storage
```

The shape exists for one reason: **secrets must not be under the web root.**
`public/` is the document root and contains a front controller, static files
and an `.htaccess`. `config/config.php` — which holds the database password,
the application key and the cron token — is a *sibling* of the web root, one
level up. A rewrite that breaks, a module that is disabled, an Apache upgrade
that forgets a directive: none of them can serve a file that is not inside the
directory Apache is pointed at.

`config.php` is also PHP rather than `.env` on purpose. A `.env` is plain text
and is served as plain text by any server that has not been told to hide it. A
`.php` file gets executed, so the worst case is a blank page.

---

## How it maps onto Hostinger

Hostinger gives you `~/domains/<domain>/public_html` as the document root, and
you can write anywhere in `~/domains/<domain>/`. So:

| Local (this repo)  | On the server                                      |
|--------------------|----------------------------------------------------|
| `php/public/**`    | `~/domains/<domain>/public_html/**` ← **contents**, not the folder |
| `php/app/`         | `~/domains/<domain>/app/`                          |
| `php/config/`      | `~/domains/<domain>/config/`  (`config.php`, chmod 600) |
| `php/storage/`     | `~/domains/<domain>/storage/` (writable, 755)       |
| `php/db/`          | `~/domains/<domain>/db/`                           |
| `php/bin/`         | `~/domains/<domain>/bin/`  (without `bin/local/`)  |
| `php/var/`         | `~/domains/<domain>/var/`                          |
| `php/tests/`       | not uploaded                                       |
| `php/public/router.php` | **not uploaded** — it is the dev server's rewrite |

`public/index.php` finds everything else with `dirname(__DIR__)`, so the layout
above is the only thing that has to be true. Nothing is hard-coded to a path.

### First deploy, in order

1. **Database.** hPanel → MySQL Databases: create a database and a user, note
   the credentials. The database name and user are prefixed (`u123456789_…`) —
   use the prefixed names.
2. **Schema.** hPanel → phpMyAdmin → Import → `db/migrations/0001_init.sql`.
   Every statement is `IF NOT EXISTS`, so importing it twice is harmless. (With
   SSH: `php bin/migrate.php` does the same and records the checksum.)
3. **Files.** Upload the tree as mapped above. Remember that the *contents* of
   `php/public/` become `public_html/`, not a `public/` folder inside it.
4. **Configuration.** Copy `config/config.sample.php` to `config/config.php`,
   fill in `db`, `app.url`, `app.key`, `mail` and `tokens`, then `chmod 600`.
   Generate the key with:
   `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`
5. **Permissions.** `storage/` and its subdirectories must be writable by the
   PHP user (755 is normally enough on Hostinger; 775 if not).
6. **PHP version.** hPanel → PHP Configuration → 8.2 or newer. Check that
   `curl`, `openssl`, `mbstring`, `zip`, `pdo_mysql`, `intl` and `fileinfo` are
   enabled; `bin/local/doctor.php` reports exactly which one is missing.
7. **Cron.** hPanel → Cron Jobs → Custom, **once an hour** (`0 * * * *`):
   ```
   /usr/bin/php /home/uXXXXXXXX/domains/<your-domain>/bin/cron.php
   ```
   Three things about that line, each of which has cost somebody an evening:
   - **Absolute paths, and no `~`.** Cron has no working directory you can count
     on and does not reliably expand `~`.
   - **No `--token=`.** `bin/cron.php` reads no arguments at all. It is CLI-only
     — `Bootstrap::cli()` refuses to run under a web server and the file lives
     above `public_html` — so there is nothing for a token to guard.
     `tokens.cron` is for the *other* case, a plan that only offers
     URL-triggered cron; set the value anyway, because an empty token must never
     be the thing standing between the internet and the scheduled jobs.
   - **No `>/dev/null 2>&1`.** hPanel's command field rejects `>`, `2>&1` and
     `/dev/null` (`docs/hostinger-constraints.md` §6). The script writes to
     `storage/logs/` itself. If you ever genuinely need redirection, put it in a
     small `.sh` file and have cron call `/bin/sh yourfile.sh`.

   **Hourly, not daily**, and that is load-bearing: the alerts that must fire
   once a day dedupe themselves through `Domain\Ops\DailyOnce`, which only makes
   sense because the job itself runs more often than that. The cron clock is UTC
   and cannot be changed.
8. **Verify the `.htaccess` actually took.** See the warning below — this is
   the one part of the security story that cannot be tested off the server.

### Verifying `.htaccess` on the server (do not skip this)

`php -S` ignores `.htaccess` entirely, so every deny rule and every header in
`public/.htaccess` is **unverified until it is checked on Hostinger**. After
the first upload, run these from any machine and read the status codes:

```bash
curl -I https://<domain>/                     # 200, and the security headers
curl -I https://<domain>/no-such-page         # 404 from index.php, not Apache
curl -I https://<domain>/.htaccess            # 403
curl -I https://<domain>/router.php           # 403 (or 404 if not uploaded)
curl -I https://<domain>/assets/              # 403 — directory listing is off
curl -I https://<domain>/../config/config.php # 400/403, never 200
```

`Strict-Transport-Security` is deliberately commented out in `.htaccess` and
`false` in `config.php`. Turn it on **only once the PHP site is the live https
site**: a browser remembers HSTS for a year and will refuse plain http to the
domain for that long, which is painful to undo if it was set by mistake.

---

## Running it locally

Full detail, including how the toolchain was installed, is in
[`bin/local/README-local.md`](bin/local/README-local.md). The short version:

```powershell
# 1. database (MariaDB 11.4 on 127.0.0.1:3307, portable, not a service)
powershell -ExecutionPolicy Bypass -File bin\local\start-db.ps1

# 2. schema, both databases
D:\php\php.exe bin\migrate.php
D:\php\php.exe bin\migrate.php --database=speedat_php_test

# 3. the site  ->  http://127.0.0.1:8081/
powershell -ExecutionPolicy Bypass -File bin\local\serve.ps1
```

Port **8081**, not 8080: 8080 is taken by another service on the build machine.
`serve.ps1` takes `-Port` if you need a different one. Stop it with Ctrl+C in
its own window — killing the PowerShell wrapper leaves an orphaned `php.exe`
holding the port.

Other things worth knowing:

```powershell
D:\php\php.exe bin\test.php                  # the whole suite
D:\php\php.exe bin\test.php --filter=RateLimit
D:\php\php.exe bin\seed.php                  # default settings rows
D:\php\php.exe bin\cron.php                  # the hourly sweep, by hand
D:\php\php.exe bin\local\doctor.php          # is this PHP good enough?
D:\php\php.exe bin\local\db-check.php        # a real PDO round trip
powershell -File bin\local\lint.ps1          # php -l on 8.2 AND 8.3
```

`bin/test.php` runs against `speedat_php_test` — the configured database name
with `_test` appended — never the one you were working in. A suite that can
truncate your development data is a suite people stop running.

It checks for a schema before it runs anything. A database that is reachable but
has never been migrated used to produce a hundred and thirty-nine identical
`PDOException`s, none of them containing the word *migrate*; now it produces one
line naming `bin/migrate.php` and exits 3. Its exit codes are **0** all green,
**1** something failed, **2** a `--database` name that is not `[A-Za-z0-9_]`, and
**3** reachable but unmigrated. A database that cannot be reached at all is not a
failure — the tests that need one skip themselves with a reason, and the pricing,
text and browser-engine suites are worth running without it.

---

## The rules this code is written under

1. **No Composer, no third-party packages.** Everything is hand-written and
   plain: XLSX via `ZipArchive` + `XMLReader`, TOTP via `hash_hmac`, Firebase
   ID-token verification via `openssl_verify` against Google's published
   certificates. If a dependency would be needed, it gets written instead.
2. **Secrets never under the web root.** See the layout above.
3. **Every query is a prepared statement.** `Core\Db` runs with
   `ATTR_EMULATE_PREPARES = false`, so values never reach the SQL text at all
   and injection is impossible by construction rather than by care. The only
   method that builds SQL from a string validates the identifier against
   `[A-Za-z0-9_]` and those identifiers come from this codebase, never from a
   request.
4. **Every form carries a CSRF token**, signed with a key derived from the
   application key *and* the session secret, compared with `hash_equals`.
5. **Every admin route re-checks the session server-side.** Hiding a link is
   not access control.
6. **`audit_log` is append-only.** `Core\Audit` contains exactly one statement
   and it is an `INSERT`. The database-level triggers that would enforce this
   even against a compromised application are written out in a comment at the
   top of `0001_init.sql`; they are not in the file itself because a host
   without the `TRIGGER` privilege would fail the whole import.
7. **`strict_types=1` in every file**, typed properties, no globals beyond the
   one container (`Core\App`), PSR-4 through a 60-line autoloader.
8. **Every write checks its column widths first.** `db/migrations/0001_init.sql`
   declares `VARCHAR` widths and `Core\Db` connects with `STRICT_TRANS_TABLES`,
   so a name one character too long is not a truncation — it is a `PDOException`,
   a 500 page, and a customer who concludes the site is broken. `Core\Caps`
   mirrors every width (guarded against the schema by `tests/Core/CapsTest.php`)
   and every write path puts one line in front of its insert:

   ```php
   Caps::assert('leads', $row);   // throws Core\ValueTooLong
   Db::insert('leads', $row);
   ```

   Use `Caps::problems($table, $row)` instead where there is a form to decorate —
   it returns `column => sentence` — and `Caps::fit()` for machine-generated
   values such as a User-Agent, where silently truncating is the right answer.
   **The repositories have not adopted this yet**: the exact call sites are
   listed in `app/Core/Caps.php`'s docblock, and a repository that adopts it
   should drop its own `MAX_*` constant for that column rather than keep a second
   number for it.
8. **The pricing engine must produce exactly the same numbers as the
   TypeScript one** — integer grams, the same rounding order, the same
   document-rate rule, the same next-priced-kilogram lookup, the same delivery
   arithmetic. The 209 tests in `/src/**/*.test.ts` are the contract, and the
   PHP port gets its own tests asserting the same values.

---

## Two clocks, and the bug they will cause

PHP runs in **Asia/Karachi** (`app.timezone`) because that is the timezone
customers read delivery dates in. MySQL runs in **UTC** — `Core\Db` sets
`time_zone = '+00:00'` on every connection, and every column is `DATETIME`
holding UTC, never `TIMESTAMP`.

This mirrors the real host, and it is deliberate, but it means **any code that
assumes the two agree produces off-by-one delivery dates**. Go through
`Core\Clock` for anything that crosses the boundary: `Clock::sql()` writes UTC,
`Clock::fromSql()` reads it back, `Clock::now()` is local for display. Delivery
estimates and cut-off times get an explicit test for this in phase 2.

---

## Where the port has got to

| Phase | | |
|---|---|---|
| 1 | Toolchain — PHP 8.2/8.3, MariaDB, scripts, doctor | done |
| 1 | Foundation — front controller, Core, schema, migrations, test runner | **done (this tree)** |
| 2 | Pricing engine + its browser twin, against 940 golden vectors | **done** — [`docs/engine-parity.md`](docs/engine-parity.md) |
| 2 | Site document, draft/versions, price hold | next |
| 3 | The v3 design: `src/styles/*.css` ported, quote page, calculator | |
| 4 | Admin — Firebase sign-in, TOTP, editing, publishing, diffs | |
| 5 | Excel intake, leads, shipments, tracking, mail, cron | |
| 6 | Cut over: DNS/hosting moves to the PHP site, Next.js retired | |

### Known, deliberately unresolved

- **`public/.htaccess` is unverified.** `php -S` ignores it. Check it on the
  server with the `curl -I` list above before the site is called done.
- ~~**`lib/pricing/addons.ts` does not exist**~~ — resolved in phase 2. Add-on
  pricing is split between `engine.ts` (`addonsList`, parsing the settings
  text) and `quote.ts` (`resolveAddons`, `bookedTotal`), and both are ported
  into `Domain\Pricing\Engine` and `Domain\Pricing\QuoteText`. No such file was
  invented.
- ~~**The TypeScript tests have not been run from here**~~ — resolved in phase
  2. `pnpm exec vitest run src/lib/pricing src/lib/site/diff.test.ts` is green
  (65 tests), and `php/tools/make-vectors.ts` captures 940 golden answers from
  the same modules into `tests/vectors/pricing.json`, which both the PHP engine
  and the browser engine are checked against on every `php bin/test.php`.
- **The Next.js quote route trusts the client for two weights.**
  `src/app/api/quotes/route.ts` accepts `billableG` and `chargeG` separately
  and only clamps `chargeG <= billableG`, so a forged body can claim the flat
  document rate for a heavy parcel. It corrupts a logged quote, not a payment
  — prices are confirmed at pickup — but the PHP route must recompute both
  weights from the submitted pieces. Not fixed in the Next.js app on purpose:
  it keeps serving customers untouched until the cut-over.
- **QR rendering for TOTP enrolment** is undecided: a small self-written
  encoder, or the `otpauth://` URI shown as text. Phase 4 decides.
