# Staging

One command puts the working tree on real Linux and tells you whether it
answers. This document is the whole of it: what the box is, the command, the
logs, resetting the database, and the tunnel that lets the owner look at it.

---

## 1. What the staging box is

The company server, reachable as `ssh admin_tf@110.93.205.230` (the key is
already in place). A docker compose project lives in `~/speedat-staging` and
nothing else about that machine is any of our business — **it also runs the
owner's Prometheus dashboard (`prometheus-*` containers) and a Kubernetes
node.** Never stop, restart or inspect anything outside `~/speedat-staging`,
never use `sudo`, never touch the firewall or system configuration.

Two containers:

| container             | what it is                                                        |
| --------------------- | ----------------------------------------------------------------- |
| `speedat-staging-web` | `php:8.2-apache` + `mod_rewrite`, `gd`, `pdo_mysql`, `opcache`. Publishes `0.0.0.0:8085 -> 80`. `www-data` is uid 1000, which is also `admin_tf`. |
| `speedat-staging-db`  | `mariadb:11`. Database `speedat`, user `speedat`, password in `~/speedat-staging/.env`. Not published — only the web container's own bridge network reaches it. |

Apache, not LiteSpeed. Hostinger runs LiteSpeed, but LiteSpeed reads
`.htaccess` with Apache semantics, so this is the closest thing that can be
stood up in a container and it is enough to prove the rewrite rules, the deny
rules, the header blocks and the "code above the web root" layout on real
Linux. `php.ini` in the project is pinned to the strictest shared-hosting
shape (128M, 30s, 8M uploads, `max_input_vars=2000`), so a limit is discovered
here rather than on the live site.

### The layout is Hostinger's layout

That is the point of deploying at all. The directories on the box map onto the
container, and onto `~/domains/<site>/` on Hostinger, like this:

```
~/speedat-staging/            ->  ~/domains/<site>/         on Hostinger
  site/      -> /var/www/html     ->  public_html/          the only web-served dir
  app/       -> /var/www/app      ->  app/
  config/    -> /var/www/config   ->  config/               config.php lives here, chmod 600
  storage/   -> /var/www/storage  ->  storage/              cache logs tmp uploads
  bin/       (docker cp'd in)     ->  bin/                  cron.php migrate.php seed.php
  db/        (docker cp'd in)     ->  db/migrations/
```

`site/`, `app/`, `config/` and `storage/` are **bind mounts**. `bin/` and `db/`
are not mounted by `docker-compose.yml`, so the deploy copies them into the
container with `docker cp` on every run; that is why the migration runner can
be executed there at all.

---

## 2. The one command

From anywhere on the Windows machine:

```powershell
powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\staging.ps1
```

That is it. It packs whatever is in `D:\speedat\php` right now, sends it,
configures it, migrates it, reloads Apache and curls the site. A first deploy
takes about half a minute; a redeploy about six seconds. Run it as often as you
like — twenty times an hour is a normal working rhythm and nothing about it is
destructive outside `~/speedat-staging`.

The last line is the whole report:

```
SUMMARY files=587 bytes=1407834 seconds=5.7 http=200 migrations=ok opcache=reset url=http://127.0.0.1:8085/
```

`http=200` (or any 2xx/3xx) is success and the script exits 0. Anything else
and it exits non-zero after printing the first twenty lines of whatever the
server actually returned, which is usually enough to know what broke.

### Options

| switch                  | effect                                                           |
| ----------------------- | ---------------------------------------------------------------- |
| `-DryRun`               | pack and report; the server is not contacted at all              |
| `-List`                 | print every packed entry and every refusal                       |
| `-NoMigrate`            | skip `bin/migrate.php` on the server                             |
| `-DebugConfig`          | generated config gets `app.debug = true`                         |
| `-Keep`                 | keep the tarball in `php\var\` instead of deleting it             |
| `-Url http://…`         | `app.url` in the generated config, and what gets curled          |
| `-Server user@host`     | a different box                                                  |
| `-PhpRoot D:\php83`     | run the deployer itself on PHP 8.3                               |
| `-TimeoutSeconds 300`   | per-`ssh`-step timeout (default 180)                             |

