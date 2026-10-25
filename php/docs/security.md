# Security: what protects what

This is the operator's document. It says where every secret lives, what each
defence actually stops, what to do in the first ten minutes after something
leaks, and — the part most such documents leave out — which protections are not
ours at all but the host's, and therefore could change without us being told.

Companion documents:
[`deploy-hostinger.md`](./deploy-hostinger.md) (how it gets there),
[`hostinger-constraints.md`](./hostinger-constraints.md) (why the host behaves
this way),
[`../deploy/htaccess/README.md`](../deploy/htaccess/README.md) (the server rules
and how to test them).

---

## 1. The one idea the whole design rests on

**Nothing sensitive is under a URL.**

```
domains/speedat.com.pk/
├── public_html/     ← the document root. index.php and assets. That is all.
├── app/             ← code
├── bin/             ← cron
├── config/          ← config.php: every secret the site has
├── db/              ← the schema
├── storage/         ← logs, uploads, cache
└── var/
```

The document root is `public_html`. Everything else is its **sibling**, and on
Hostinger's Web and Cloud plans the document root **cannot be changed**. So
`config/config.php` is not "protected by a rule"; there is no URL that maps to
it. Apache does not have to decline to serve it, because Apache is never asked.

### Why there is still an `.htaccess` in every one of those directories

Because the sentence above is true *until somebody changes something*. Three
realistic ways it stops being true:

1. The release is extracted **into** `public_html` instead of one level above
   it. This is the most likely deployment mistake there is.
2. An addon domain is pointed at the account root rather than at
   `domains/<site>/public_html`.
3. A panel "repairs" the document root after a restore.

In all three, `config/.htaccess` with `Require all denied` is the difference
between a 403 and handing out the database password. It costs nothing and it is
the only thing standing there on the worst day.

**But treat it as the second lock, never the first.** `.htaccess` is a *request*
to the server. If the server is misconfigured enough to serve `config/`, it may
also be misconfigured enough to ignore `.htaccess`. Which is why:

### Even if `.htaccess` were ignored entirely, `config.php` does not leak

`config.php` is **PHP that returns an array**. It is not `.env`, not `.ini`, not
`.json`, not YAML. If a web server with PHP enabled were somehow pointed at it,
it would *execute* it — and executing it produces no output at all, because the
file's only statement is `return [...]`. The array goes to a caller that is not
there. The browser gets a blank 200.

That is the whole reason the project has no `.env` file. A `.env` served as a
static file is a plain-text list of your passwords in the browser. A PHP file
that returns an array cannot be, short of PHP itself being switched off — and if
PHP is switched off, the site is down anyway and somebody is already looking.

`deploy/verify-release.php` refuses to pass a release that contains a `.env`,
for this reason, and refuses one that contains `config.php` at all.

---

## 2. Where the secrets live

**One file: `config/config.php`, mode 600, outside the web root, never in git.**

| Secret | What it does | Losing it means |
|---|---|---|
| `app.key` | THE secret. Everything else is derived from it: the CSRF signing key, the IP-hash salt, the TOTP encryption key. | Every stored TOTP secret is unrecoverable, every open form is invalid, every rate-limit counter resets. No recovery. |
| `db.pass` | MySQL | Read and write access to every lead, quote and audit line |
| `tokens.cron` | Guards the URL-triggered cron fallback | Someone can make the scheduled jobs run |
| `tokens.metrics` | Unlocks the detail in `/health` | Someone learns your PHP version, environment and whether the database is up |
| `tokens.inbound_email` | Authenticates the rate-sheet forwarder | Someone can inject a rate sheet into the import queue |
| `mail.smtp.pass` | Sending mail | Someone can send mail as your domain |

`app.key` is derived from, not reused:

```
app.key  --hmac("csrf")----->  CSRF signing key
         --hmac("ip-hash")-->  IP hash salt
         --sha256----------->  AES-256-GCM key for TOTP secrets
```

Deriving rather than reusing means a leak of one subsystem's key does not hand
over the others. It also means **rotating `app.key` rotates all three at once**,
which is the behaviour you want in an incident and the behaviour that makes
section 7 short.

