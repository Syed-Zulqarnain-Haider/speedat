# Hostinger constraints the PHP port must respect

Researched 2026-09-27 from Hostinger's own knowledge base (each claim below was
carried from an article with a 2026 last-modified date) plus php.net for the ini
semantics. Scope: **Web hosting (Premium / Unlimited, ex-"Business") and Cloud
hosting, managed in hPanel**. VPS is out of scope — the owner's plan is shared.

Anything marked **VERIFY** is not documented by Hostinger and must be confirmed
once in the owner's panel; the checklist at the end collects those in one pass.

---

## 1. The server is LiteSpeed, not Apache

`.htaccess` is the only configuration surface (`httpd.conf` is inaccessible and
LiteSpeed does not use it), and it is read per request — no restart needed.

Confirmed working here:

| Feature | Status |
|---|---|
| `mod_rewrite` | enabled by default on all Web/Cloud plans |
| `mod_gzip` / `mod_deflate` | enabled by default |
| `mod_expires` (`ExpiresByType`) | documented by Hostinger with a working snippet |
| `php_value` in `.htaccess` | documented (works because PHP runs through LSAPI) |
| `AddHandler` for a per-directory PHP version | documented |
| ModSecurity | **on by default**, can be narrowed/disabled per directory |
| `mod_headers` (`Header set …`) | **VERIFY** — LiteSpeed supports it, Hostinger never confirms it |

Rules for us:

- Wrap **every** `mod_*` block in `<IfModule …>` so a missing module degrades
  instead of returning 500. A bad directive is a hard 500, so change `.htaccess`
  in small reversible steps.
- `<Directory>` / `<VirtualHost>` blocks are invalid in `.htaccess`;
  `php_admin_value` / `php_admin_flag` are typically refused. Do not ship them.
- HTTP/2 is on. HTTP/3 and Brotli are **VERIFY** (gzip is guaranteed).

---

## 2. PHP version and extensions

- Selectable: **8.2, 8.3, 8.4, 8.5**; **8.3 is the default** for new sites; 8.1
  and below are not selectable. The version is set **per plan, not per site**
  (a per-directory override is possible via `.htaccess`, framed as temporary).
- Therefore: **write 8.2-safe code that is also 8.3-clean** — no dynamic property
  creation, no `${var}` interpolation, no implicit nullable parameters.
- `php.ini` cannot be edited and custom extensions cannot be compiled on
  Web/Cloud plans.

Extension reality:

| Extension | Assume |
|---|---|
| `json` | present (compiled into core since PHP 8) |
| `pdo_mysql`, `mysqli`, `mbstring`, `curl`, `openssl`, `iconv` | present (not documented individually, but the whole stack depends on them) — **VERIFY** |
| `opcache` | **on by default** |
| `fileinfo`, `gd` | available but **toggleable in hPanel — may be OFF** |
| `zip` | likely, **VERIFY** |
| `intl`, `sodium` | **treat as possibly missing** |
| `imagick` | **assume absent** — use `gd` only |

**Hard rule:** guard `zip`, `intl`, `sodium` with `extension_loaded()` and keep a
pure-PHP fallback or a visible, explained failure. Our XLSX reader must not die
because `zip` is off — it needs the hand-written fallback reader.

---

## 3. Functions that do not exist here

Disabled by default on Hostinger:

```
system, exec, shell_exec, passthru, popen, proc_open, dl, symlink, link,
chgrp, leak, ini_alter, apache_child_terminate, virtual, mb_send_mail,
mysql_list_dbs
```

(`proc_open` is disabled for all newly created accounts; Hostinger's own article
contradicts itself about re-enabling it — **treat it as unavailable**.)

Consequences for the port:

- **No process spawning at all.** No shelling out to `mysqldump`, `zip`,
  ImageMagick, `wkhtmltopdf`, `git`, or any `Process` helper.
- **No `symlink()`/`link()`** — no "current release" symlink deploy tricks.
- Still available: `set_time_limit`, `ini_set`, `getenv`/`putenv`, `mail`,
  `opcache_reset`, `curl_*`, `file_get_contents()` on URLs (`allow_url_fopen` on).

### What `ini_set()` can and cannot change

Changeable at runtime: `memory_limit`, `max_execution_time`, `display_errors`,
`error_reporting`, `error_log`, `date.timezone`, `default_charset`,
`session.save_path` and the `session.cookie_*` family (**before**
`session_start()`), `zlib.output_compression` (before any output).

**Not** changeable at runtime — `.htaccess` or panel only:
`upload_max_filesize`, `post_max_size`, `max_input_time`, `max_input_vars`,
`output_buffering`.

Never changeable from userland: `disable_functions`, `allow_url_fopen`,
`file_uploads`, extension loading, most `opcache.*`, and `open_basedir` (which
can only be narrowed).

