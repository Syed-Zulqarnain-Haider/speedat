# Speedat runbook

Day-to-day operation of the live PHP site on Hostinger shared hosting. The
**first** install is a different document: [`deploy-hostinger.md`](./deploy-hostinger.md),
sections 0–15. This one assumes the site is already up.

What the owner does is [`admin-guide.md`](./admin-guide.md). Do not send him here.

No SSH, no Composer, no build step on the host. Everything below is hPanel, File
Manager, phpMyAdmin, or `curl` from your own machine.

---

## 1. Where everything is

```
/home/uXXXXXXXX/domains/<domain>/
├── public_html/     ← the ONLY directory the web serves
│   ├── index.php        front controller
│   ├── .htaccess        rewrite + deny rules
│   ├── .user.ini        PHP limits (pasted by hand, see §9)
│   └── assets/          css, js, fonts, flags
└── speedat_main/php/ ← THE APPLICATION. The site runs from here.
    ├── app/             ← code
    ├── bin/             ← cron.php
    ├── config/          ← config.php  (600, the only copy of every secret)
    ├── db/migrations/   ← the schema
    ├── public/          ← a copy of public_html the code reads from
    ├── storage/         ← cache/ logs/ tmp/ uploads/   (700, writable)
    └── var/
```

**Every path in this runbook that is not under `public_html` is under
`speedat_main/php/`** — `config/config.php` means
`domains/<domain>/speedat_main/php/config/config.php`.

`speedat_main/php` is the **sibling** of `public_html`, not inside it, so no URL
reaches it. Each directory in it also ships an `.htaccess` with
`Require all denied` as a second lock. If you ever see it *inside*
`public_html`, stop: `config/config.php` is being published. Rename
`public_html/index.php` to take the site down and redo
[`deploy-hostinger.md`](./deploy-hostinger.md) §5.

`public_html/index.php` runs the application from `speedat_main/php` whenever
that folder exists. An `app/`, `config/` or `bin/` lying directly in
`domains/<domain>/` — what older releases produced — is a
tree **nothing runs**: a fix uploaded there never executes, and an edit to a
`config.php` there changes nothing. Delete such leftovers once you have
confirmed the live install is the one in `speedat_main/php`.

| What | Where |
|---|---|
| Files, permissions, backups | hPanel → Files → File Manager / Backups |
| Database | hPanel → Databases → phpMyAdmin (`localhost:3306`, prefixed names) |
| PHP version and extensions | hPanel → Websites → your site → Advanced → PHP Configuration |
| Cron | hPanel → Advanced → Cron Jobs |
| ModSecurity | hPanel → Advanced → ModSecurity |
| The application's own log | `storage/logs/app-<YYYY-MM-DD>.log` (UTC date) |
| PHP fatals before the app starts | hPanel → Files → Error Log |
| Outgoing mail log | `/home/uXXXXXXXX/.logs/exim.log` |
| Who may sign in | `admins` table; sign-in itself is Firebase |
| Secrets | `config/config.php` on the host, and a password manager. Nowhere else. |

---

## 2. Deploy an update

Ten minutes, drag and drop. Not at 6pm — see §9.

**On your machine:**

```bash
cd /d/speedat/php
/d/php/php.exe bin/build-release.php
/d/php/php.exe deploy/verify-release.php var/speedat-release-<stamp>.zip
```

The builder writes `var/speedat-release-<stamp>.zip` and prints the file count,
the size and a SHA-256. **Keep the SHA-256.** The verifier unpacks the zip and
runs its checks against what actually landed: no `config.php`, no `.env`, no
tests, no logs, every non-public directory guarded, every shipped `.php` parses.
If it does not end with **"The archive is safe to upload"**, stop. Do not upload
it to see what happens.

**On the host:**

1. **Take a database backup first** — hPanel → Files → Backups → *Database
   backup* → generate now, and a phpMyAdmin export as well (§6). This is the copy
   you roll back to.
2. Keep the **previous** release zip somewhere you can find it in two minutes.
3. File Manager → `domains/<domain>/` (the level with `public_html`, **not**
   inside it) → **Upload** the zip. It carries `public_html/` and
   `speedat_main/php/` and replaces the files in both.
4. Compare the uploaded size with what the builder printed. File Manager
   truncates large uploads on a flaky connection and says nothing.
