# Deploying Speedat to Hostinger

A checklist. Follow it top to bottom; do not skip ahead. Everything is done
through hPanel and File Manager — **there is no shell on this plan**, and nothing
below assumes one.

Budget **60–90 minutes** for the first deploy, **10 minutes** for every one after
that.

Two companion documents:

- [`hostinger-constraints.md`](./hostinger-constraints.md) — *why* the host
  behaves as it does, and the list of things only the owner's panel can answer.
- [`security.md`](./security.md) — what protects what, and what to do when
  something leaks.

---

## 0. Before you touch the panel

Have these ready. Stopping halfway to generate a key is how a half-configured
site ends up live.

| What | Where it comes from |
|---|---|
| The release zip | `php bin/build-release.php` on your machine |
| The verification | `php deploy/verify-release.php <that zip>` — must say **safe to upload** |
| The data migration `.sql.gz` | `bin/export-from-nextjs.php --gzip` (section 4) |
| An application key | `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` |
| A cron token | `php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"` |
| A metrics token | same command, a *different* value |
| The four Firebase web-app values (`projectId`, `apiKey`, `authDomain`, `appId`) | Firebase console → Project settings → Your apps → Web app ([`firebase-setup.md`](./firebase-setup.md) section 1) |
| Your hPanel login | — |

Write the key and the tokens into a password manager **now**, before they go
into `config.php`. They are not recoverable from the server: `config.php` is the
only copy, and losing the application key means every stored TOTP secret is
gone.

> Never put any of these in a chat message, a ticket, a commit, or a file inside
> `public_html`. See [`security.md`](./security.md) §"Where the secrets live".

### Build and verify the release

```bash
cd /d/speedat/php
/d/php/php.exe bin/build-release.php
/d/php/php.exe deploy/verify-release.php ../php/var/speedat-release-<stamp>.zip
```

The builder prints the file count, the size and a SHA-256. The verifier unpacks
the zip into a temporary directory and runs ~60 checks against what actually
landed — no `config.php`, no `.env`, no tests, no logs, every non-public
directory guarded, every shipped `.php` parses. **If it does not end with "The
archive is safe to upload", stop.** Do not upload it "just to see".

> **The `app/Core/Config.php` false positive is FIXED** (2026-09-29; the
> verifier was re-run against a clean build during the rehearsal recorded in
> [`go-live-rehearsal.md`](./go-live-rehearsal.md), and it ended
> `63 check(s): 63 passed, 0 failed` / *"The archive is safe to upload"*).
> It used to fail **every** archive with
> `FAIL  no config.php (real configuration)  app/Core/Config.php`
> and print `DO NOT UPLOAD THIS ARCHIVE`, because `app/Core/Config.php` — the
> class that *reads* the configuration and holds none of it — was caught by a
> rule the release builder had always exempted `app/` from. Check 2 of the same
> run asserts `has app/Core/Config.php`, so no archive could satisfy both and
> the verifier could never pass. **If it says anything but "safe to upload",
> stop** — and read the failing lines, not only the last one.

**The builder now runs the test suite before it packs anything** (2026-09-29).
`bin/build-release.php` runs `bin/test.php` in a child process, with the same
PHP binary, and refuses to collect a single file if it exits non-zero. This is
not belt and braces — it is the only check in the pipeline that runs a line of
the application. Every other one asks whether the files are the right files and
whether each one *parses*, and during the rehearsal a release was built from a
tree whose views still read a property that had just been removed from
`Settings`: every check passed, the builder said *"Verified"*, and the
installed home page returned **500**.

`--skip-tests` exists for a machine with no test database. It prints a framed
warning and stamps `TESTS NOT RUN` into the archive comment and `INSTALL.txt`,
so an unproven zip is identifiable long after the terminal is closed. Item 3 of
[`go-live-checklist.md`](./go-live-checklist.md) is still not optional — the
suite has to be green, and now the builder is what insists on it.

The verifier still does not run the suite, and does not need to: by the time it
sees a zip, the builder has already refused to make one from a red tree.

Keep the SHA-256. After the upload you can confirm in File Manager that the file
size matches; a truncated upload is the classic File Manager failure.

---

## 1. The directory layout you are aiming for

This is the single most important picture in this document. Get it wrong and the
site either does not start or publishes its own configuration.

