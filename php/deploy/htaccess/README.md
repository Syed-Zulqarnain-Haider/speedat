# `deploy/htaccess/` — the server configuration, and which copy is live

Three files here, and two in `php/public/` that are the ones actually shipped.
Nothing in this directory has to be pasted into hPanel any more.

| File | Where it ends up | Shipped by the release builder? |
|---|---|---|
| `php/public/.htaccess` | `public_html/.htaccess` | **Yes** — this is the live one |
| `php/public/.user.ini` | `public_html/.user.ini` | **Yes** (2026-09-29) — and the build is **refused** without it |
| `public_html.htaccess` | `public_html/.htaccess` | **No** — annotated reference, and the fallback if `php/public/.htaccess` ever goes missing |
| `deny-all.htaccess` | `.htaccess` in `app/ bin/ config/ db/ storage/ storage/*/ var/` | **Yes**, wherever the source tree has no `.htaccess` of its own |
| `user.ini.sample` | — | **No** — the annotated copy of `php/public/.user.ini`, kept for reading and for a one-off paste if a host ever needs one |

**Why the pair, and not one of them.** `php_value` in `.htaccess` applies only
under **mod_php**. Hostinger runs LiteSpeed through LSAPI, which ignores that
whole `<IfModule mod_php.c>` block *silently* — no error, no warning, the value
simply never applies. `.user.ini` is read by the CGI/FastCGI SAPI and ignored
by mod_php. So a host honours **one of the two and never announces which**, and
if they disagree the site's real body limit is whichever file that host
happened to read. They carry the same `upload_max_filesize`, `post_max_size`
and `max_input_vars`; `tests/Core/UserIniTest.php` fails if a copy drifts, and
`deploy/verify-release.php` re-checks the numbers inside the finished archive.

Measured, not assumed: the go-live rehearsal on real Apache + mod_php saw the
`php_value` pair in force and the `.user.ini` ignored — the exact opposite of
what will happen on the live host. `docs/go-live-rehearsal.md` §2.12.

Beside them, `LimitRequestBody 2097152` in both `.htaccess` copies refuses an
over-sized body at the **web server**, before a PHP worker is engaged at all.
It is wrapped in `<IfModule>` so a host that does not accept the directive
degrades to no cap rather than to a 500, and it sits *below* `post_max_size` so
the cheaper refusal is the one that fires. The rehearsal measured it: a 3 MB
POST answered **413**.

The PHP-version selector is a panel setting, not a file; it is the last section
of this README.

## Which web-root `.htaccess` is actually live

`bin/build-release.php` ships **`php/public/.htaccess`** as `public_html/.htaccess`,
because that is the file the application is developed and tested against. It
prints the decision on every build:

```
htaccess    public_html/.htaccess  <- php/public/.htaccess
```

`public_html.htaccess` in this directory is the **annotated deployment copy**:
the same rules plus the blocks that are deliberately switched off — HSTS, the
http→https redirect, the ModSecurity note — each with the reason it is off and
the one test that says whether to turn it on. Use it when you have to change
server behaviour on the host without rebuilding a release: File Manager →
`public_html/.htaccess` → paste the block you need.

If `php/public/.htaccess` is ever missing, the builder falls back to
`public_html.htaccess` and says so loudly. It never silently invents one — a
release whose web root has no `.htaccess` has no front-controller rewrite and no
deny rules, which is a site that serves its own source code.