5. Right-click the zip → **Extract** → **here**, overwriting.
6. Delete the zip from the server. It is a complete copy of the application
   sitting above the web root. Then **redo the permissions**
   ([`deploy-hostinger.md`](./deploy-hostinger.md) §7): an extract-over
   re-creates group-writable modes on every file it replaces.
7. **Reset OPcache** (§9). Skipping this is how you end up debugging a mixture of
   old and new code.
8. Smoke test (§4). Two minutes.

`config/config.php` is not in any release zip, so it survives every deploy
untouched. That is why it is not in there.

**If the release contains a new migration**, import it in phpMyAdmin →
your database → **Import** → `db/migrations/00NN_*.sql` **before** step 7. Every
statement is `IF NOT EXISTS`, so importing twice is safe. There is no shell, so
`bin/migrate.php` cannot be run on the host; phpMyAdmin is the path.

---

## 3. Roll back

Two restores, independent. **Code first; data only if you must** — a newer schema
usually tolerates older data, older code against newer data does not.

**Code** (about three minutes): upload the previous release zip to
`domains/<domain>/`, extract **here** overwriting, delete the zip, redo the
permissions, reset OPcache (§9), smoke test. The previous zip must be one that
writes `speedat_main/php/`: its top level holds `speedat_main/` beside
`public_html/`, and its archive comment says "The app lands in
speedat_main/php/". Check the zip, not its build date. An older zip has `app/`
at its top level and puts it beside `public_html`, so extracting it rolls back `public_html` and
leaves the running code exactly where it was.

**Data**: phpMyAdmin → your database → drop the tables (or import a dump that
starts with `DROP TABLE IF EXISTS`) → import the pre-deploy dump → **delete
`storage/cache/site/stamp`** in File Manager → re-check the counts and the
`hold` row (§6). The public pages and the hold are cached for up to five
minutes, and an import behind the application's back does not expire them;
deleting the stamp does.

**To stop the bleeding in ten seconds** — prices are wrong and you are not ready
to do either of the above. Put the site on hold **from the admin** (*Rates* →
*Hold prices*). The switch clears the site cache as it saves, so the next page
load is held.

Only if the admin itself is the problem, phpMyAdmin → SQL:

```sql
UPDATE app_settings
   SET v = JSON_SET(v, '$.on', TRUE), updated_at = UTC_TIMESTAMP(), updated_by = 'rollback'
 WHERE k = 'hold';
```

**and then delete `storage/cache/site/stamp`** in File Manager. SQL does not
touch the cache: without that step the site keeps serving the cached, un-held
state for **up to five minutes**, and it looks as if the hold did not work.

The site stops quoting and shows the hold message instead. Customers can still
reach you on WhatsApp. Then do the rollback calmly.

**Rolling back prices is not a deploy at all.** Admin → *History* → *Restore into
editor* → *Review and publish*. Every publish is kept as an immutable row in
`versions`, so "restore the rates" never needs a database restore.

---

## 4. Health, metrics, and what a bad answer means

```bash
SITE=https://your-domain

curl -sS  $SITE/health                                         # {"ok":true}
curl -sS -H "Authorization: Bearer $METRICS_TOKEN" $SITE/health      # the detail
curl -sS -H "Authorization: Bearer $METRICS_TOKEN" $SITE/api/metrics
```

The token goes in the **header** for both. `/health?token=…` is ignored — the
route reads `Authorization: Bearer` and nothing else — so it answers the bare
`{"ok":true}` and never shows `"database"`. `$METRICS_TOKEN` is
`tokens.metrics`.

`/health` answers the same status code either way, so an uptime monitor calling
the public form still goes red. Point one at it every five minutes.

| Answer | Means |
|---|---|
| `200` `{"ok":true}` | PHP is running and the database answered |
| `503` | **down**: the database is unreachable, or a required extension (`json`, `pdo_mysql`, `mbstring`, `openssl`) is missing. Customers see no prices. |
| `200` with `"status":"degraded"` | serving customers, but something you must fix: a `storage` directory it cannot write to. Deliberately not a 503 — nobody should be paged at 3am for a read-only log directory. |
| connection refused / Hostinger error page | the host, not the application. hPanel → Files → Error Log. |

The detailed answer is the one to read during an incident. It carries
`status`, `php`, `env`, `database`, `databaseMs`, `rates` (`version`,
`publishedAt`, `ageSeconds`, `live`), `hold` (`on`, `since`, `seconds`),
`storage` (`logs`, `cache`, `tmp` — writable true/false), `extensions` and
`missing`. Two readings worth knowing:

