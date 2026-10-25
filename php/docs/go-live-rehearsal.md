# The go-live rehearsal

**29 September 2026.** A release built from the current tree, installed from
nothing into an empty directory and an empty database on the staging server,
and then driven — home page, admin sign-in, a price published and seen by an
anonymous visitor, the cron, `/health`, the permissions, and every URL a
stranger would try in the hope of reading `config.php`.

This is not a summary of the procedure. It is what each step **printed**, and
every place the written procedure was wrong. Where a document has been
corrected, the correction is named.

> **Why rehearse at all.** `php -S` on the development machine reads no
> `.htaccess`, has no `AllowOverride`, and sends any status code it is given.
> The three most useful findings below are all things it cannot show, and one
> of them — **the application's 419 becomes a 500 on real Apache** — would have
> made every expired contact form on the live site answer *Internal Server
> Error*.

---

## 1. What was run, and where

| | |
| --- | --- |
| Host | `admin_tf@110.93.205.230`, the company server. It also runs the owner's Prometheus containers and a Kubernetes node; **nothing outside `~/speedat-staging` was read, started, stopped or changed**, and `sudo` was never used. |
| Scratch directory | `~/speedat-staging/rehearsal/golive-20260929-1206/` — created empty for this run |
| Database | **`speedat_golive`**, a second database with its own user and its own password, created for this run. The staging site's own `speedat` database was not touched. |
| Web | a container from the existing `speedat-staging-web:local` image (Apache 2.4.68, **mod_php**, PHP 8.2.34), published on `127.0.0.1:8086` only, with the scratch directory bind-mounted at `/var/www/site` and a vhost whose `DocumentRoot` is `/var/www/site/public_html` — Hostinger's layout exactly |
| Transfer | `base64` over `ssh` stdin. **No `scp`, no `rsync`**, by instruction; the SHA-256 was compared on both ends. |

**Why a vhost one level inside the mounted directory.** On Hostinger the
document root is `~/domains/<site>/public_html` and cannot be moved on Web or
Cloud plans, so `app/`, `bin/`, `config/`, `db/`, `storage/` and `var/` sit
*beside* it and no URL can reach them. The staging compose file mounts those
four paths separately, which proves things about the code but cannot prove
anything about extracting **one archive into one directory** — which is the
step this rehearsal exists to test.

### Timings

The clock is UTC, taken on the machine that ran each step.

| step | at | took |
| --- | --- | --- |
| build the release (`bin/build-release.php`, **test suite included**) | 12:02:08 → 12:03:57 | **1m 49s** |
| verify it (`deploy/verify-release.php`, 70 checks) | → 12:05:42 | 1m 45s |
| upload 2.0 MiB as base64 over ssh, decode, checksum | 12:06:00 → 12:06:01 | **1s** |
| extract as File Manager would (629 entries) | 12:06:07 | **<1s** |
| create the second database, user and grants | 12:06:32 | **<1s** |
| fill `config/config.php` from the sample | 12:06:41 → 12:06:53 | 12s |
| permissions (644/755, 700 on writables, 600 on config) | 12:07:01 | **<1s** |
| migrate (2 migrations, 17 statements) | 12:07:08 | **<1s** |
| seed the first owner | 12:07:24 | **<1s** |
| start the web container | 12:07:33 → 12:07:37 | 4s |
| prove the installed copy over HTTP (15 steps) | 12:09:14 → 12:09:15 | **1s** |
| upgrade in place: rebuild, re-upload, extract over the top | 12:11:11 → 12:13:44 | 2m 33s |
| cron, with its environment line | 12:13:51 | **2ms of work** |

**From the archive landing on the server to a proven, signed-in, publishing
site: 3 minutes 15 seconds.** Everything longer than that happened on the
development machine — building and verifying — and the build is now 1m 49s
because it runs the whole test suite first.

---

## 2. What each step printed

### 2.1 Build

