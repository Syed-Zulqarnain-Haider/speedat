# Speedat PHP port — local toolchain

Phase 1 of the PHP rewrite: everything needed to develop and test the PHP
version of the Speedat quote site on this Windows machine, with no Composer,
no Node, and no installers.

Everything lives on **D:**. Nothing was installed on C:, nothing was registered
as a Windows service, and nothing touches the Postgres test cluster on 55432.

---

## What is installed

| Thing | Version | Location | Port |
|---|---|---|---|
| PHP (build target) | 8.2.34 NTS x64 (VS16) | `D:\php` | — |
| PHP (forward-compat check) | 8.3.35 NTS x64 (VS16) | `D:\php83` | — |
| MariaDB | 11.4.13 LTS (Win64) | `D:\mariadb` | **3307** |
| PHP dev web server | — | `php/public` | **8081** |

Downloaded zips are kept in `D:\downloads` in case anything needs re-extracting.

### Why these choices

- **PHP 8.2, not the newest.** The target is Hostinger shared hosting, where
  the PHP version is a dropdown in hPanel. Code written for 8.2 also runs on
  8.3 and 8.4; the reverse is not true. 8.3.35 is installed alongside purely so
  `lint.ps1` can prove the code still parses if the owner moves the dropdown.
- **Non Thread Safe** builds, because that is what PHP-FPM / LiteSpeed use,
  which is what shared hosting runs.
- **MariaDB 11.4 LTS**, because Hostinger runs MariaDB rather than Oracle MySQL,
  and 11.4 is the long-term-support line.
- **Port 3307**, not 3306, so a future MySQL install does not collide.
  **Port 8081**, not 8080, because 8080 is taken by the Kafbat UI service on
  this machine.

---

## After a machine restart

Nothing here starts automatically. Two commands:

```powershell
powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\start-db.ps1
powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\serve.ps1
```

Then open <http://127.0.0.1:8081/>.

`serve.ps1` runs in the foreground and holds the window. **Stop it with Ctrl+C
in that window**, not by closing the window or killing the PowerShell process —
killing the wrapper leaves an orphaned `php.exe` still holding port 8081.
If that happens:

```powershell
Get-Process php | Stop-Process
```

To stop the database when you are done for the day:

```powershell
powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\stop-db.ps1
```

> **Never hard-kill MariaDB.** `stop-db.ps1` asks it to shut down so InnoDB can
> flush and close cleanly. `Stop-Process` on `mariadbd.exe` can leave a data
> directory that needs recovery. This machine has lost a database once already.

Two bits of harmless noise you will see and should not chase:

- `stop-db.ps1` prints `WARNING: option --ssl-verify-server-cert is disabled,
  because of an insecure passwordless login`. That is `mariadb-admin` objecting
  to the passwordless local root account. Expected.
- `D:\mariadb\data\mariadb-error.err` collects pairs of
  `Aborted connection N ... user: 'unauthenticated' ... host: '127.0.0.1'`
  warnings. That is `start-db.ps1`'s readiness probe opening a TCP connection
  to see whether the port is up yet. Also expected — but it does mean the
  error log has a constant low hum, so read it by timestamp rather than
  assuming any warning is new.

---

## The scripts

| Script | What it does |
|---|---|
| `start-db.ps1` | Starts MariaDB hidden on 127.0.0.1:3307, waits for the port, prints the error log if it fails to come up. |
| `stop-db.ps1` | Graceful shutdown via `mariadb-admin`. Refuses to escalate to a kill. |
| `serve.ps1` | `php -S 127.0.0.1:8081 -t ../public public/router.php`. `-PhpRoot D:\php83` runs the same code on 8.3. |
| `lint.ps1` | `php -l` on every project `.php` file, on both runtimes. |
| `doctor.php` | Asserts the PHP runtime can do what the port needs (see below). |
| `db-check.php` | Real PDO round trip against `config/config.php` — DDL, insert, select, strict-mode rejection, utf8mb4 fidelity. |
| `bootstrap-db.sql` | One-time schema/user creation. Already applied. |

Checks, any time:

```powershell
D:\php\php.exe D:\speedat\php\bin\local\doctor.php
D:\php\php.exe D:\speedat\php\bin\local\db-check.php
D:\php\php.exe D:\speedat\php\bin\local\db-check.php --database=speedat_php_test
powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\lint.ps1
```

---

## Local database credentials

Development only. Committed on purpose so the setup is reproducible.

```
host      127.0.0.1
port      3307
databases speedat_php, speedat_php_test
user      speedat
password  speedat
```

The `speedat` user has full rights **inside those two schemas only** — no
global privileges, no `GRANT OPTION`, no `FILE`, no `PROCESS`. That mirrors
what a Hostinger database user gets, so a privilege the app relies on locally
will also exist on the server.