`-DebugConfig` is off by default on purpose: the container is published on
`0.0.0.0:8085`, so error detail belongs in `storage/logs`, not in a visitor's
browser.

The wrapper is a wrapper. Everything of substance is in
`php/bin/deploy-staging.php`, and `php bin/deploy-staging.php --help` lists the
same options in their long form if you would rather run it directly.

### What goes up, and what cannot

Exactly what the release ships, in the release's shape: `public/` -> `site/`,
`app/`, `config/config.sample.php`, `db/migrations/*.sql`,
`bin/{cron,migrate,seed}.php`, and an empty `storage/{cache,logs,tmp,uploads}`
skeleton. No tests, no `docs/`, no `deploy/`, no `tools/`, no dotfiles, no
`node_modules`, no `.env`, and **never `config/config.php`**.

That deny list is not written twice. `deploy-staging.php` borrows
`bin/build-release.php`'s `refusalReason()` — it slices the function and its two
constants out of that file verbatim and `require`s them — so a rule added to
the release builder is a rule the staging deploy obeys on its next run. It also
self-tests the borrowed function before using it, and re-checks the finished
manifest against it a second time. A bug in the collector can fail a deploy; it
cannot ship a secret.

`-DryRun -List` prints both lists if you want to see them.

### The `!!` warning

`app/` and `db/migrations/` are directories whose entire contents are meant to
ship, so a `.php` refused out of `app/` or a `.sql` refused out of
`db/migrations/` is not a deny list doing its job — it is the deny list eating
the application, and whatever it ate is missing from **every release** too,
because both come from the same `refusalReason()`. The deploy prints a `!!`
block for exactly those cases, on every run, and nothing is special-cased to
paper over it: staging serving code the release does not is the one thing
staging must never do.

As of 2026-09-27 that block names two, both of them bugs in
`bin/build-release.php`:

- `app/Vendor/Smtp/*.php` — `DENIED_DIRS` contains `vendor`, meant for
  Composer's directory. This project has no Composer; `app/Vendor/Smtp` is the
  hand-written SMTP client, and refusing it means mail cannot work on staging
  or in production.
- `db/migrations/0002_session_csrf_secret.sql` — the "named like a credential
  file" heuristic matches `secret` in the filename, so the second migration
  never reaches a server and the schema deploys incomplete.

Until `refusalReason()` is fixed, staging is running migration `0001` only and
has no SMTP transport. That is a release-builder change, not a deploy-script
one.

### What it does to the server, in order

1. Streams one `tar.gz` into `ssh … tar -xzf -`, landing in
   `~/speedat-staging/var/incoming`. (No `scp`, no `rsync` — neither is
   permitted on that box.)
2. Syncs `site/` and `app/` **contents** into place, then deletes whatever the
   payload no longer has. The contents, not the directories: they are bind
   mounts, and replacing the directory would leave the running container
   looking at the old inode — a deploy that reports success and changes
   nothing.
3. Replaces `bin/` and `db/`, and `docker cp`s them into `/var/www/`.
4. Writes `config/config.php` (below).
5. Runs `bin/migrate.php` inside the container as uid 1000.
6. `apache2ctl -k graceful`, which drops the shared OPcache. If that is
   refused the summary says `opcache=stale-up-to-2s`, because `php.ini` here
   also has `validate_timestamps=1` / `revalidate_freq=2`.
7. `curl`s the site from inside the server and prints the status and the first
   lines of the page.
8. Deletes `var/incoming`. On failure it is left behind on purpose, so you can
   look at what was actually sent.

Every path it touches is built from `~/speedat-staging`, and the remote script
refuses to start if that is not where it finds itself.