```
/home/uXXXXXXXX/
└── domains/speedat.co/
    ├── public_html/          ← THE ONLY directory the web serves
    │   ├── index.php             the front controller
    │   ├── .htaccess             rewrite + deny rules
    │   ├── .user.ini             SHIPS IN THE RELEASE (section 8)
    │   └── assets/               css, js, fonts, flags
    ├── speedat_main/php/     ← THE APPLICATION. NOT under public_html
    │   ├── app/                  code
    │   ├── bin/                  cron.php, seed.php
    │   ├── config/               config.php
    │   ├── db/migrations/        the schema
    │   ├── public/               a copy of public_html the code reads from
    │   ├── storage/              cache logs tmp uploads — writable
    │   └── var/
    └── INSTALL.txt
```

`public_html` and `speedat_main/php` side by side. **Not** `public_html/app`,
and **not** `app/` straight beside `public_html`. The front controller looks for
the application at `dirname(__DIR__)/speedat_main/php` and uses it whenever it
exists, so:

- an `app/`, `config/` or `bin/` directly in `domains/<your-domain>/` is a tree
  **nothing runs**. Older releases put the code there; on a
  host that already had `speedat_main/php`, extracting one replaced
  `public_html` and left the old code serving it, and every "fix" looked like an
  OPcache problem. If you have such leftovers, delete them once the install in
  `speedat_main/php` is proven, so nobody edits a `config.php` the site never
  reads;
- extract one level too deep and the site answers *"This site is not installed
  yet."* forever;
- extract *into* `public_html` and `config/config.php` becomes reachable under
  `https://your-domain/speedat_main/php/config/config.php`. The deny-all
  `.htaccess` in every non-public directory exists to survive exactly that, but
  do not rely on it — it is the second lock, not the first.

**From here on, every path that is not under `public_html` is under
`speedat_main/php/`.** The release zip already has this shape. **Extract it at
`domains/<your-domain>/`.**

---

## 2. Create the database

**hPanel → Databases → MySQL Databases**

1. *Create a New MySQL Database*. Type `speedat` as the name and `speedat` as
   the user, pick a long random password (the panel will generate one — use it),
   and create.
2. **Write down the real names.** Hostinger prefixes both with your account id.
   What you typed as `speedat` is actually something like:

   | | |
   |---|---|
   | database | `u123456789_speedat` |
   | user | `u123456789_speedat` |
   | host | `localhost` |
   | port | `3306` |

   Those prefixed names are what goes in `config.php`. Typing `speedat` there is
   the most common step-2 mistake and produces *"The database is unavailable."*

3. Confirm the user has **all privileges** on that database (the panel grants
   this by default when it creates the pair).

> Note the version while you are here: **phpMyAdmin shows it top-right.** It will
> be MariaDB. Record it in `hostinger-constraints.md` §12 — the schema is written
> for MariaDB 10.4+/MySQL 8 and the `CHECK` constraint on `draft` needs 10.2+.

---

## 3. Import the schema

**hPanel → Databases → phpMyAdmin →** your database **→ Import**

1. Choose `db/migrations/0001_init.sql` from the release zip (unzip it locally
   to get at the file, or upload the zip first and read it from File Manager —
   either way the *import* happens in phpMyAdmin, not File Manager).
2. Format **SQL**, character set **utf8mb4**. Go.
3. Expect *"Import has been successfully finished, 12 queries executed."*
4. **Then import every later migration, in filename order.** Today that is one
   more file: `db/migrations/0002_session_csrf_secret.sql`. It is not optional —
   without it the CSRF secret falls back to one derived from the session row and
   **every admin is signed out again by their own second factor**. The file
   carries the same warning at the top and is written to be safe to import
   twice.

Every statement in `0001` is `CREATE TABLE IF NOT EXISTS` and `0002` adds its
column only when it is missing, so importing either twice is harmless. That is
deliberate: a go-live where you are not sure whether an import completed is a
go-live where you can just do it again.

**Check:** the *Structure* tab should list

```
admins  app_settings  audit_log  draft  leads  quotes  rate_limits
schema_migrations  sessions  shipment_events  shipments  versions
```