`root` has **no password** and is reachable only on loopback
(`bind-address = 127.0.0.1` in `D:\mariadb\my.ini`). That is the normal posture
for a portable dev install, but it does mean any process running as this
Windows user can connect as root. If that ever matters:

```powershell
D:\mariadb\bin\mariadb.exe --protocol=tcp --port=3307 --user=root -e "SET PASSWORD FOR 'root'@'localhost' = PASSWORD('something')"
```

…and then pass `-Password` to `stop-db.ps1`.

---

## Configuration and secrets

```
php/
  public/          <- this becomes public_html on Hostinger. Web-reachable.
    index.php        the only entry point
    .htaccess        rewrite + deny rules + security headers
    router.php       dev-server only; never uploaded
  app/             <- application code            } all of these sit ABOVE
  config/          <- config.php (never committed) } the web root on the
  storage/         <- cache, logs, uploads         } server and are not
  var/             <- runtime state                } reachable over HTTP
  bin/local/       <- this directory
```

`config/config.sample.php` is the template that ships, with empty values.
`config/config.php` holds the real values and is git-ignored — verified:

```
php/.gitignore:6:/config/config.php   php/config/config.php
```

The local `config.php` contains a throwaway `app_key` and `cron_token`
generated on this machine with `random_bytes(32)`. **The production key is
generated on the server and never leaves it.** Rotating `app_key` invalidates
every stored TOTP secret — there is no recovery path.

---

## php.ini

Both installs use a `php.ini` built from the stock `php.ini-development`, with
an override block appended at the end (in php.ini the last assignment wins).

Extensions enabled: `curl fileinfo gd intl mbstring mysqli openssl pdo_mysql
zip` plus `opcache`. `ZipArchive` + `XMLReader` are what the hand-written
`.xlsx` rate-sheet reader will use; `openssl` covers both Firebase ID-token
verification (`openssl_verify` against Google's certs) and AES-256-GCM
encryption of TOTP secrets. `argon2id` is available, so `password_hash` does
not have to fall back to bcrypt.

The limits **deliberately match the strictest Hostinger configuration**:

```
memory_limit        = 128M
max_execution_time  = 30
upload_max_filesize = 8M
post_max_size       = 9M
max_input_vars      = 1000
date.timezone       = Asia/Karachi
expose_php          = Off
```

`post_max_size` is one megabyte **above** `upload_max_filesize`, and the gap is
not slack — it is a fix. `post_max_size` caps the whole request body: the file,
its multipart envelope, and every other field. With both at 8M, an 8M rate sheet
(which `upload_max_filesize` allows) makes a body of slightly more than 8M and
PHP discards **all** of it — `$_POST` and `$_FILES` both arrive empty, with no
upload error code for the handler to report, so the admin sees a form that did
nothing at all. `Core\Upload::problem()` detects that case and says so in words;
`tests/Core/UploadTest.php` fails if this machine is set up with the trap; and
`public/.htaccess` plus `deploy/htaccess/user.ini.sample` carry the same pair.

The point is to hit those ceilings here rather than in production. **Do not
raise them to make something work** — a rate-sheet import that needs more than
128M or 30s needs to be chunked, because on the server it will simply die.

One gotcha worth knowing: the **CLI SAPI ignores `max_execution_time`** and
always reports `0`. `doctor.php` therefore reads the value out of `php.ini`
when it runs under CLI. The web SAPI really does apply 30s — confirmed by
serving a probe through `php -S` and reading `ini_get()` there.

`display_errors` is `On` locally and **must be `Off` in production**; the
production `config.php` carries `env => 'production'` for exactly that.

---

## Differences from the real server — do not forget these

- **`php -S` is not Apache.** It ignores `.htaccess` entirely. `router.php`
  reproduces only the one rewrite rule. Every deny rule, header and MIME
  setting in `public/.htaccess` is therefore **unverified until it runs on the
  server** — check them there with `curl -I` before calling the site done.
- The built-in server is **single-threaded**. It will not reveal concurrency
  problems, and it makes any "slow page" measurement optimistic.
- Timezones are split on purpose: **PHP runs Asia/Karachi, MariaDB runs UTC**,
  because that is the normal shared-hosting arrangement. Any code that assumes
  the two agree is a bug that will show up as an off-by-one delivery date.
- `opcache.enable_cli` is `0`, so CLI runs always read fresh source. On the
  server opcache is on for web requests; after uploading changed files the
  cache may need a moment (or `opcache.revalidate_freq` seconds) to notice.

---

## Not done in phase 1

The application itself. `public/index.php` is a stub that prints one line;
`app/` is empty. The pricing engine, the site document, the admin, the XLSX
importer and the CSS port are phases 2 and 3.