### What is deliberately NOT stored

- **No raw IP addresses, anywhere.** `audit_log.ip_hash` and `leads.ip_hash` are
  keyed hashes truncated to 32 characters. The only question anybody ever asks
  of an address is "was this the same visitor?", and a hash answers it. The
  migration from Postgres re-hashes the old `audit_log.ip` column rather than
  carrying the addresses across.
- **No session tokens.** The browser holds 32 random bytes; `sessions.id` holds
  their SHA-256. A dump of the table cannot be replayed as a login.
- **No TOTP seeds in the clear.** `admins.totp_secret_enc` is AES-256-GCM with a
  key derived from `app.key`, stored as `base64(iv).base64(ct).base64(tag)`.
- **No passwords.** `admins.password_hash` is argon2id (32 MiB, 2 passes) where
  the host has it, bcrypt cost 12 where it does not. `password_hash()` records
  which, so a host that gains argon2 later keeps verifying old bcrypt hashes and
  upgrades each one on next sign-in.
- **No secrets in logs.** `storage/logs/` holds structured application events.
  Nothing writes a token, a password or a full card of PII into them.

### Things that must never happen

- `config.php` in git. `php/.gitignore` lists it; `build-release.php` refuses to
  put it in a release; `verify-release.php` fails a release that has one.
- A secret pasted into a chat, a ticket, a commit message or a code comment.
  Once it is there, treat it as leaked and rotate — see section 7.
- A `.sql` dump left in `public_html`. It is the customer list. The web-root
  `.htaccess` denies `.sql`, the release builder refuses any `.sql` outside
  `db/migrations/`, and the verifier checks again — three locks, because this is
  the mistake with the worst consequences and the lowest effort.
- A `phpinfo.php` left behind after a diagnosis. It publishes the absolute path
  of `config.php` along with everything else. If you make one, delete it in the
  same minute.

---

## 3. Sessions

Admin sessions live in the `sessions` table, not in PHP's own session files.
PHP's session directory on shared hosting has historically been readable by
other accounts, is garbage-collected on a schedule nobody controls, and cannot
answer "who is signed in right now" or "revoke that one login". A table answers
all three.

| | |
|---|---|
| Cookie | `speedat_sid`, 32 random bytes |
| Stored | SHA-256 of those bytes — **never the token itself** |
| Flags | `HttpOnly`, `Secure`, `SameSite=Lax` |
| Absolute life | `auth.session_lifetime`, default 5 days |
| Idle timeout | `auth.session_idle`, default 8 hours — a shift, not a week |

Both clocks are enforced **on read**, so an expired row is dead the moment it is
looked at, whether or not the hourly sweep has run. Signing in deletes any
previous session for that browser rather than accumulating them.

What each flag actually buys:

- **HttpOnly** — an XSS bug can still act as the admin within the page, but it
  cannot exfiltrate the session token to somewhere it keeps working afterwards.
- **Secure** — the cookie is never sent over plain HTTP, so a hostile network
  cannot pick it up from a stray redirect.
- **SameSite=Lax** — a cross-site POST arrives *without* the session, so it is
  simply not signed in. This is the primary CSRF defence; the token in section 4
  is the one that survives a browser that gets `SameSite` wrong.

**Identity is not authorisation.** Firebase says *who* you are. The `admins`
table says whether you may do anything. A perfectly valid Google account that is
not in that table gets nothing.

---

## 4. CSRF

Every state-changing form carries a token. Without it, any page on the internet
could make a signed-in admin's browser publish a price change.

```
token = issued-at "." nonce "." HMAC(issued-at "." nonce)
```

signed with a key derived from **both** `app.key` **and the session secret**.
Three properties follow:

- it cannot be minted by anyone without `app.key`;
- it stops working when the session ends or rotates, so a token lifted from one
  session is useless in another;
- it expires on its own after 12 hours, which quietly kills the tab that has
  been open since Tuesday.

Comparison is `hash_equals()`, never `===`, so the number of correct leading
bytes cannot be measured by timing the response.

A rejected token is a *reload and try again*, not a scary error. Most of them
are honest — an expired tab, a back button — and an alarming message for a
normal event trains people to click through alarming messages.