Raising a limit above the plan ceiling silently does nothing — the plan maximum
is applied. LiteSpeed also enforces **its own** request timeout independent of
`max_execution_time` (**VERIFY**), so never design a web request that needs
minutes.

---

## 4. Plan limits that actually bite

Published figures (V3-era table; Hostinger versions limits by purchase date, so
the owner's entitlement depends on when he bought — **VERIFY**):

| Parameter | Web Premium | Unlimited (ex-Business) |
|---|---:|---:|
| CPU cores | 1 | 2 |
| RAM | 2 GB | 3 GB |
| PHP workers (concurrent requests) | **40** | **60** |
| `memory_limit` ceiling | 1536 MB | 2048 MB |
| `max_execution_time` | 360 s | 360 s |
| **MySQL connections per user** | **50** | **75** |
| MySQL max query time | 60 s | 120 s |
| Max database size | 3 GB | 3 GB |
| Inodes | 400,000 | 600,000 |

Read this correctly: the 1.5–2 GB `memory_limit` is a *ceiling*, not a budget —
40 workers share the plan's 2 GB of RAM. **Budget ~32–48 MB and well under 10 s
per request.** When RAM or processes run out, visitors get a bare **503**.

**The tightest number is MySQL: 50 connections.** One connection per request,
**never persistent** (`PDO::ATTR_PERSISTENT => false`); a persistent pool pinned
to idle workers produces "Too many connections" under trivial load.

---

## 5. Database

- **MariaDB** (no PostgreSQL, no Redis, no Memcached on shared plans). Exact
  version is not published — **VERIFY** in phpMyAdmin.
- Databases and users are created in hPanel → Databases → Management, and both
  names are **prefixed with the account id** (`u123456789_speedat`) — the names
  we pick are not the names we connect with. Host is `localhost`, port 3306.
- **phpMyAdmin import ceiling: 256 MB**, and it accepts `.sql.gz` / `.zip` — so
  the migration dump must be gzipped and kept under that, or split.
- Remote MySQL exists (hPanel → Remote MySQL) for a one-off migration from this
  machine; its "Any Host" option exposes the database to the internet, so it is
  enabled for the import and **deleted the same day**.

---

## 6. Cron

- hPanel → Cron Jobs. Type **PHP** (just a path) or **Custom** (a command).
- **The cron clock is UTC+0 and cannot be changed.**
- Premium and above: unlimited jobs. Minimum interval is not documented —
  **VERIFY** the shortest option in the dropdown.
- PHP binary: `/usr/bin/php`. **Whether CLI PHP matches the site's PHP version is
  undocumented** — our first cron run must log `PHP_VERSION` and
  `get_loaded_extensions()` and we read that log.
- **The cron command field rejects `>`, `2>&1`, `/dev/null`.** Anything needing
  redirection goes into a small `.sh` file that cron calls with `/bin/sh`.
- Cron gets a minimal environment: absolute paths only, no web server variables,
  and **`.htaccess` `php_value` lines do not apply** (CLI never reads them) —
  every limit a cron script needs must be set with `ini_set()` inside it.

---

## 7. File layout and access

```
/home/uXXXXXXXXX/
└── domains/<domain.tld>/
    ├── public_html/     ← the only web-served directory
    └── …                ← siblings are NOT served
```

- **Files outside `public_html` are not reachable over HTTP** — this is what
  makes our "config and app code above the web root" design work. Whether PHP can
  *read* them depends on `open_basedir`, which Hostinger does not document:
  **VERIFY** with a one-line test (see the checklist).
- Belt and braces: any sensitive directory that must live inside the web root
  also ships an `.htaccess` with `Require all denied`.
- The document root cannot be changed on Web/Cloud plans.
- Access: FTP/FTPS on all plans; **SFTP, SSH and rsync on Premium and above**
  (SSH is off by default and is enabled in hPanel).
- **hPanel has native Git deployment** (GitHub *and* GitLab via OAuth, no keys),
  with a selectable branch and target directory, and **auto-deploy on merge**.
  It is a **file sync with no build step** — anything needing a build or
  Composer must already be committed. This is our preferred deploy route.
- Composer 1 and 2 are in fact **pre-installed** on Premium+ and usable over SSH.
  We still build with **no Composer dependency**, so the app can be deployed by
  file sync alone on any plan.
- Permissions: files **644**, directories **755**. Never 777 — LiteSpeed may
  refuse to execute world-writable PHP.
- Backups: weekly on all plans (6 weeks retained); daily on Unlimited/Cloud.

---

## 8. HTTPS and headers

Free certificates are installed automatically and **HTTPS is forced by default**,
so our PHP must **not** add its own http→https redirect (double redirect). It
must still set `session.cookie_secure`, `httponly`, `samesite=Lax` and read
`X-Forwarded-Proto` defensively. HSTS has no panel toggle — we add it in
`.htaccess` inside `<IfModule mod_headers.c>` (**VERIFY** that module first), and
without `preload` until the site has run cleanly for a while.

---

## 9. Email

`mail()` works but is capped at **10 per minute and 100 per rolling 24 hours**,
then silently blocked. Booking and alert mail must go through **SMTP**
(Hostinger Email up to 3,000/day; Titan 1,000/day, 300/hour). With no Composer we
**vendor a small SMTP client** into the repository rather than relying on
`mail()`. The exim log is at `/home/<user>/.logs/exim.log`.

---

## 10. Things that commonly break an app on this host

1. **OPcache serves stale code after a file-sync deploy** (OPcache is on by
   default; hPanel's Cache Manager does not clear it). We ship a
   token-protected `opcache_reset()` endpoint and call it after every deploy.
2. **Everything is UTC** — OS, cron and MariaDB. For a courier site with pickup
   cut-off times this is a correctness issue: store UTC, set the display timezone
   explicitly in the bootstrap, and `SET time_zone` on the database session.
3. **ModSecurity is on by default** and false-positives on admin forms that post
   SQL-ish or HTML-ish text — exactly what a rates admin does. Symptom is a 403
   that never reaches PHP. Scope any exception to the admin path only.
4. **`open_basedir` scope is unknown** — test before trusting reads outside the
   web root.
5. **Session save path** is undocumented; if sessions misbehave, point
   `session.save_path` at a private 700 directory before `session_start()`.
6. **Inodes** are finite: prefer the database or a few rolled files over
   thousands of cache files.
7. Hostinger's own documentation contradicts itself in places (PHP versions on
   the marketing page, File Manager upload limit, `proc_open`). Where it
   conflicts, believe the most recently modified support article and verify.

---

## 11. Build checklist (binding for this port)

- [ ] PHP 8.2 syntax, 8.3-clean.
- [ ] Only the conservative extension set; `extension_loaded()` guards with
      fallbacks for `zip`, `intl`, `sodium`; never ImageMagick.
- [ ] No process spawning, no symlinks, anywhere.
- [ ] One non-persistent database connection per request; close early in cron.
- [ ] ~32–48 MB and under 10 s per web request; heavy work belongs in cron with
      its own `ini_set()` limits.
- [ ] Config and application code outside `public_html`, plus `Require all
      denied` as a second line of defence.
- [ ] `.htaccess` fully `<IfModule>`-guarded; `php_value` for memory/time/upload;
      `mod_expires` for assets; front-controller rewrite.
- [ ] Static assets cache-busted with a `filemtime()` query string (no build step).
- [ ] No http→https redirect in PHP; secure session cookie flags.
- [ ] UTC everywhere internally; explicit display timezone.
- [ ] SMTP (vendored client), never `mail()`, for notifications.
- [ ] Cron scripts self-sufficient, absolute paths, first run logs PHP version
      and extensions; redirection wrapped in a `.sh`.
- [ ] Migration dump gzipped and under 256 MB.
- [ ] Post-deploy OPcache reset endpoint, token-protected.
- [ ] Deploy by hPanel Git (auto-deploy on merge) with everything committed;
      file-sync only.
- [ ] Permissions 644/755 in the deploy checklist.

---

## 12. What only the owner's hPanel can answer (one pass)

**Plan and PHP**
1. Exact plan name **and purchase date** (decides which limits table applies).
2. PHP Configuration → which version is selected, and what the dropdown offers.

**The big one — a `phpinfo()` page, screenshot it, then delete it**
3. Loaded extensions: `intl`, `sodium`, `zip`, `fileinfo`, `gd`, `imagick`?
4. `opcache.enable`, `opcache.validate_timestamps`, `opcache.revalidate_freq`.
5. **`open_basedir`** — empty or restricted?
6. `session.save_path`, live `disable_functions`, `memory_limit`,
   `max_execution_time`, `upload_max_filesize`, `post_max_size`,
   `max_input_vars`, Server API (should read `litespeed`), `date.timezone`.

**Database**
7. MariaDB version (top-right in phpMyAdmin), the real prefixed database and
   user names, and the import screen's stated maximum.

**Access and deploy**
8. Is SSH available/enabled? Does hPanel show **Git** under Advanced?
9. File Manager's stated upload limit.

**Cron**
10. Shortest interval the schedule offers; and does `/usr/bin/php -v` match the
    site's PHP version?

**Server behaviour**
11. Does `mod_headers` work? (`Header set X-Test "1"` inside `<IfModule>`, then
    look for the header.) Decides HSTS and `Cache-Control`.
12. Can PHP read a file placed outside `public_html`? Decides where config lives.
13. Is Cache Manager or the Hostinger CDN on? (Both can serve stale pages.)
14. SMTP host/port from the mailbox settings page.