**Twelve tables.** Fewer means the import stopped early — read the error, do not
carry on. (This said *thirteen* and listed an `imports` table until 2026-09-27;
that table went with the Excel rate-sheet feature the owner removed from the
product, and the rehearsal counted twelve on a correct import. A document that
says thirteen makes you abandon a good install.)

Confirm `0002` landed, in phpMyAdmin → SQL:

```sql
SELECT COUNT(*) AS has_csrf_secret FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'sessions'
   AND column_name = 'csrf_secret';   -- must be 1
```

### Optional: make `audit_log` append-only in the database

Hostinger gives one database user with full rights, so the Postgres trick of
granting the application `INSERT` and `SELECT` only cannot be reproduced. If the
plan allows triggers (most do), paste this into phpMyAdmin → SQL and the
*database* will refuse to rewrite history even if the application is
compromised:

```sql
DELIMITER //
CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';//
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';//
DELIMITER ;
```

If it errors with a privilege message, skip it — the application never issues an
`UPDATE` or `DELETE` against that table either way. Note the outcome in
`security.md`'s host-dependent list.

---

## 4. Import the migrated data

Skip this section on a fresh site with no history. Do it on cut-over night.

### 4a. Produce the file (on your machine)

The exporter reads the Next.js Postgres database and writes MySQL-ready SQL.
`D:\php` has no `pdo_pgsql`, so the read goes through a small Node script first:

```bash
cd /d/speedat

# 1. Postgres -> JSON  (read-only; the `postgres` package the app already uses)
node php/deploy/export/dump-postgres.mjs \
  --url=postgres://USER:PASS@HOST:PORT/DB \
  --out=php/var/nextjs-dump.json

# 2. JSON -> MySQL SQL
cd php
/d/php/php.exe bin/export-from-nextjs.php \
  --dump=var/nextjs-dump.json \
  --app-key=<the same app.key you will put in config.php> \
  --gzip
```

It prints a per-table count — source rows against emitted rows — and a SHA-256.
Read that table. If a number is wrong, it is wrong *here*, on your machine,
where fixing it costs nothing.

Three things it does deliberately, each reported in its output:

- **`audit_log.ip` becomes `ip_hash`.** The PHP app never stores an address.
  Passing `--app-key` hashes the old addresses exactly the way the live site will
  hash new ones, so history stays comparable; leaving it off drops them.
- **TOTP secrets travel verbatim** (they are already encrypted, in the same
  format the PHP app reads). They keep working only if the SecretBox key matches
  the old `TOTP_ENCRYPTION_KEY`. If it does not, pass `--drop-totp` and have the
  admins re-enrol — 30 seconds each, and cleaner than a mystery.
- **`rate_limits` is not migrated.** The counters are keyed by the *old* app's
  salt and the windows closed hours ago.

Then rehearse it, against a scratch database, before you touch production:

```bash
/d/php/php.exe bin/export-from-nextjs.php --dump=var/nextjs-dump.json \
  --app-key=... --import-into=speedat_php_test --truncate
```

It imports and then counts the rows in MySQL beside the rows it emitted. Run it
a **second** time without `--truncate`: the counts must not move. That is the
proof that a half-finished import on the night is safe to simply re-run.

### 4b. Import it (phpMyAdmin)

1. **phpMyAdmin → Import**, choose the `.sql.gz`. phpMyAdmin accepts gzip and
   the ceiling is **256 MB**; our file is well under a megabyte.
2. Go. Expect a green "Import has been successfully finished".

If the file ever does grow past the limit, split it with
`--only=versions,draft,app_settings` and so on, in the order the exporter lists
them, importing each in turn.

### 4c. Check it

phpMyAdmin → SQL:

```sql
SELECT 'versions',  COUNT(*) FROM versions
UNION ALL SELECT 'draft',        COUNT(*) FROM draft
UNION ALL SELECT 'app_settings', COUNT(*) FROM app_settings
UNION ALL SELECT 'admins',       COUNT(*) FROM admins
UNION ALL SELECT 'leads',        COUNT(*) FROM leads
UNION ALL SELECT 'quotes',       COUNT(*) FROM quotes
UNION ALL SELECT 'shipments',    COUNT(*) FROM shipments
UNION ALL SELECT 'audit_log',    COUNT(*) FROM audit_log;
```

Compare against the exporter's printed table. They must match exactly.