---

## 5. Rate limits

Counters live in MySQL, because shared hosting gives no Redis and no guarantee
two requests even reach the same process. Fixed windows, keyed by
`<bucket>:<hashed ip>`. Old windows are swept opportunistically by the limiter
and by the hourly cron.

Defaults, from `config.sample.php` — **raise them rather than remove them**:

| Bucket | Limit |
|---|---|
| `quote_per_hour` | 120 |
| `lead_per_hour` | 10 |
| `contact_per_hour` | 10 |
| `login_per_15min` | 10 |
| `admin_write_per_min` | 60 |
| `import_upload_per_day` | 40 |
| `track_per_hour` | 120 |

`login_per_15min` is the one that matters most: it is what turns an online
password-guessing attack into an impossible one. `track_per_hour` stops
`/track` being walked from one customer's shipment to the next — which is also
why shipment ids are random rather than sequential.

**The limiter fails open.** If the database is unreachable, requests are allowed
rather than refused. That is a deliberate trade: a database blip should not take
the public site down, and an attacker who can take the database down has already
taken the site down. It does mean the limiter is not a defence against someone
who can also break MySQL.

Two things it is *not*:

- **Not a DDoS defence.** It runs in PHP, which means a request has already cost
  you a worker by the time it is counted. Volumetric traffic is the host's edge
  and Cloudflare's problem, not this table's.
- **Not per-user.** It is per hashed IP. An office behind one NAT shares a
  bucket; `admin_write_per_min` at 60 is set with that in mind.

Also in force, and cheaper than any of the above: `upload_max_filesize 8M` and
`post_max_size 9M`. They decide how much a stranger can make the server chew on
*before authentication has even been checked*.

---

## 6. The audit log

`audit_log` is **append-only**. One INSERT, in one file (`app/Core/Audit.php`),
and no code path anywhere updates or deletes a row.

On Postgres the application's database role was granted `INSERT` and `SELECT`
only. **Hostinger gives one database user with full rights, so that grant cannot
be reproduced** — which is an honest weakening of this control on the new host.
Where the plan allows triggers, `db/migrations/0001_init.sql` documents two that
make the *database* refuse an `UPDATE` or `DELETE`; installing them is a step in
[`deploy-hostinger.md`](./deploy-hostinger.md) §3. They are not in the migration
itself because a host without the TRIGGER privilege would fail the whole import,
and a schema that will not install is worse than a schema whose last lock is
enforced in PHP.

Each row carries `at`, `actor`, `action`, a JSON `detail`, `ip_hash` and
`user_agent`. **Audit writes never throw.** The action they describe has already
happened; throwing would roll back a price publish because the diary was full,
and would hide the event instead of recording it. A failed write is logged
loudly instead.

`detail` is capped at 16 KB. It records *what changed* — never a password, never
a token, never a full card of personal data.

**Read it.** An audit log nobody reads is a compliance ornament. After every
deploy, and once a week:

```sql
SELECT at, actor, action, LEFT(detail, 120)
  FROM audit_log ORDER BY id DESC LIMIT 50;

-- who signed in, and from how many different places
SELECT actor, COUNT(DISTINCT ip_hash) AS places, COUNT(*) AS events
  FROM audit_log WHERE at > UTC_TIMESTAMP() - INTERVAL 7 DAY
 GROUP BY actor ORDER BY events DESC;
```

An `actor` you do not recognise, or a familiar one with a sudden new `ip_hash`,
is section 7.

---

## 7. Something leaked. What now.

**The first move is always the same: assume it is real, and rotate.** Deciding
whether a leak was serious takes hours; rotating takes minutes. Do it in this
order, and write down what you did and when — that note is the incident record.

### 7.0 First ten minutes, whatever leaked

1. **Turn the price hold on.** It takes the site's prices down without publishing
   anything and without a deploy:
   ```sql
   UPDATE app_settings
      SET v = JSON_SET(v, '$.on', TRUE), updated_at = UTC_TIMESTAMP(), updated_by = 'incident'
    WHERE k = 'hold';
   ```
   Skip this only if you are certain nothing about pricing is involved.