```
tests  : running bin/test.php (use --skip-tests to build without it)
...
  RESULT: PASS
tests  : PASS

files        : 623
directories  : 6
uncompressed : 4.42 MiB
zip          : 1.97 MiB  (55% smaller)
sha256       : 57d67b3f9939fc1317b2baf33954bed4b56075e5f6e3a7c5f2698fa09738956a
```

`deploy/verify-release.php` then read the archive back:

```
70 check(s): 70 passed, 0 failed

The archive is safe to upload.
```

> **CORRECTION.** `deploy-hostinger.md` carried a boxed warning saying the
> verifier *"currently fails every archive"* with
> `FAIL no config.php (real configuration) app/Core/Config.php`. That is no
> longer true — a clean build passes all 70 checks. The warning has been
> replaced with a statement that the false positive is fixed, because a
> document that tells you to expect a failure teaches you to ignore one.

### 2.2 Upload and extract

```
57d67b3f9939fc1317b2baf33954bed4b56075e5f6e3a7c5f2698fa09738956a  release.zip   (matches the build)

entries in the archive : 629
files on disk          : 623
directories on disk    : 47
modes as extracted:
  dir  0o775  x47
  file 0o664  x623

top level: INSTALL.txt  app  bin  config  db  public_html  storage  var
```

`INSTALL.txt` now states its own provenance, which is new:

```
golive.zip, built 2026-09-29 12:03:56 UTC.
Test suite: PASSED at build time (bin/test.php, run by the builder).
```

Both dotfiles arrived in the web root, which is the point of shipping the
second one:

```
-rw-rw-r-- 1 admin_tf admin_tf 12886 .htaccess
-rw-rw-r-- 1 admin_tf admin_tf  4934 .user.ini
-rw-rw-r-- 1 admin_tf admin_tf  3786 index.php
```

…and all twelve deny-all guards:

```
app/.htaccess          bin/.htaccess          config/.htaccess
db/.htaccess           db/migrations/.htaccess public_html/.htaccess
storage/.htaccess      storage/cache/.htaccess storage/logs/.htaccess
storage/tmp/.htaccess  storage/uploads/.htaccess var/.htaccess
```

> **FINDING — extraction does not produce 644/755, and no document said so.**
> The tree came out **664/775**, not 644/755: an extract is written with the
> account's umask, and this account's is `002`. The checklist's item 7 asks for
> 644/755 as if the archive carried modes; it does not, and on Windows there
> are none worth carrying. `chmod` is therefore not a tidy-up, it is the step
> that creates the permissions — and it has to run **after every extract**,
> including an upgrade. That is now written where it belongs, in the checklist
> and in this document's recipe below.

### 2.3 The second database

```
mariadb_version
11.8.9-MariaDB-ubu2404

Grants for speedat_golive@%
GRANT USAGE ON *.* TO `speedat_golive`@`%` IDENTIFIED BY PASSWORD '***'
GRANT ALL PRIVILEGES ON `speedat_golive`.* TO `speedat_golive`@`%`
```

The password was written to a `600` file and the SQL piped from another `600`
file. **No secret was ever a command-line argument** — arguments are readable
in `/proc` by every other process, and this box is shared with the owner's
dashboard and a Kubernetes node.

### 2.4 Migrate

```
Database : speedat_golive on speedat-staging-db:3306
Migrations: /var/www/site/db/migrations

  + 0001_init.sql (12 statements) ... ok
  + 0002_session_csrf_secret.sql (5 statements) ... ok

2 migration(s) applied.
```

And the schema, counted:

```
admins  app_settings  audit_log  draft  leads  quotes  rate_limits
schema_migrations  sessions  shipments  shipment_events  versions

tables_found     12
has_csrf_secret   1
```