Then check the two rows that decide what customers see:

```sql
SELECT MAX(version) FROM versions;        -- the version the site will serve
SELECT k, v FROM app_settings WHERE k='hold';   -- the price hold
```

**The price hold is the one setting that is invisible until it is wrong.** If
`on` is `true` in the old system it must be `true` here, or the new site goes
live quoting prices the owner had deliberately taken down.

---

## 5. Upload the release

**hPanel → Files → File Manager**

1. Navigate to `domains/<your-domain>/`. You should already see an empty (or
   default-parking-page) `public_html` there.
2. If `public_html` has Hostinger's default `index.html` or a parking page in
   it, delete those files now. `DirectoryIndex index.php` puts `index.php` first,
   but a leftover `index.html` in a subdirectory will still surprise you later.
3. **Upload** the release zip *into* `domains/<your-domain>/` — the level with
   `public_html`, not inside it.
4. Check the uploaded size against what the builder printed. File Manager
   silently truncates large uploads on a flaky connection.
5. Right-click the zip → **Extract** → extract **here** (the current directory).
6. Delete the zip from the server when the extraction is done. It is a complete
   copy of the application sitting one directory above the web root; there is no
   reason to leave it.

**Check the layout before going on.** File Manager should show, in
`domains/<your-domain>/`:

```
public_html/   speedat_main/   INSTALL.txt
```

and in `domains/<your-domain>/speedat_main/php/`:

```
app/   bin/   config/   db/   public/   storage/   var/
```

If instead you see `public_html/speedat_main/`, you extracted in the wrong place: delete
what you extracted and start section 5 again. Do not try to move folders around —
it is faster and safer to redo it.

---

## 6. Fill in the configuration

1. File Manager → `speedat_main/php/config/`. You will see `config.sample.php`
   and `.htaccess`.
2. Right-click `config.sample.php` → **Copy** → into the same directory, then
   rename the copy to **`config.php`**. (Copy-then-rename, not rename: keeping
   the sample means the next deploy has something to diff against.)
3. Edit `config.php` and fill in:

| Key | Value |
|---|---|
| `app.key` | the base64 key from section 0 |
| `app.url` | `https://your-domain` — absolute, **no trailing slash** |
| `app.env` | `production` |
| `app.timezone` | `Asia/Karachi` |
| `app.debug` | `false` |
| `app.trust_proxy` | `false` unless Cloudflare is genuinely in front |
| `db.host` | `localhost` |
| `db.port` | `3306` |
| `db.name` | the **prefixed** name, `u123456789_speedat` |
| `db.user` | the **prefixed** user |
| `db.pass` | the password from section 2 |
| `auth.firebase_project_id` | `projectId` — [`firebase-setup.md`](./firebase-setup.md) section 2 |
| `auth.firebase_api_key` | `apiKey` — same place. **Add this line** if your `config.php` does not have it |
| `auth.firebase_auth_domain` | `authDomain`, e.g. `<project-id>.firebaseapp.com`, no `https://`. **Add this line** if missing |
| `auth.firebase_app_id` | `appId`. **Add this line** if missing |
| `auth.allow_password_login` | `true` until a Google sign-in has worked ([`firebase-setup.md`](./firebase-setup.md) section 7); then `false` |
| `tokens.cron` | the cron token from section 0 |
| `tokens.metrics` | the metrics token from section 0 |
| `mail.from` | an address **on this domain**, or the host refuses to send |
| `mail.office` | where new leads are reported |

Leave `security.hsts` as `false` for now. Section 9 turns it on, once.

The Google button needs **all four** `auth.firebase_*` values; with any one
missing it is not drawn. You do **not** set `security.csp_script`,
`csp_connect` or `csp_frame` for Firebase: the login page adds Google's
addresses to its own Content-Security-Policy when the four values are set. The
`csp_*` keys are optional extras and normally stay empty.

4. Save.

> Every value is read through `Core\Config`. Anything required and empty stops
> the application with a message naming the *key*, never the value, and that
> message goes to the host's error log, not to a visitor.

---

## 7. Permissions

File Manager → right-click → **Permissions** (or **CHMOD**).

All under `speedat_main/php/` except `public_html/`.