**Keep the two in step.** They are separate files on purpose (one is the app's,
one is the operator's), and since 2026-10-26
`HtaccessTest::testTheDeployReferenceCopyHasExactlyTheShippedActiveRules` fails
the suite when their active lines differ - so a change to the deny rules belongs
in both. `php/public/.htaccess` is the one that ships. To see a difference by hand:

```bash
diff <(grep -v '^\s*#' php/public/.htaccess) \
     <(grep -v '^\s*#' php/deploy/htaccess/public_html.htaccess)
```

Differences in the commented-out blocks are expected. Differences in an active
`RewriteRule` or `FilesMatch` are a bug in one of them.

## The deny-all guard

`deny-all.htaccess` is copied into every non-public directory of the release.
On Hostinger those directories are siblings of `public_html`, so no URL reaches
them and the file is never read — which is exactly why it must be there. It
earns its place on the day somebody extracts the release *into* `public_html`
instead of one level above it. That is the most likely deployment mistake, and
without this file it publishes `config/config.php`.

The builder does not overwrite an `.htaccess` the source tree already ships
(phase 1 wrote its own for `app/`, `config/`, `db/`, `storage/` and `var/`); it
fills in the gaps. Either way, every non-public directory in the zip has one,
and `deploy/verify-release.php` fails the build if one is missing.

## Verifying the rules on the host — five curl calls

Run these once, immediately after the first deploy, from your own machine. Every
one of them must give the stated answer. This is the whole point of the release
having been built by a script: the file layout is not in question, only whether
the server honours it.

```bash
SITE=https://speedat.com.pk

curl -sS -o /dev/null -w '%{http_code}  /            (expect 200)\n' $SITE/
curl -sS -o /dev/null -w '%{http_code}  /health      (expect 200)\n' $SITE/health
curl -sS -o /dev/null -w '%{http_code}  /.env        (expect 403 or 404)\n' $SITE/.env
curl -sS -o /dev/null -w '%{http_code}  /router.php  (expect 403 or 404)\n' $SITE/router.php
curl -sS -o /dev/null -w '%{http_code}  /../config/config.php (expect 400/403/404)\n' \
  "$SITE/../config/config.php"
```

A `200` on any of the last three is a **stop-the-deploy** finding: take the site
down (rename `public_html/index.php`) and fix the layout before anything else.

Then check the headers, which is the one part of this file that Hostinger does
not document as supported:

```bash
curl -sSI $SITE/ | grep -iE 'x-content-type|x-frame|referrer|permissions|cache-control|x-powered-by'
```

No `X-Content-Type-Options` line means `mod_headers` is not active. That is not
fatal — the application sets the same headers from PHP — but it does mean the
commented-out HSTS line in `public_html.htaccess` would do nothing, so HSTS has
to come from PHP instead. Record the answer in `docs/hostinger-constraints.md`
§12, which is where the unverified host questions are collected.

## Choosing the PHP version (hPanel)

The release is written for **PHP 8.2** and lints clean on **8.3**. Hostinger
offers 8.2, 8.3, 8.4 and 8.5, and **8.3 is the default for new sites**, so a
fresh account is probably already on a version this code is happy with.

**hPanel → Websites → your site → Advanced → PHP Configuration**

1. **PHP version** tab → pick **8.2** or **8.3**. Not 8.4 or 8.5: nothing in the
   code is known to break on them, but nothing has been run on them either, and
   a go-live is not the moment to find out.
2. **PHP options** tab → confirm `opcache` is on (leave it on; see below),
   `zip`, `fileinfo` and `gd` are on. `intl` and `sodium` may be off — the
   application guards those with `extension_loaded()` and falls back, so off is
   acceptable, but note which it was.
3. Save. hPanel applies it immediately; no restart.

Two consequences worth knowing before you click:

- **The version is per plan, not per site.** Every site on the account moves
  together. If another site on this account needs a different version, the
  per-directory `AddHandler` override in `.htaccess` is the documented escape
  hatch — Hostinger frames it as temporary, and so should you.
- **The cron PHP binary is a different question.** `/usr/bin/php` on the CLI is
  not documented to match the site's selected version. `bin/cron.php` logs
  `PHP_VERSION` on every run for exactly this reason; read
  `storage/logs/app-<date>.log` after the first scheduled run and confirm it
  says 8.2 or 8.3.

### OPcache, and the stale-code trap

OPcache is on by default and hPanel's Cache Manager **does not clear it**. After
a file upload the server can keep serving the previous version of a `.php` file
for up to `opcache.revalidate_freq` seconds — and if
`opcache.validate_timestamps` is Off, indefinitely.

This is the single most confusing failure mode on this host: you upload a fix,
reload, and nothing changes. Do not start editing files at random. Either wait
out the revalidate window, or call the token-protected OPcache reset endpoint,
which is in the deploy checklist in `docs/deploy-hostinger.md` for that reason.