> **CORRECTION.** `go-live-checklist.md` item 5 said *"this, which must list all
> **thirteen** tables"*. There are **twelve**. The thirteenth was `imports`,
> which left with the Excel rate-sheet feature on 2026-09-27;
> `deploy-hostinger.md` was corrected that day and the checklist was not. A
> checklist that says thirteen makes you abandon a correct install. It now says
> twelve and lists them by name.

### 2.5 Seed the first owner

```
Seeding speedat_golive
  + app_settings[hold]
  + version 1 published (sample rates, not flagged live)
  + rehearsal.owner@speedat.test added as owner
```

A second run, with `SPEEDAT_SEED_PASSWORD` read from a `600` file **inside the
container** so the value never appears in a host process list:

```
  ~ password set (argon2id)
```

### 2.6 First contact with the installed site

```
/health   200  {"ok":true}        13ms
/         200  18,312 bytes       18ms
```

…and it carried real prices, not placeholders:

```
from PKR 3,500   from PKR 3,650   from PKR 4,550   from PKR 4,680
from PKR 5,250   from PKR 5,380   from PKR 5,500      (18 distinct amounts)
```

### 2.7 The admin, end to end

The first attempt did not get past the login page:

```
  ok   GET /admin/login            200, csrf MISSING
  FAIL login page offers a password field
  FAIL the real password signs in
```

> **FINDING — a fresh install has NO WAY IN, and no document says so.**
> `config.sample.php` ships `'allow_password_login' => false`, and
> `firebase_project_id` empty. With both unset,
> `Http\Admin\LoginController::render()` prints no form at all — only the
> sentence *"Sign-in is not configured on this deployment yet."* That is the
> code being honest; the **procedure** is what is wrong. `go-live-checklist.md`
> items 15–17 walk the operator through creating an owner and then signing in,
> and never mention that one of the two sign-in methods must be switched on
> first. An installer who is not using Firebase seeds an owner, opens
> `/admin`, and finds a page with no password box and no explanation of which
> file to edit.
>
> Setting `'allow_password_login' => true` in `config/config.php` produced the
> form immediately (no OPcache reset needed — the file was newer than the
> cached entry).

With it on, every step passed:

```
  ok   GET / renders                      200, 18312 bytes
  ok   GET / carries prices               18 distinct PKR amounts
  ok   GET /health                        {"ok":true}
  ok   GET /admin/login                   200, csrf found
  ok   login page offers a password field
  ok   a wrong password is refused        HTTP 401 -> no redirect
  ok   the real password signs in         HTTP 303 -> /admin
  ok   GET /admin is the dashboard        HTTP 200, 67489 bytes
  ok   the editor carries the rate fields changing dest[gb][rates][express][first] from 4500 to 4321
  ok   POST the changed draft             HTTP 303 -> /admin?saved=1&base=1
  ok   GET the review screen              HTTP 200, expectedBase=1
  ok   POST publish with "rates are live" HTTP 303 -> /admin?published=2
  ok   the public home page still renders HTTP 200, 18226 bytes
  ok   the published price is on the public page   looking for 4321
  ok   the "sample prices" notice is gone
------------------------------------------------------------------------
every step passed
```

The price a signed-in owner typed reached an anonymous visitor's page. That is
the whole product in one line.

The audit log agrees, which is checklist item 24 and is not optional:

```
2026-09-29 12:13:51  cron_run       system
2026-09-29 12:09:24  cron_run       system
2026-09-29 12:09:15  publish        rehearsal.owner@speedat.test
2026-09-29 12:09:15  draft_saved    rehearsal.owner@speedat.test
2026-09-29 12:09:15  login          rehearsal.owner@speedat.test
2026-09-29 12:09:14  login_refused  rehearsal.owner@speedat.test
2026-09-29 12:08:28  seed_run       system
2026-09-29 12:07:24  seed_run       system
```

### 2.8 Upgrade in place, over the running install

Rather than hand-patch one file, the corrected tree was rebuilt, re-uploaded
and extracted **over the top** of the running install — which also rehearses
checklist item 28.