| Path | Mode | Why |
|---|---|---|
| `config/config.php` | **600** | owner read/write only. It holds every secret. |
| `config/` | 700 | |
| `storage/` and everything under it | **700** | the app writes here; nobody else needs to read it |
| `app/`, `bin/`, `db/`, `public/`, and `public_html/` | 755 | |
| every file under them | 644 | |

**Never 777.** LiteSpeed may refuse to execute world-writable PHP, and a
world-writable directory on shared hosting is a directory other accounts may be
able to reach.

If the site 503s after this with a permissions error in the log, try `755` on
`storage/` — a few Hostinger configurations run PHP as a different user than the
one that owns the files. Go up one step at a time; do not jump to 777.

---

## 8. PHP version and `.user.ini`

**hPanel → Websites → your site → Advanced → PHP Configuration**

1. **PHP version**: **8.2** or **8.3**. Not 8.4/8.5 — nothing is known to break,
   but nothing has been run on them either, and go-live is not the moment.
2. **PHP options**: confirm `opcache` on; `zip`, `fileinfo`, `gd` on. `intl` and
   `sodium` may be off; the application guards those and falls back. Note which.

### The `.user.ini` is in the release — you no longer paste it

`public_html/.user.ini` **ships inside the zip** (2026-09-29), alongside
`public_html/.htaccess`, and `bin/build-release.php` refuses to build without
it. Extract the archive and it is already there. Nothing to paste, nothing to
forget, and no install where the body limits are whatever Hostinger's defaults
happen to be.

If you want to change a value, do it in `php/public/.user.ini` and cut a new
release. Editing it in File Manager works and is overwritten by the next
deploy, which is the right way round.

**THE SAME THREE NUMBERS LIVE IN TWO FILES, ON PURPOSE.**

| file | who reads it |
| --- | --- |
| `public_html/.htaccess` (`php_value …`) | **mod_php only.** LiteSpeed and PHP-FPM ignore the whole `<IfModule mod_php.c>` block *silently* — not an error, not a warning; the value simply never applies |
| `public_html/.user.ini` | **the CGI/FastCGI SAPI** — which is what Hostinger runs. Ignored by mod_php, and ignored by `php -S`, which is why it changes nothing locally |

A host reads one or the other and never says which. If the two disagree, the
site's real limit is an accident of this host's SAPI — a number nobody chose.
So they carry the same `upload_max_filesize`, `post_max_size` and
`max_input_vars`, `deploy/htaccess/user.ini.sample` carries them a third time
for pasting by hand, and **`tests/Core/UserIniTest.php` fails if any of the
three drift apart**.

Beside them, `LimitRequestBody 2097152` in both `.htaccess` copies refuses an
over-sized body at the **web server**, before a PHP worker is engaged at all.
It is wrapped in `<IfModule>` so a host that does not accept it degrades to no
cap rather than to a 500, and it sits *below* `post_max_size` so the cheap
refusal is the one that fires.

Three things to know about `.user.ini` on any host:

1. It is cached for **five minutes** (`user_ini.cache_ttl`). Do not conclude it
   did not work for at least that long.
2. It applies to its directory and everything below it, which is why it is in
   `public_html/` and not at the account root.
3. **Only `PHP_INI_PERDIR` and `PHP_INI_ALL` directives take effect.** A
   `PHP_INI_SYSTEM` one — `expose_php`, `max_file_uploads`, `open_basedir`,
   `disable_functions` — is not an error, it is ignored, and the line sits
   there reading like a setting that was made. `UserIniTest` fails if one
   appears. Set those in hPanel, or not at all.
4. hPanel → PHP Configuration applies **immediately** and wins where it sets
   the same directive, so it is the better place for anything it offers.

Full reasoning for all of it, including the OPcache stale-code trap, is in
[`../deploy/htaccess/README.md`](../deploy/htaccess/README.md) and in the
annotated [`../deploy/htaccess/user.ini.sample`](../deploy/htaccess/user.ini.sample).

---

## 9. Domain and HTTPS

**hPanel → Websites → your site**

1. Confirm the domain's document root is `domains/<your-domain>/public_html`.
   It cannot be changed on Web/Cloud plans, which is why the layout in section 1
   is shaped the way it is.
2. **SSL**: Hostinger issues a free certificate automatically and forces HTTPS.
   Confirm it is **Active**. If it is not, *Install SSL* and wait — DNS has to
   point here first.