### The generated `config/config.php`

Written on the server, never on the laptop. The database password is read from
`~/speedat-staging/.env` — the file the compose project already keeps it in — and
handed to the renderer through `docker exec --env-file`, so it is never a
command-line argument (arguments show up in `ps`), never printed, and never
copied back to the laptop. The file lands `chmod 600`, owned by `admin_tf`,
which is also `www-data` inside the container.

It is rewritten on every deploy, but the **generated secrets are kept**:
`app.key` and the `cron` / `metrics` tokens are read back out of the existing
file. `app.key` encrypts stored TOTP secrets, and rotating it makes them
unreadable for good.

Staging-specific values worth knowing:

- `app.env = 'local'`, `app.debug = false`, `app.url = http://127.0.0.1:8085`
- `db.host = 'db'` (the compose service name), database and user `speedat`
- `auth.allow_password_login = true` and no Firebase project — sign-in on
  staging is the password + TOTP path, with its own admin and its own 2FA
- `mail.transport = 'log'`: nothing is ever sent. A staging box that emails the
  office every time somebody tests the lead form is a staging box people turn
  off
- `security.hsts = false`, always. Staging is plain http and a browser
  remembers HSTS for a year
- no `tokens.inbound_email`: the rate-sheet mailbox forwarder was removed from
  the product (owner, 2026-09-27)

Edits to that file are lost on the next deploy. Change the renderer in
`bin/deploy-staging.php` instead.

---

## 3. Looking at the logs

Three places, in the order you usually want them.

**The application's own log** — every request, every handled error, every audit
line. This is the one that answers "why did that page 500".

```bash
ssh admin_tf@110.93.205.230 'tail -50 ~/speedat-staging/storage/logs/app-$(date -u +%Y-%m-%d).log'
```

**PHP's error log** — fatals, warnings, anything that died before the
application's logger existed. `php.ini` points `error_log` here.

```bash
ssh admin_tf@110.93.205.230 'tail -50 ~/speedat-staging/storage/logs/php-error.log'
```

**Apache** — access lines, rewrite refusals, 403s from the deny-all
`.htaccess` guards, and startup complaints. It goes to the container's stdout.

```bash
ssh admin_tf@110.93.205.230 'docker logs --tail 50 speedat-staging-web'
ssh admin_tf@110.93.205.230 'docker logs -f speedat-staging-web'     # follow
```

If you are already logged in, everything is under `~/speedat-staging/storage/logs`
and `ls -lt` there is the fastest way to see what is being written.

To turn the detail up, redeploy with `-DebugConfig` — but turn it off again
with a plain redeploy, because port 8085 is open on that host's public
interface.

---

## 4. Resetting the staging database

Staging holds test data only, so this is cheap. Three levels.

**Re-run the migrations.** Idempotent and automatic on every deploy; each
migration is recorded in `schema_migrations` with the SHA-256 of the file it
ran from, so an edited-after-the-fact migration is reported rather than
silently skipped.

```bash
ssh admin_tf@110.93.205.230 'docker exec -u 1000:1000 speedat-staging-web php /var/www/bin/migrate.php'
```

**Seed the starting state** — settings and the first admin. Also idempotent: it
never overwrites a setting that is already there and never downgrades an
existing admin.

```bash
ssh admin_tf@110.93.205.230 'docker exec -u 1000:1000 speedat-staging-web php /var/www/bin/seed.php --owner=you@example.com'
```

**Start the schema over.** Destructive, and deliberately a command you type
yourself rather than a switch on the deploy script. Log in first, so nothing
about it is a one-liner someone pastes by accident:

```bash
ssh admin_tf@110.93.205.230
cd ~/speedat-staging
docker exec -i --env-file .env speedat-staging-db \
  sh -c 'MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb -uroot -e "
     DROP DATABASE IF EXISTS speedat;
     CREATE DATABASE speedat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     GRANT ALL PRIVILEGES ON speedat.* TO speedat;"'
```