```
46fe112e881f836adf33a0450c77c222f1909c840f86223368dbff70311aa7f4  release2.zip
entries in the archive : 629

config.php survived:  600 config/config.php  8224 bytes
                      allow_password_login => true   (still 1 match)
/         200
/health   200  {"ok":true}
```

`config/config.php` is not in the archive, so it survives an extract-over. That
is the rollback story in `INSTALL.txt`, and it is now a measured fact rather
than a claim.

### 2.9 Cron

```
  php  8.2.34  (cli, /usr/local/bin/php)
  ext  pdo_mysql mbstring json openssl gd fileinfo sodium
  --   not loaded: zip intl

  ok  sessions      0 expired session(s) removed
  ok  rate_limits   0 old window(s) removed
  ok  logs          0 log file(s) removed

Finished in 2ms with 0 failure(s).
```

and in `storage/logs/app-2026-09-29.log`:

```json
{"ts":"2026-09-29T12:13:51.509Z","level":"info","event":"cron_environment",
 "php":"8.2.34","sapi":"cli","binary":"/usr/local/bin/php",
 "loaded":["pdo_mysql","mbstring","json","openssl","gd","fileinfo","sodium"],
 "missing":["zip","intl"],
 "memory":"128M","maxExecute":"0","timezone":"Asia/Karachi"}
```