3. **Do not add your own http→https redirect.** The platform already does it;
   a second one costs every visitor an extra round trip and loops behind a
   TLS-terminating proxy. The rule is in `public_html.htaccess`, commented out,
   with the `curl -I` test that says whether you need it.

Once the site has run cleanly for a week or two, and only then, enable HSTS:
uncomment the `Strict-Transport-Security` line in `public_html/.htaccess`. A
browser remembers it for a year; set it on a domain that still has to answer
plain HTTP for anything and you have locked yourself out for twelve months.
Never add `preload`.

---

## 10. The cron job

One entry. Everything scheduled happens inside it.

**hPanel → Advanced → Cron Jobs → Create New Cron Job**

- **Type**: Custom
- **Command**:
  ```
  /usr/bin/php /home/uXXXXXXXX/domains/<your-domain>/speedat_main/php/bin/cron.php
  ```
- **Schedule**: once an hour (`0 * * * *`)

`speedat_main/php` is not optional in that path. A cron pointed at
`domains/<your-domain>/bin/cron.php` boots a tree with no `config.php` (or an
old one nobody maintains) and fails every hour without the site noticing.

Four things about this field that will otherwise cost you an evening:

1. **Absolute paths only.** Cron has no working directory you can count on and
   none of the web server's environment.
2. **The cron clock is UTC and cannot be changed.** "Once an hour" is fine;
   anything you schedule at a wall-clock time needs converting from PKT.
3. **The field rejects `>`, `2>&1` and `/dev/null`.** Do not add them. The script
   writes to `storage/logs/` itself. If you ever genuinely need redirection, put
   it in a small `.sh` file and have cron call `/bin/sh yourfile.sh`.
4. **`.htaccess` `php_value` lines do not apply to CLI.** `bin/cron.php` sets its
   own limits with `ini_set()` for exactly this reason.

### Why there is no token on this command

`bin/cron.php` is **CLI-only**: it lives above `public_html`, so no URL reaches
it, and `Bootstrap::cli()` refuses to run under a web server. A token would
protect nothing.

`tokens.cron` in the config is for the *other* case — a plan that only offers
URL-triggered cron. Then a route is added that checks the token with
`hash_equals` and runs the same jobs. You do not need it on this plan; you do
need to set the value, because an empty token must never be the thing standing
between the internet and the scheduled jobs.

### Confirm it ran

After the first hour, File Manager → `speedat_main/php/storage/logs/app-<today>.log`. Look for a
`cron_run` line. **Read the PHP version it logs** — `/usr/bin/php` on the CLI is
not documented to match the version the website runs on, and this is the one
place that tells you.

---

## 11. Firebase authorised domains

**Firebase console → Authentication → Settings → Authorised domains → Add
domain**

Add `your-domain` and `www.your-domain` (for this site: `speedat.co` and
`www.speedat.co`). The full Firebase set-up, in the safe order, is
[`firebase-setup.md`](./firebase-setup.md).

Without it, admin sign-in fails with `auth/unauthorized-domain` and nothing in
the PHP logs explains why, because the failure happens in the browser before any
request reaches the server.

---

## 12. Smoke test

In order. Stop at the first failure.

### Anyone can run these

```bash
SITE=https://your-domain

curl -sS  $SITE/health                       # {"ok":true}
curl -sSI $SITE/ | head -1                   # HTTP/2 200
curl -sS -H "Authorization: Bearer $METRICS_TOKEN" $SITE/health
                                             # php version, env, database: up
```

The token goes in the **header**. `/health?token=…` is ignored — the route
reads `Authorization: Bearer` and nothing else, so it answers the bare
`{"ok":true}` and you never see the database line. `$METRICS_TOKEN` is
`tokens.metrics` from `config.php`.

`"database":"up"` in the detailed health answer is the single best check that
section 2, 3 and 6 all agree with each other.

### Then the deny rules — all three must be 403 or 404, never a file

```bash
curl -sS -o /dev/null -w '%{http_code}  /.env\n'                    $SITE/.env
curl -sS -o /dev/null -w '%{http_code}  /router.php\n'              $SITE/router.php
curl -sS -o /dev/null -w '%{http_code}  /config/config.php\n'       $SITE/config/config.php
```

