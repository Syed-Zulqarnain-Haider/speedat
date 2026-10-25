# `php/deploy/` — the deployment kit

Everything needed to get this application onto Hostinger shared hosting, where
there is **no shell, no Composer, no build step and no Node**. The owner uploads
files through hPanel or SFTP, and that is the whole deployment mechanism.

The aim is that go-live is boring: a script builds the artefact, a second script
refuses to let a bad one through, and a checklist does the rest.

```
php/deploy/
├── README.md              this file
├── verify-release.php     unpack a release and prove it is safe to upload
├── htaccess/
│   ├── README.md          which .htaccess is live, and how to test it on the host
│   ├── public_html.htaccess   the annotated web-root rules (reference copy)
│   ├── deny-all.htaccess      the guard shipped into every non-public directory
│   └── user.ini.sample        the ANNOTATED copy of php/public/.user.ini,
│                              for pasting into hPanel by hand
└── export/
    └── dump-postgres.mjs  Postgres -> JSON, for the migration
```

**`.user.ini` ships in the release now** (2026-09-29). `php/public/.user.ini`
goes into the archive as `public_html/.user.ini` beside `.htaccess`, and
`bin/build-release.php` refuses to build without it. The sample above is the
annotated reference — nothing on the host needs to be pasted any more.

Both files exist because a host reads **one or the other and never says which**:
`php_value` in `.htaccess` works only under mod_php, and LiteSpeed and PHP-FPM
ignore that block silently. They therefore carry the same
`upload_max_filesize`, `post_max_size` and `max_input_vars`, and
`tests/Core/UserIniTest.php` fails if any copy drifts. Proved on real Apache
during the go-live rehearsal — see `docs/go-live-rehearsal.md` §2.12.

Two of the tools live in `php/bin/` because they are things you run, not things
you read: `bin/build-release.php` and `bin/export-from-nextjs.php`.

---

## The two jobs

### 1. Ship the code

```bash
cd /d/speedat/php
/d/php/php.exe bin/build-release.php
/d/php/php.exe deploy/verify-release.php var/speedat-release-<stamp>.zip
```

`build-release.php` writes a zip whose top level **is** the contents of
`~/domains/<site>/`: `public_html/`, `INSTALL.txt`, and the application in
`speedat_main/php/` — `app/`, `bin/`, `config/config.sample.php`,
`db/migrations/`, a copy of the web root as `public/`, and empty guarded
`storage/` and `var/`.

`speedat_main/php` is not a naming preference. It is the folder
`public_html/index.php` runs the application from, and it is preferred whenever
it exists. The zip used to put `app/`, `config/` and the rest straight beside
`public_html`: extracting it replaced the web root and wrote a second
application next to it that nothing ran, so new CSS and JS were served against
the old code, a cron pointed at `domains/<site>/bin/cron.php` had no config, and
rolling back by extracting an older zip changed nothing that executes (audit,
2026-10-23). The cron path is
`/home/uXXX/domains/<site>/speedat_main/php/bin/cron.php`.

It builds from an explicit manifest, refuses anything on a deny list, and then —
the part that actually matters — **re-checks the deny list against the entries
that are really inside the finished zip**, comparing each one's CRC against its
source file, and deletes the zip if anything is wrong. A bug in the collector
cannot ship a secret; it can only fail the build.

`verify-release.php` starts from the zip instead, unpacks it the way File Manager
will, and asks a different set of questions of the result: no `config.php`, no
`.env`, no `.git`, no tests, no logs, no dumps, no keys; every non-public
directory guarded; `speedat_main/php/app` beside `public_html` and not inside
it, and nothing else at the top level — an `app/` straight beside `public_html`
is refused as the tree nothing runs; every shipped
`.php` parses; and a grep for anything shaped like a private key. Sixty-odd
checks, non-zero exit on any failure.

Two scripts because one that checks its own work agrees with itself. The
builder's first run refused `app/Domain/Auth/SecretBox.php` — a class that holds
no secret — because its *name* matched a credential pattern. It would have
shipped an application that fatals on the first admin login, and it would have
said "verified" while doing it. The rule is narrower now, and there is a second
opinion.

### 2. Move the data

```bash
# Postgres -> JSON (read-only)
node php/deploy/export/dump-postgres.mjs --url=postgres://... --out=php/var/dump.json

# JSON -> MySQL-ready SQL
/d/php/php.exe bin/export-from-nextjs.php --dump=var/dump.json --app-key=<app.key> --gzip
```

Why two steps: **`D:\php` has no `pdo_pgsql`** — `PDO::getAvailableDrivers()`
returns `["mysql"]` and there is no `pgsql` extension either. Rather than compile
one, the read goes through the `postgres` package the Next.js app already
depends on. Nothing is installed. `bin/export-from-nextjs.php` keeps a
direct-PDO path for the day it runs somewhere with the driver, and both paths
are driven by the same column spec so they cannot disagree.

The exporter prints source rows against emitted rows per table, and with
`--import-into` it runs the statements and counts what landed. Everything is
`INSERT ... ON DUPLICATE KEY UPDATE` (`INSERT IGNORE` for the append-only
`audit_log`), so importing twice changes nothing — which is what makes a
half-finished cut-over safe to simply re-run.

---

## Then follow the checklist

[`../docs/deploy-hostinger.md`](../docs/deploy-hostinger.md) — create the
database, import the schema, import the data, upload, configure, permissions,
domain, HTTPS, the one cron job, Firebase authorised domains, smoke test,
rollback, weekly backups.

[`../docs/security.md`](../docs/security.md) — where the secrets live, why
nothing sensitive is reachable over HTTP even if `.htaccess` is ignored, the
session and CSRF model, the rate limits, the audit log, the rotation procedure
for each secret, and the protections that belong to the host rather than to us.

[`../docs/hostinger-constraints.md`](../docs/hostinger-constraints.md) — the
platform research these two are built on.

---

## What is deliberately not here

- **No deploy automation.** hPanel has native Git deployment and it works, but a
  file sync that runs on merge, on a host whose OPcache serves stale code and
  whose document root cannot be changed, is a way to break production at a
  moment nobody chose. Upload a zip you have verified, when you are watching.
- **No `mysqldump` or `zip` shell-outs.** `exec`, `shell_exec` and `proc_open`
  are disabled on this host. Everything here is pure PHP or pure Node, and the
  PHP half runs inside the same constraints the application does.
- **No secrets, in any file in this directory.** `config.sample.php` is the only
  configuration that is ever committed, and every one of its secret fields is an
  empty string. `verify-release.php` checks that too.