2. **Kill every session:** `DELETE FROM sessions;` Everyone signs in again. It
   costs each admin fifteen seconds.
3. **Read the audit log** for the period in question (section 6). Note anything
   you cannot account for *before* you start changing things, because some of
   the rotations below destroy evidence.

### 7.1 `app.key` leaked

The big one. It is the key everything else is derived from.

1. Generate a new one: `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`
2. Put it in `config/config.php` as `app.key`.
3. **Every stored TOTP secret stops opening.** Set `totp_secret_enc = NULL,
   totp_enabled = 0` for every admin and have each one re-enrol. There is no way
   around this and it is why the key is worth protecting.
   ```sql
   UPDATE admins SET totp_secret_enc = NULL, totp_enabled = 0;
   ```
4. Every open form becomes invalid (new CSRF key) and every rate-limit counter
   becomes meaningless (new IP salt). Both self-heal within the hour. Optionally
   `DELETE FROM rate_limits;` to clear them at once.
5. `DELETE FROM sessions;`
6. Note the date. Audit rows written before it have `ip_hash` values computed
   with the old salt and will never match rows written after it. That is not a
   bug; it is what a salt rotation means, and knowing the date is what lets you
   read the log correctly afterwards.

> If you have retired keys to keep reading old rows during a staged rotation,
> `SecretBox` takes the current key first and retired keys after it. Re-encrypt
> at rest, then drop the old key from the config. Do not leave a retired key in
> place "just in case" — a retired key that never retires is just a second live
> key.

### 7.2 A token leaked (`cron`, `metrics`, `inbound_email`)

Cheap. Generate a new one and save:
`php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"`

- **cron** — also update the hPanel cron entry if it passes the token. On this
  plan it does not (the CLI entry point needs none), so usually just the config.
- **metrics** — nothing else to do. The detailed `/health` answer is not
  catastrophic on its own, but it tells somebody your PHP version.
- **inbound_email** — also update the forwarder that posts to the intake
  endpoint, or rate sheets stop arriving silently.

### 7.3 The database password leaked

1. **hPanel → Databases → MySQL Databases →** change the user's password.
2. Update `db.pass` in `config/config.php` **in the same minute** — the site is
   down between those two steps.
3. Check **Remote MySQL** in hPanel. If a host is allowed there — especially
   "Any Host", which some migrations turn on — remove it. A leaked password with
   remote access enabled is a leaked database.
4. Assume the data was read. Leads and quotes contain customer names, phone
   numbers and email addresses; work out who has to be told.

### 7.4 An admin password leaked

1. `UPDATE admins SET password_hash = NULL WHERE email = '...';` — that admin
   can no longer sign in with a password. Firebase sign-in is unaffected.
2. `DELETE FROM sessions WHERE email = '...';`
3. Set a new one through the password-setup flow. The policy is 12 characters
   minimum, 128 maximum, no long runs, no known-weak strings.
4. If you are not sure it was only that one account, do all of them.

### 7.5 A TOTP secret leaked, or a phone was lost

1. `UPDATE admins SET totp_secret_enc = NULL, totp_enabled = 0 WHERE email = '...';`
2. `DELETE FROM sessions WHERE email = '...';`
3. Re-enrol from a device you can see.

`totp_last_step` already stops a code being replayed inside its own window, so a
code shoulder-surfed thirty seconds ago is dead. That is not a substitute for
re-enrolling after a lost phone.

### 7.6 Someone had access to the whole server

Do all of the above, in order, and additionally:

1. Change the **hPanel account password** and turn on its 2FA.
2. Rotate **FTP/SFTP** credentials.
3. Compare the deployed files against a freshly built release. The builder prints
   a SHA-256 and every file's CRC is checked into the zip, so "is this the code I
   shipped?" is an answerable question. Anything extra in `public_html` —
   especially a `.php` file that is not `index.php` — is the finding.
4. Rebuild from the release zip rather than patching what is there. You cannot
   review your way to confidence in a tree somebody else had write access to.
5. `SELECT * FROM admins;` — an account you did not add is the whole incident.

---

## 8. What is the host's, not ours