- **`rates.ageSeconds: -1`** means nothing has ever been published. That is not
  "published a second ago", and an alert rule must tell them apart.
- **`php`** is the version the **website** runs. The cron may run a different
  one; only the log says (§5).

It never names a file, a host, a driver or a query. The strongest thing it will
ever say about the database is `down`; the reason is in `storage/logs`.

**`/api/metrics`** is Prometheus text, computed fresh on every scrape (there is
no long-lived process to hold counters). It needs the bearer token;
`tokens.metrics` falls back to `tokens.cron` if it is blank. Worth alerting on:

| Gauge | Alert when |
|---|---|
| `speedat_rates_publish_age_seconds` | older than 7 days (prices nobody has refreshed) |
| `speedat_prices_held_seconds` | over ~12 h (a hold nobody lifted; the cron also emails) |
| `speedat_notify_failed_24h` | above 0 (mail is failing — see §10) |
| `speedat_shipments_exception` | above 0 for a working day |
| `speedat_leads_new` | climbing with nobody working the inbox |
| `speedat_rates_version` | it moved and you did not expect a publish |

`/health` ships in every release. `/api/metrics` and `/ops/opcache-reset` come
with the `Domain\Ops` routes — if one answers 404, that surface is not in the
release you uploaded, and §9 tells you what to do about OPcache meanwhile.

---

## 5. The cron job

**One** hPanel entry. Everything on a timer happens inside it.

- hPanel → Advanced → Cron Jobs, **hourly** (`0 * * * *`)
- Command:
  ```
  /usr/bin/php /home/uXXXXXXXX/domains/<domain>/speedat_main/php/bin/cron.php
  ```
  Without `speedat_main/php` in that path the cron boots a tree with no
  `config.php` (or an abandoned one) and fails every hour, silently.

Four things about that field, each of which costs an evening:

1. **Absolute paths only** — cron has no working directory and none of the web
   server's environment.
2. **The cron clock is UTC and cannot be changed.**
3. **The field rejects `>`, `2>&1` and `/dev/null`.** Do not add them; the script
   writes to `storage/logs/` itself.
4. **`.htaccess` and `.user.ini` do not apply to CLI.** The script sets its own
   limits with `ini_set()`.

`bin/cron.php` is CLI-only: it lives above `public_html` and refuses to run under
a web server, so no URL reaches it and it needs no token. `tokens.cron` exists
for a plan that can only fetch a URL, and for `/ops/opcache-reset`; set it
anyway — an empty token must never be the only thing between the internet and a
scheduled job.

What runs: the housekeeping in `bin/cron.php` (expired sessions, old rate-limit
windows, logs over 30 days) and the checks in `app/Domain/Ops/Cron.php` (rates
older than 7 days, a hold longer than 12 hours — each emailed at most once a day
— the day's numbers, and the cache sweep). Every job is wrapped: one that fails
is recorded and the rest still run, and the run exits non-zero.

**Confirm it ran**: `storage/logs/app-<today>.log`, look for `cron_run`. Read the
PHP version on the environment line — `/usr/bin/php` is not documented to match
the website's version, and this is the only place that tells you.

---

## 6. Backups and restore

Hostinger's own backups are **weekly, six weeks retained** on all plans (daily on
Unlimited/Cloud) and restoring from them is a support-ticket-shaped process.
Treat that as the floor.

**Every Monday, ten minutes:**

1. hPanel → Files → **Backups** → *Files backup*: confirm one exists, note the
   date. Generate one on demand before any deploy.
2. Same for *Database backup*.
3. **Your own copy**: phpMyAdmin → your database → **Export** → *Quick*, format
   **SQL**, compression **gzipped**. Name it `speedat-db-YYYY-MM-DD.sql.gz`.
4. Keep it somewhere that is neither Hostinger nor a Downloads folder, and keep
   **eight weeks**. This file contains every customer name, phone number and
   email address the business has. Delete older ones on purpose.

**Once a quarter, restore one** into the local MariaDB and open the site against
it. A backup nobody has restored is a hypothesis.

```bash
gunzip -c speedat-db-2026-09-27.sql.gz > restore.sql
mysql -h 127.0.0.1 -P 3307 -u speedat -p speedat_php_test < restore.sql
```