> **FINDING — the checklist asked for evidence the cron could not produce.**
> Item 14's evidence line reads: *"a `cron_run` line in
> `storage/logs/app-<today>.log` … **and the PHP version on its environment
> line** (CLI PHP is not documented to match the website's)."* There was no
> environment line. The first cron run of this rehearsal printed three job
> summaries and nothing else, and the item could not be ticked.
>
> `bin/cron.php` now logs `cron_environment` **before** the jobs — version,
> SAPI, binary path, the nine extensions this application would miss, the
> memory and time limits, and the timezone. Before the jobs, deliberately: a
> run that dies inside a job still leaves the one fact that explains which
> interpreter it died in. If `pdo_mysql` is absent it also says so on stderr in
> plain words, because that is the failure where the website looks perfectly
> healthy, the cron entry "runs", and every sweep silently does nothing until
> the disk quota takes the site down.
>
> `tests/Ops/CronEnvironmentTest.php` fails if the line goes away, or if it
> moves to after the jobs.

Two details worth keeping: `max_execution_time` is **0** under CLI (no limit,
which is correct and is why cron does the slow work), and the timezone the
application sets itself is `Asia/Karachi` while everything stored is UTC.

### 2.10 Permissions, as finally set

```
  1 file  600   (config/config.php)
627 files 644
  6 dirs  700   (storage, storage/{cache,logs,tmp,uploads}, var)
 43 dirs  755

world-writable: none
```

### 2.11 Every URL a stranger would try

```
/                      -> 200      /config/config.php     -> 404
/services              -> 200      /.user.ini             -> 403
/about                 -> 200      /.htaccess             -> 403
/contact               -> 200      /.env                  -> 403
/faq                   -> 200      /INSTALL.txt           -> 404
/health                -> 200      /router.php            -> 403
/admin                 -> 302      /app/Core/Config.php   -> 404
/admin/login           -> 200      /db/migrations/0001_init.sql -> 404
/sitemap.xml           -> 200      /../config/config.php  -> 404
/robots.txt            -> 200      /config.php            -> 404
```

`/config/config.php` returns the site's own **404 page** — it is outside the
document root, so there is no file for Apache to refuse; the URL simply is not
a route. The `403`s are the `.htaccess` deny rules firing, and Apache's error
log proves they were *rules* and not absences:

```
AH01630: client denied by server configuration: /var/www/site/public_html/.user.ini
AH01630: client denied by server configuration: /var/www/site/public_html/.htaccess
AH01630: client denied by server configuration: /var/www/site/public_html/router.php
AH01630: client denied by server configuration: /var/www/site/public_html/.env
```

The body of `/config/config.php` was read to be certain: it is the contact-less
404 page, `<!doctype html>…`, with no PHP source in it.

### 2.12 The body caps, measured rather than read

```
POST 3,000,000 bytes -> 413      (Apache: LimitRequestBody 2097152)
POST 9,000,000 bytes -> 400      (and PHP logged the reason, below)
```

```
PHP Warning:  PHP Request Startup: POST Content-Length of 9000000 bytes
exceeds the limit of 3145728 bytes in Unknown on line 0
```

3,145,728 is `3M` — the `php_value post_max_size` in `.htaccess`, applied
because **this host runs mod_php** (`apache2ctl -M` → `php_module (shared)`).

> **This is the whole argument for shipping both files, proved.** On *this*
> host the `.htaccess` `php_value` pair is what applies and `.user.ini` is
> ignored. On Hostinger — LiteSpeed through LSAPI — it is the other way round
> and the `php_value` block is ignored **silently**, with no error and no
> warning. Ship one of the two and the live site's body limit is whatever the
> plan's default is, chosen by nobody. They now carry the same three numbers,
> and `tests/Core/UserIniTest.php` fails if they drift apart.

---

## 3. Findings, ranked

### 3.1 BLOCKER — the application's `419` becomes `500` on real Apache

Every CSRF refusal — an expired contact form, a stale admin login page —
answers **500 Internal Server Error** on the installed copy. Measured:

```
POST /admin/login/password (no token, app picks 419) -> 500
POST /contact              (no token, app picks 419) -> 500
GET  /nope                 (app picks 404)           -> 404
GET  /api/metrics          (app picks 401)           -> 401
```

The application is choosing correctly. `Http\ContactController` logs
`contact_csrf_rejected` and returns `419`; the same request against the
development server answers **419**. `404` and `401` pass through untouched on
both. It is the *transport* of that one code that breaks: `Core\Response::send()`
calls `http_response_code(419)`, and Apache has no reason phrase for 419 — a
non-registered status — so it substitutes a 500.

**Why this matters more than it looks.** The body is right: the visitor sees
the contact page with *"that form expired, here it is again"* on it. Only the
status line lies. So nobody notices by looking — and meanwhile every uptime
monitor, every error budget and every log filter counts a customer's expired
form as the site falling over. `php -S` cannot show this, because it has no
status table; **only a rehearsal on real Apache can.**

Hostinger runs LiteSpeed, which emulates Apache's semantics. Assume it does the
same until measured.

This is in `app/Core/Response.php` and the controllers that pick `419`, which
are outside this task's ownership. It is written up in full so whoever owns it
can fix it without repeating the measurement. The shape of the fix is either an
explicit status line with a reason phrase, or a registered status code.

### 3.2 MAJOR — a fresh install has no way to sign in, and the procedure never says so

`config.sample.php` ships `allow_password_login => false` and
`firebase_project_id => ''`. A correct install of a correct release therefore
renders a login page with **no form on it**, and the checklist's items 15–17
walk you straight into it. See §2.7. The fix is one line in `config.php`, and
the cost of not knowing it is an hour with a working site you cannot get into.

`go-live-checklist.md` needs a step between "create the first admin" and "the
owner signs in": *choose a sign-in method and switch it on*. That checklist is
outside this task's ownership; the finding is recorded here and in the report.

### 3.3 MINOR — `/api/metrics` refuses a `Bearer` token and accepts `?token=`

```
Authorization: Bearer <token>   -> 401
?token=<token>                  -> 200
```

`app/routes.php` documents both: *"Bearer token (or `?token=`, which the runbook
uses)"*. Only the query form works. Whoever fixes it should decide which is
intended — a token in a query string is in the access log of every proxy
between here and the caller.

### 3.4 MINOR — `deploy/htaccess/user.ini.sample` described an endpoint that does not exist