These protections are real, and they are **not under our control**. They can
change with a platform update and nobody will tell us. Re-check them after any
panel change and at least quarterly.

| Protection | Who owns it | How to check | If it changes |
|---|---|---|---|
| **TLS certificate and forced HTTPS** | Hostinger (free cert, auto-renewed, HTTPS forced by default) | `curl -sSI http://your-domain \| head -1` → expect a 301 to https | If plain HTTP starts answering 200, uncomment the redirect block in `public_html.htaccess`. Do **not** add it pre-emptively — behind a TLS-terminating proxy it loops. |
| **HSTS** | Ours, in `.htaccess`, **but only if `mod_headers` works** | `curl -sSI https://your-domain \| grep -i strict-transport` | If `mod_headers` is inactive, HSTS has to be set from PHP instead. Never add `preload`. |
| **PHP version** | hPanel, **per plan, not per site** | hPanel → PHP Configuration; `/health?token=…` reports the running version | The whole account moves together. Code is written for 8.2 and lints clean on 8.3. A jump to 8.5 has never been run here. |
| **`disable_functions`** | Hostinger. `exec`, `shell_exec`, `proc_open`, `symlink` and friends are off by default | a `phpinfo()` page, once, then delete it | This one protects *us*, and the application spawns no process anyway, so a change here is a weakening we would simply not use. |
| **`open_basedir`** | Hostinger, undocumented | can PHP read a file outside `public_html`? The site starting at all proves it can | If it is ever narrowed to `public_html`, the app cannot read `config.php` and the site 503s with a config error. The fix is a panel setting, not a code change. |
| **ModSecurity** | Hostinger, **on by default** | a 403 with nothing in `storage/logs/` | It false-positives on admin forms that post long or SQL-ish text. Narrow the exception to the one path. Never disable it site-wide. |
| **OPcache** | Hostinger, on by default | your fix does not take effect | Not a security control, but it decides whether a security *fix* is actually running. Reset it after every deploy. |
| **MySQL grants** | Hostinger: one user, full rights | — | The append-only grant on `audit_log` cannot be reproduced. Install the triggers if the plan allows them; otherwise the control lives in PHP. |
| **`mail()` caps** | Hostinger: 10/min, 100/day, then silently blocked | alerts stop arriving | Use SMTP. A silently-dropped alert is a monitoring failure. |
| **Backups** | Hostinger: weekly, 6 weeks (daily on Unlimited/Cloud) | hPanel → Backups | Six weeks is shorter than the gap between noticing some problems and understanding them. Keep your own weekly dump — [`deploy-hostinger.md`](./deploy-hostinger.md) §15. |
| **The document root** | Hostinger; cannot be changed on Web/Cloud | `curl https://your-domain/config/config.php` → 403/404 | A 200 here is a stop-everything finding. |

---

## 9. The checks worth running on a schedule

**After every deploy** — from [`deploy-hostinger.md`](./deploy-hostinger.md) §12:

```bash
SITE=https://your-domain
curl -sS  $SITE/health
curl -sS -o /dev/null -w '%{http_code}  /.env\n'              $SITE/.env
curl -sS -o /dev/null -w '%{http_code}  /router.php\n'        $SITE/router.php
curl -sS -o /dev/null -w '%{http_code}  /config/config.php\n' $SITE/config/config.php
curl -sSI $SITE/ | grep -iE 'x-content-type|x-frame|referrer|x-powered-by'
```

The three deny checks must be 403 or 404. A 200 is a stop-everything finding.

**Every week**, ten minutes:

- read the last 50 audit rows, and the per-actor summary in section 6;
- `SELECT * FROM admins;` — is every account still one you would add today?
- `SELECT COUNT(*) FROM sessions;` — more sessions than people is worth a look;
- take and store the database dump ([`deploy-hostinger.md`](./deploy-hostinger.md) §15).

**Every quarter:**

- restore one backup into the local MariaDB and open the site against it. A
  backup nobody has restored is a hypothesis;
- re-run the whole of section 8 and update it where the host has moved;
- confirm `config.php` is still mode 600 and `storage/` still 700 — a restore
  can quietly reset both.