**After any restore, check the two rows that decide what customers see:**

```sql
SELECT MAX(version) FROM versions;              -- the version the site serves
SELECT k, v FROM app_settings WHERE k='hold';   -- the price hold
```

The hold is the setting that is invisible until it is wrong. A restore that turns
it off puts prices back on the site that the owner had deliberately taken down.

Also keep off the server: the release zip of whatever is currently live, and the
`config.php` values in a password manager. Hostinger's backup covers files; it
does not help if the account itself is what you have lost.

---

## 7. Rotating secrets

All of them live in `config/config.php` (mode 600). Edit in File Manager, save,
**reset OPcache** (§9), then re-run the §4 smoke test.

| Secret | Effect on users | How |
|---|---|---|
| `db.pass` | none if done in one sitting | hPanel → Databases → change the user's password → paste into `config.php` |
| `tokens.metrics` | none | new value; update the uptime monitor and any scraper |
| `tokens.cron` | none | new value; it is also the OPcache-reset token |
| `mail.smtp.pass` | mail stops until updated; the Security page shows the red light | the mailbox provider → paste in |
| `app.key` | **every admin's 2FA stops working**, open forms are refused for a minute, rate-limit counters reset | last resort — see below |

`app.key` encrypts the stored TOTP secrets and derives the CSRF key and the
IP-hash salt. Rotating it makes every stored secret undecryptable and there is no
recovery. Do it only after a suspected compromise, and in this order: tell the
admins, rotate, clear `totp_secret_enc` / `totp_enabled` for everyone, have each
admin re-enrol under *Security* (thirty seconds each).

```sql
UPDATE admins SET totp_secret_enc = NULL, totp_enabled = 0;
```

There is **no Firebase service-account key on the server** — ID tokens are
verified against Google's published certificates — so there is nothing to rotate
there. Generate new values with
`php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"` (tokens) or
`php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` (`app.key`). Never in a
chat message, a ticket, or a file under `public_html`.

---

## 8. Logs

| Log | Where | Holds |
|---|---|---|
| Application | `storage/logs/app-<YYYY-MM-DD>.log` | one JSON line per event: `cron_run`, failures with the real reason, the daily environment line. Pruned at 30 days by cron. |
| PHP fatals | hPanel → Files → **Error Log** | anything that died before the application's own handler, and every misconfiguration message |
| Mail | `/home/uXXXXXXXX/.logs/exim.log` | whether a message left the server |
| Who did what | `audit_log` table, newest last; the admin shows the last forty under *Security* | sign-ins, publishes, holds, admin actions, with a **hashed** IP |

The application never prints a stack trace, a path, a query or a driver message
to a visitor — `display_errors` is Off in `.user.ini` and the code enforces it
again. When a visitor reports "an error", the detail is in one of the first two
rows above, not on their screen.

`audit_log` is append-only. If the two triggers from
[`deploy-hostinger.md`](./deploy-hostinger.md) §3 were installed, the *database*
refuses `UPDATE` and `DELETE` on it; a maintenance job that needs to trim it must
drop them deliberately and say so.

---

## 9. OPcache, after every upload

OPcache is on by default and **hPanel's Cache Manager does not clear it**. After
an upload the server can keep serving the previous version of a `.php` file for
up to `opcache.revalidate_freq` seconds — indefinitely if `validate_timestamps`
is off. You upload a fix, reload, and nothing changes.

**First check the upload landed where the site runs**: the code is
`speedat_main/php/app/`, not an `app/` beside `public_html` (§1). A fix in the
wrong tree never executes, and it looks exactly like this. Then, **do not
start editing files at random.** Either:

```bash
curl -sS -H "Authorization: Bearer $CRON_TOKEN" https://your-domain/ops/opcache-reset
```

(prefer the header: `?token=` still works on this route, but it writes the cron
secret into the access log and is recorded as `ops_token_in_url`)

or wait out the revalidate window. That route arrives with the `Domain\Ops`
routes; while it answers 404, waiting is the only option — which is the other
reason not to deploy at 6pm.

`.user.ini` has its own delay: PHP caches it for `user_ini.cache_ttl` seconds
(300 by default), so do not conclude an edit failed for at least five minutes.
Prove the live values with the detailed `/health`; **never** create a
`phpinfo.php` — it publishes the absolute path of `config.php`. If you do create
one, delete it in the same minute.

---

## 10. Incidents