The sample said: *"The application's `/health` endpoint reports the live values
when called with the metrics token."* It does not. `/health` answers
`{"ok":true}` and nothing else, by design, and `/api/metrics` is a Prometheus
text endpoint with no `ini` values in it. **Corrected** — the sample now says
how to prove the file applied without publishing a `phpinfo.php`.

### 3.5 MINOR — extraction produces 664/775, not 644/755

See §2.2. **Corrected** in `go-live-checklist.md` and in §4 below: the `chmod`
is the step that *creates* the permissions and it runs after every extract,
including an upgrade.

### 3.6 Noted, not a defect — the rehearsal's own mechanics

The first attempt to write `config/config.php` used a container running as
root, and the resulting file could not be `chmod`'d by `admin_tf`
(`Operation not permitted`). Re-running with `--user $(id -u):$(id -g)` fixed
it. On Hostinger the question does not arise — File Manager runs as the account
— but any future containerised rehearsal should pass the uid.

---

## 4. The procedure, as it should actually be written

Corrected against this run. This is the short form; `deploy-hostinger.md` has
the full one.

1. **Build and verify on the development machine.**
   `php bin/build-release.php` — it now runs the test suite first and refuses
   to build from a red tree. Then `php deploy/verify-release.php <zip>`; it must
   end with *"The archive is safe to upload"*.
2. **Create the database and user** in hPanel, and note the **prefixed** names.
3. **Upload the zip to `~/domains/<site>/` and Extract there — not inside
   `public_html`.**
4. **`chmod` immediately.** The extract does *not* set modes; your umask does.
   Directories 755, files 644, `storage/` and `var/` 700, and `config.php` 600
   once it exists. **Repeat this after every upgrade.**
5. **Import `db/migrations/0001_init.sql` and every later `00NN_*.sql`** in
   phpMyAdmin. Then count the tables: **twelve**.
6. **Copy `config/config.php` from the sample** and fill `app.key`, `app.url`,
   `db.*`, `tokens.cron`. Then `chmod 600`.
7. **Choose a sign-in method and switch it on** — `auth.firebase_project_id`
   for Google, or `auth.allow_password_login => true` for password + TOTP.
   **Without one of these the login page has no form on it.** (§3.2)
8. **Create the first owner** — `bin/seed.php --owner=<email>`, with
   `SPEEDAT_SEED_PASSWORD` set if you chose passwords.
9. **Add the hourly cron entry** and run it once by hand. Its first line names
   the PHP version and the extensions; if `pdo_mysql` is missing, the cron is
   pointed at the wrong PHP build and nothing it reports below that line means
   anything.
10. **Walk the site**: `/health`, `/`, sign in, publish a price, load the public
    page as a stranger and find that price. Then try to read `config.php` over
    HTTP and record the status.

---

## 5. What this rehearsal did not cover

Said plainly, because a rehearsal that implies more than it did is worse than
none.

- **LiteSpeed.** This is Apache with mod_php. Hostinger is LiteSpeed through
  LSAPI, which reads `.htaccess` with Apache semantics but is not Apache. The
  two places it matters are §2.12 (which of the two ini files applies — the
  *opposite* one applies there) and §3.1 (whether LiteSpeed also turns 419 into
  500).
- **HTTPS, the real domain, and Firebase.** `app.url` was
  `http://127.0.0.1:8086`, so the canonical, the sitemap and the `robots.txt`
  `Sitemap:` line all printed that host — correctly, which is exactly what the
  browser harness's SEO checks were taught to accept this week. Nothing here
  proves the HSTS header, the certificate, or a Google sign-in.
- **Email.** No SMTP was configured, so no contact notification was sent.
- **Real data volume.** Twelve empty tables and one published version.
- **The hPanel interface itself.** The extraction, the database creation and the
  cron entry were reproduced faithfully but not clicked through in hPanel.