**A `200` on any of these is a stop-everything finding.** Rename
`public_html/index.php` to take the site down, then fix the layout.

### Then the headers

```bash
curl -sSI $SITE/ | grep -iE 'x-content-type|x-frame|referrer|permissions|x-powered-by'
```

No `X-Content-Type-Options` means `mod_headers` is not active here. Not fatal —
the application sets the same headers — but record it, because it decides where
HSTS has to come from.

### Then the application itself

These depend on the phase that is deployed. Phase 1 ships `/` and `/health`;
the admin, the calculator and booking arrive with the later phases.

| # | Do this | Expect |
|---|---|---|
| 1 | Open `/` | the calculator, with prices, no PHP notices |
| 2 | Look for the data-as-of / hold banner | matches what section 4c showed |
| 3 | Sign in at `/admin` with your Google account | the dashboard, not a 403; `storage/cache/google-certs/firebase-certs-*.json` now exists |
| 4 | Sign in with an account **not** in `admins` | refused |
| 5 | Edit the draft and **publish a version** | appears in the history; `SELECT MAX(version)` moves |
| 6 | Price a quote on `/` and **book it** | a row in `leads`, an email to `mail.office` |
| 7 | phpMyAdmin → `SELECT * FROM audit_log ORDER BY id DESC LIMIT 10` | your sign-in, the publish and the booking are all there |

Step 7 is not optional. If the audit log is empty after steps 3–6, the
application is running but not recording, and you will find out the next time
somebody asks who changed a price.

---

## 13. Rollback

Decide this before you need it. It is two restores and they are independent.

**Keep, for every release:**

- the previous release zip (on your machine, and one copy off it),
- a database dump taken **immediately before** the import,
- the SHA-256 the builder printed.

**To roll back the code:**