The root password comes out of `.env` through `--env-file` and `MYSQL_PWD`, so
it never appears in the command line or the shell history. Dropping the
database drops the `speedat` user's privileges on it too, which is what the
`GRANT` puts back. Then redeploy (or just run the migrate command above) and
the schema is rebuilt from `db/migrations`.

Do **not** reach for `docker compose down -v`. It would work — the volume
belongs to this project and nothing else — but it stops and recreates
containers on a host that is running the owner's dashboard, and there is never
a reason to when `DROP DATABASE` is right there.

---

## 5. Looking at the site (the tunnel)

The staging site listens on the server's port 8085. Forward it to the same port
locally and open `http://127.0.0.1:8085/`.

### MobaXterm

1. **Tools -> MobaSSHTunnel** (or the *Tunneling* button on the toolbar).
2. **New SSH tunnel**, and pick **Local port forwarding** — the left-hand
   option, the one whose diagram has the arrow leaving your machine.
3. Fill in the three boxes:

   | box                                    | value              |
   | -------------------------------------- | ------------------ |
   | *Forwarded port* (left, "My computer") | `8085`             |
   | *SSH server* / *SSH login* / *SSH port*| `110.93.205.230` / `admin_tf` / `22` |
   | *Remote server* / *Remote port*        | `127.0.0.1` / `8085` |

4. Save it, then press the **play** button on the row. The lamp goes green.
5. Open <http://127.0.0.1:8085/>.

Tick the key icon on the saved tunnel row and point it at the same private key
you use for the SSH session if it asks for a password.

### Plain ssh

Same thing in one line, from PowerShell or Git Bash:

```bash
ssh -N -L 8085:127.0.0.1:8085 admin_tf@110.93.205.230
```

`-N` means "no shell, just the tunnel"; leave it running and open
<http://127.0.0.1:8085/> in a browser. Ctrl-C closes it.

Because port 8085 is published on `0.0.0.0`, `http://110.93.205.230:8085/` may
also answer directly, depending on the company firewall. Prefer the tunnel:
`app.url` in the generated config is `http://127.0.0.1:8085`, so that is the
address the site's own absolute links are built for.

---

## 6. When it goes wrong

**`Refusing to deploy: php/public/index.php does not exist.`** There is no
front controller in the tree, so there would be nothing to serve. Nothing was
sent.

**`DEPLOY-ERROR …` from the server.** The remote half checked its ground before
touching anything: `~/speedat-staging` missing, `.env` missing, no
`docker-compose.yml` (so this is not the staging project), or the payload
arrived without `site/index.php` or `app/Core`. Nothing was changed.

**`No usable ssh client`.** Name the binary explicitly through the underlying
script: `php bin/deploy-staging.php --ssh=C:\Windows\System32\OpenSSH\ssh.exe`.
It already tries that path by itself when `ssh` is not on `PATH`, so this means
OpenSSH is somewhere else again.

**`http=503` and "This site is not configured yet."** `config/config.php` was
not written or is not readable by `www-data`. Check
`ls -l ~/speedat-staging/config/` — it should be `-rw------- admin_tf admin_tf` —
and look at the PHP error log, which is where that one failure explains itself.

**`http=500`.** The application started and then threw. `storage/logs` has the
detail; the visitor never does.

**`http=000`.** `curl` could not reach Apache from inside the server at all.
`docker ps` should show `speedat-staging-web` as `Up`.

**The page has not changed.** The summary line says `opcache=reset` when the
graceful reload worked. If it says `stale-up-to-2s`, wait two seconds and
reload — `validate_timestamps=1` picks the new files up on its own.

**`migrations` failed.** The runner prints which file and which statement.
`speedat-staging-db` must be `Up (healthy)`; the web container reaches it as
host `db` on the project's own network, and nothing else on the machine can.