### Wrong prices are published

1. **Stop it now**: admin → *Rates* → **Hold prices**. Ten seconds, and customers
   can still reach you. If the admin is unreachable, the SQL in §3.
2. **Who and what**: admin → *History* shows the version, the time and who
   published it; *Security* → Recent activity shows the rest.
3. **Put it right**: *History* → the last good version → **Restore into editor**
   → **Review and publish**, with *Show prices again after publishing* ticked.
4. Quotes already given are stored with the version that priced them, so any
   customer argument is settlable from the record.

### The site is down

1. `curl -sS $SITE/health`. A `503` with `"database":"down"` is the database; a
   Hostinger error page or a timeout is the host.
2. hPanel → Files → **Error Log**, top of the file.
3. *"This site is not configured yet."* — `config/config.php` is missing a
   required value. The log names the **key**, never the value. Most often a
   permissions change or a half-finished edit.
4. *"The database is unavailable."* — `db.name` / `db.user` are missing the
   `u123456789_` prefix, or the password is wrong, or the plan's **50 concurrent
   MySQL connections** are exhausted. Nothing needs restarting; fix and reload.
5. A bare `503` under load is PHP workers or RAM. Confirm `memory_limit = 128M`
   in `.user.ini` — not the plan maximum. Forty workers share the plan's RAM, and
   a generous per-request limit is what turns a busy afternoon into a 503.
6. **PHP source served as text** is catastrophic: the server is ignoring
   `.htaccess` or the layout is wrong. Rename `public_html/index.php` to take the
   site down, then §1.

### The database is full

The plan's ceiling is **3 GB**, and inodes (400,000 on Premium) usually bite
first.

1. phpMyAdmin → your database → sort by Size. It will be `audit_log`, then
   `quotes`, then `shipment_events`.
2. `sessions` and `rate_limits` are swept hourly by cron — if they are large, the
   cron has not run. §5.
3. `storage/cache` and `storage/logs` are swept too. Thousands of files there is
   the same symptom.
4. Trimming `audit_log` is a **decision, not a chore**: it is the record of who
   changed a price. Export the rows first, keep the export as long as the
   business needs the history, and if the append-only triggers are installed,
   drop them, trim, and put them back in the same sitting.

### ModSecurity blocks an admin form

A `403` on **one** admin form, with **nothing in any PHP log**, and the same form
working with shorter text. The request never reached PHP. The rates editor posts
hundreds of numbers and the content editor posts sentences with punctuation —
exactly what the default rules dislike.

hPanel → Advanced → **ModSecurity**. Scope the exception to that one path
(`/admin/rates/save`, `/admin/rates/publish`, …). **Never turn it off for the
whole site**, and never for a public path. Record what you did — it is
host-dependent and it is not in the release.

### Email is not sending

1. Admin → *Security* → deployment checks. A red light there is the answer.
2. `/home/uXXXXXXXX/.logs/exim.log`. `mail()` is capped at **10 per minute and
   100 per rolling 24 hours**, then **silently blocked** — no error, no bounce.
3. The fix is SMTP, not a retry: `mail.transport = 'smtp'` and `mail.smtp.*`
   filled in. `mail.from` must be an address **on this domain** or the host
   refuses it.
4. Check `speedat_notify_failed_24h` afterwards. Enquiries are never lost when
   mail fails — they are in the Inbox either way — but nobody is told about them.

### A suspected break-in

In this order:

1. **Prices off the site** (§3) — whatever else is true, stop quoting.
2. **Revoke every session.** phpMyAdmin → SQL: `DELETE FROM sessions;` Everyone
   signs in again, including you.
3. **Check who can sign in**: `SELECT email, role, created_at, added_by FROM
   admins;` An account you do not recognise, or an `editor` that has become an
   `owner`, is the finding. Remove it.
4. **Read `audit_log`** newest first. It is append-only, and if the triggers are
   installed the database itself refuses to rewrite it — so it is the one record
   an intruder cannot tidy up.
5. **Rotate `app.key`** and everything else in §7, then have the admins re-enrol
   their second factor.
6. **Re-deploy from a verified zip** rather than tidying files in place: build,
   `deploy/verify-release.php`, upload, extract over the top, reset OPcache. That
   replaces every shipped file with one you have a SHA-256 for. `config.php` is
   not in the zip, so rotate it by hand.
7. Then work out what happened, from the two logs in §8. Not before.