1. File Manager → `domains/<your-domain>/`, upload the previous release zip.
2. Extract **here**, overwriting. The code lands in `speedat_main/php/`, which
   is what the site runs. **Check the previous zip before you rely on it**:
   open it on your computer, and its top level must hold `speedat_main/` beside
   `public_html/` (its archive comment says *"The app lands in
   speedat_main/php/"*). An older zip with `app/` at its top level does not roll
   the code back: it puts `app/` beside `public_html`, where nothing runs it,
   and only `public_html` changes. A date is not the check - the old builder
   kept producing that layout after the audit that found it.
3. `speedat_main/php/config/config.php` is not in any release zip, so it
   survives untouched — which is exactly why it is not in there.
4. Redo the permissions (section 7): an extract-over re-creates group-writable
   modes on every file it replaces.
5. Reset OPcache (section 14) or wait out the revalidate window. Otherwise you
   will be looking at a mixture of old and new code, which is worse than either.

**To roll back the data:**

1. phpMyAdmin → your database → **Operations → Drop** every table, or import a
   dump that starts with `DROP TABLE IF EXISTS`.
2. Import the pre-import dump.
3. **Clear the site cache**: File Manager →
   `speedat_main/php/storage/cache/site/` → delete the file `stamp` (or every
   `*.json` there). The public pages and the hold are cached for up to five
   minutes and only a publish or the admin hold switch expires them; an import
   behind the application's back does neither.
4. Re-check the counts and the price hold, as in section 4c.

**Roll the code back first, data only if you must.** A newer schema usually
tolerates older data; older code against newer data does not.

**If you only need to stop the bleeding:** turn the price hold on **with the
switch in the admin**. The switch clears the site cache as it saves, so the
site stops quoting and shows the hold message on the next page load. That is a
10-second fix for a wrong-prices incident, and it buys you time to do the real
rollback calmly.

Only if the admin itself is down, phpMyAdmin → SQL:

```sql
UPDATE app_settings
   SET v = JSON_SET(v, '$.on', TRUE), updated_at = UTC_TIMESTAMP(), updated_by = 'rollback'
 WHERE k = 'hold';
```

**Then delete `speedat_main/php/storage/cache/site/stamp` in File Manager.**
SQL does not touch the cache: without this step the site keeps serving the
cached, un-held state for **up to five minutes**, customers keep seeing the
wrong prices, and it looks as if the hold did not work. Deleting the stamp
expires every cached page at once; the next request writes a fresh one.

---

## 14. After every deploy: reset OPcache

OPcache is on by default and **hPanel's Cache Manager does not clear it**. After
a file upload the server can keep serving the previous version of a `.php` file
for up to `opcache.revalidate_freq` seconds — indefinitely if
`validate_timestamps` is off.

This is the most confusing failure on this host: you upload a fix, reload, and
nothing changes. Do not start editing files at random.

Either wait out the revalidate window, or call the token-protected reset
endpoint:

```bash
curl -sS -H "Authorization: Bearer $CRON_TOKEN" https://your-domain/ops/opcache-reset
```

Prefer the header. `?token=` still works on this one route (for the operator
who cannot set a header), but it writes the cron secret into the access log
and every such call is recorded as `ops_token_in_url`. Only `/health` and
`/api/metrics` ignore a query-string token.

(That route arrives with the phase that adds the admin. Until then, waiting is
the only option — which is another reason not to deploy at 6pm.)

---

## 15. Weekly backups

Hostinger's own backups are **weekly on all plans, six weeks retained** — daily
on Unlimited/Cloud. That is the floor, not the plan. Restoring from them is a
support-ticket-shaped process, and six weeks is shorter than the gap between
noticing some data problems and understanding them.

**Every Monday, ten minutes:**

1. **hPanel → Files → Backups → Files backup.** Confirm a backup exists for the
   past week and note its date. Generate one on demand before any deploy.
2. **hPanel → Files → Backups → Database backup.** Same.
3. **Take your own copy of the database.** phpMyAdmin → your database → **Export**
   → *Quick*, format **SQL**, compression **gzipped**. Save it somewhere that is
   not Hostinger and not your Downloads folder — this file contains every
   customer name, phone number and email address the business has.
4. Name it `speedat-db-YYYY-MM-DD.sql.gz` and keep **eight weeks** of them.
   Delete anything older, on purpose, rather than accumulating customer data
   forever on a laptop.
5. **Once a quarter, restore one** into the local MariaDB and open the site
   against it. A backup nobody has restored is a hypothesis.

```bash
# quarterly restore rehearsal, on your machine
gunzip -c speedat-db-2026-09-27.sql.gz > restore.sql
mysql -h 127.0.0.1 -P 3307 -u speedat -p speedat_php_test < restore.sql
```

**Also keep, off the server:** the release zip of whatever is currently live, and
the `config.php` values in a password manager. Hostinger's backup covers the
files; it does not help you if the account itself is the thing you have lost.

---

## Appendix: when something is wrong

| Symptom | Almost always |
|---|---|
| *"This site is not installed yet."* | `speedat_main/php` is not beside `public_html`, or was extracted one level too deep. Section 1. |
| *"This site is not configured yet."* | `speedat_main/php/config/config.php` missing, or a required value is blank. The key's name is in the host's error log — **Files → Error Log**. |
| *"The database is unavailable."* | `db.name`/`db.user` are missing the `u123456789_` prefix, or the password is wrong. |
| Blank page or 500 | Host error log. `display_errors` is Off on purpose; a visitor is never shown a stack trace. |
| The site serves PHP source as text | Catastrophic: the server is ignoring `.htaccess` or the layout is wrong. Take the site down (rename `public_html/index.php`) and re-read section 1. |
| Your fix did not take effect | **First check where it landed**: the site runs `speedat_main/php/app`, not an `app/` beside `public_html` (section 1). Then OPcache, section 14. |
| 403 on one admin form only, nothing in the logs | ModSecurity ate the request before PHP saw it. hPanel → Advanced → ModSecurity; narrow the exception to that one path, never the whole site. |
| Sign-in fails with `auth/unauthorized-domain` | Section 11. |
| Sessions drop constantly | `session.save_path` on shared hosting. See the `.user.ini` sample. |
| Mail never arrives | `mail()` is capped at 10/minute and 100/day, then silently blocked. Switch `mail.transport` to `smtp` and fill in `mail.smtp.*`. |
| A 503 under light load | PHP workers (40 on Premium) or RAM. Check `memory_limit` in `.user.ini` is 128M and not the plan maximum. |
| Timestamps five hours out | The database session is not in UTC, or a `TIMESTAMP` column crept into a migration. Everything stored is UTC; only the display converts. |
