# Go-live checklist

Launch day, in order. Each item has the **evidence** to write down beside it —
a value, a screenshot, a version number, a date. An item with no evidence has not
been done, whatever anyone remembers.

The how-to for every step is [`deploy-hostinger.md`](./deploy-hostinger.md); this
is the order and the proof. Stop at the first failure: later steps assume the
earlier ones.

Record it somewhere that is not this file — a page in the password manager or a
dated note — because the evidence contains the live domain, the database name and
the day it all happened.

---

## Before the day

- [ ] **1. Release built and verified.**
      `/d/php/php.exe bin/build-release.php`, then
      `/d/php/php.exe deploy/verify-release.php var/speedat-release-<stamp>.zip`.
      *Evidence:* the zip's filename, its size, its **SHA-256**, and the
      verifier's last line reading *"The archive is safe to upload"*.
- [ ] **2. Secrets generated and stored in the password manager** — `app.key`
      (`base64_encode(random_bytes(32))`), `tokens.cron` and `tokens.metrics`
      (`bin2hex(random_bytes(24))`, two **different** values).
      *Evidence:* the password-manager entry exists and is shared with whoever
      must be able to reach it. Never the values themselves in a note or a chat.
- [ ] **3. Tests green, and the code parses on both runtimes.**
      `/d/php/php.exe bin/test.php` (8.2, the build target), then
      `powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\lint.ps1`
      (`php -l` on 8.2 **and** 8.3, because the host's PHP version is a dropdown
      somebody can change).
      *Evidence:* the test runner's summary line and the linter's last line.

---

## On the host

- [ ] **4. hPanel settings confirmed.**
      Plan name **and purchase date** (Hostinger versions its limits by purchase
      date, so this decides which ceilings apply); PHP version **8.2 or 8.3**,
      not 8.4/8.5; extensions: `opcache`, `zip`, `fileinfo`, `gd` **on**, and note
      whether `intl` and `sodium` are on or off.
      *Evidence:* the plan name and date, and a screenshot of the PHP
      Configuration page showing the version and the extension toggles.
- [ ] **5. Database created and schema imported.**
      hPanel → Databases → MySQL Databases, then phpMyAdmin → Import →
      `db/migrations/0001_init.sql` and every later `00NN_*.sql`.
      *Evidence:* the **prefixed** database and user names (`u123456789_speedat`),
      the MariaDB version phpMyAdmin shows top-right, and this, which must list
      all **twelve** tables:
      ```sql
      SELECT table_name, table_rows FROM information_schema.tables
       WHERE table_schema = DATABASE() ORDER BY table_name;
      ```
      ```
      admins  app_settings  audit_log  draft  leads  quotes  rate_limits
      schema_migrations  sessions  shipment_events  shipments  versions
      ```
      **Twelve, not thirteen.** This line said thirteen and counted an
      `imports` table until 2026-09-27. That table left with the Excel
      rate-sheet feature the owner removed from the product; there is no import
      of any kind in the site now, and the only import on this page is
      phpMyAdmin's own, of the schema. A checklist that says thirteen makes you
      abandon a correct install. `deploy-hostinger.md` has carried the right
      count since that day — this is the copy that did not.
- [ ] **6. Files uploaded and the layout right.**
      Extract the zip at `domains/<domain>/` — **not** inside `public_html`.
      *Evidence:* File Manager shows `app/ bin/ config/ db/ public_html/ storage/
      var/ INSTALL.txt` side by side, and the uploaded zip's size matches item 1.
      Delete the zip from the server afterwards.
- [ ] **7. Permissions set.** `config/config.php` **600**, `config/` 700,
      `storage/` and below **700**, everything else 755 directories / 644 files.
      Never 777.
      **THIS STEP CREATES THE PERMISSIONS — IT DOES NOT TIDY THEM UP.** The
      archive carries no unix modes worth honouring (it is built on Windows)
      and File Manager writes the tree with the account's own umask. The
      2026-09-29 rehearsal extracted a correct release and got **664 files and
      775 directories** — group-writable, every one of them — so "it extracted
      fine" is not the same as "the permissions are right"
      ([`go-live-rehearsal.md`](./go-live-rehearsal.md) §2.2).
      **Run it again after every upgrade**, because an extract-over-the-top
      re-creates the same modes on every file it replaces.
      *Evidence:* a File Manager screenshot of `config/` and `storage/` showing
      the modes.
- [ ] **8. `config.php` filled in.** Copy `config.sample.php` → `config.php`
      (copy, then rename the copy, so the sample stays). `app.key`, `app.url`
      (absolute, no trailing slash), `app.env = production`, `app.debug = false`,
      `app.timezone = Asia/Karachi`, `app.trust_proxy = false`, the **prefixed**
      `db.*`, `auth.firebase_project_id`, `tokens.cron`, `tokens.metrics`,
      `mail.from` (an address on this domain), `mail.office`.
      *Evidence:* `curl -sS "https://<domain>/health?token=$METRICS_TOKEN"`
      returning `"database":"up"` with the expected `php` and `"env":"production"`.
      That one line proves items 5, 6, 7 and 8 all agree with each other.
- [ ] **9. `.user.ini` pasted** into `public_html/` from
      [`../deploy/htaccess/user.ini.sample`](../deploy/htaccess/user.ini.sample).
      *Evidence:* the file exists; the detailed `/health` shows the limits after
      the five-minute cache expires.
- [ ] **10. HTTPS active and forced.** Hostinger issues and forces it; do **not**
      add a second redirect. Leave `security.hsts` off for now.
      *Evidence:* `curl -sSI http://<domain>/ | head -1` shows a 301 to https, and
      `curl -sSI https://<domain>/ | head -1` shows 200.
- [ ] **11. Nothing private is served.** All three must be 403 or 404 — never a
      file:
      ```bash
      SITE=https://<domain>
      curl -sS -o /dev/null -w '%{http_code}  /.env\n'              $SITE/.env
      curl -sS -o /dev/null -w '%{http_code}  /router.php\n'        $SITE/router.php
      curl -sS -o /dev/null -w '%{http_code}  /config/config.php\n' $SITE/config/config.php
      ```
      *Evidence:* the three status codes, pasted as they printed. **A 200 on any
      of them stops the launch**: rename `public_html/index.php` to take the site
      down and fix the layout.
- [ ] **12. Security headers present.**
      `curl -sSI https://<domain>/ | grep -iE 'x-content-type|x-frame|referrer|permissions|x-powered-by'`
      *Evidence:* the header lines, and a note if `X-Content-Type-Options` is
      absent (`mod_headers` is not active — not fatal, but it decides where HSTS
      must come from later).
- [ ] **13. Domain added to Firebase's authorised domains.**
      Firebase console → Authentication → Settings → Authorised domains: the apex,
      and `www.` if it resolves.
      *Evidence:* a screenshot of the list. Without it sign-in fails in the
      browser with `auth/unauthorized-domain` and **nothing appears in any server
      log**.
- [ ] **14. Cron added.** hPanel → Advanced → Cron Jobs, hourly (`0 * * * *`):
      `/usr/bin/php /home/uXXXXXXXX/domains/<domain>/bin/cron.php` — absolute
      path, no `>` or `2>&1`.
      *Evidence:* a `cron_run` line in `storage/logs/app-<today>.log` after the
      first hour, **and the PHP version on its environment line** (CLI PHP is not
      documented to match the website's).

---

## The first admin, and the real rates

- [ ] **14b. A SIGN-IN METHOD IS SWITCHED ON.** Do this **before** item 16, or
      you will be looking at a login page with no form on it.
      `config.sample.php` ships `auth.firebase_project_id` empty **and**
      `auth.allow_password_login => false`, and with both unset
      `Http\Admin\LoginController` prints no sign-in form at all — only
      *"Sign-in is not configured on this deployment yet."* That is the code
      being honest, and it is exactly what a correct install of a correct
      release looks like until you choose. Pick one in `config/config.php`:
      ```php
      'firebase_project_id'  => 'your-firebase-project',   // Google sign-in
      // or
      'allow_password_login' => true,                      // password + TOTP
      ```
      *Evidence:* `/admin/login` showing the method you chose.
      *(Found by the 2026-09-29 go-live rehearsal, which seeded an owner and
      then could not get in — [`go-live-rehearsal.md`](./go-live-rehearsal.md)
      §3.2. This step did not exist before that run.)*
- [ ] **15. First admin created.** There is no shell on this plan, so either add
      a one-off hPanel cron entry
      `/usr/bin/php /home/uXXXXXXXX/domains/<domain>/bin/seed.php --owner=<owner email>`
      and **delete the entry once it has run** (it also writes the price-hold
      setting, off), or insert the row in phpMyAdmin:
      ```sql
      INSERT INTO admins (email, name, role, added_by)
      VALUES ('owner@example.com', 'Owner name', 'owner', 'install');
      ```
      *Evidence:* `SELECT email, role FROM admins;` and
      `SELECT k, v FROM app_settings WHERE k='hold';` showing `"on": false`.
- [ ] **16. The owner signs in at `/admin`**, and an account that is **not** in
      `admins` is refused.
      *Evidence:* both outcomes, and the two rows they wrote in `audit_log`.
- [ ] **17. Two-factor enrolled for every owner.** *Security* → scan the code →
      type the 6 digits.
      *Evidence:* `SELECT email, totp_enabled FROM admins;` — every row `1`.
- [ ] **18. Real rates published with "Rates are live" ticked.** A fresh database
      serves the sample document and carries the sample notice until this is done.
      Key the real prices in, review, publish, tick the box.
      *Evidence:* the version number and its timestamp from *History*, and
      `"live":true` with that version in the detailed `/health`.
- [ ] **19. Settings and words checked by the owner** — company details, WhatsApp
      number, phone, address, working days, **the year's holidays**, and the
      About / Services / FAQ text as they appear on the live pages.
      *Evidence:* the owner's confirmation, dated, naming the version he read.

---

## Prove it end to end

- [ ] **20. A quote booked on a real phone, on the live domain.** Country →
      weight → the price → **Book**. Do it on a phone, on mobile data, not on a
      desktop browser made narrow.
      *Evidence:* the quote id (`SP-…`), and the lead visible in *Inbox*.
- [ ] **21. The WhatsApp message arrives on the office phone**, carrying the
      destination, weight, service, price and dates.
      *Evidence:* a screenshot of the received message.
- [ ] **22. A contact-form message arrives** in *Inbox* and by email to
      `mail.office`.
      *Evidence:* the lead, and the received email. If mail fails, *Security* →
      deployment checks says so and the fix is SMTP — see
      [`runbook.md`](./runbook.md) §10.
- [ ] **23. Lead → shipment → status update → WhatsApp update.**
      *Evidence:* the shipment id (`SH-…`), a timestamped event in its timeline,
      and a screenshot of the customer message as received.
- [ ] **24. The audit log recorded all of it.**
      ```sql
      SELECT at, action, actor FROM audit_log ORDER BY id DESC LIMIT 20;
      ```
      *Evidence:* the sign-in, the publish, the booking and the shipment update,
      all present. **This one is not optional.** If the log is empty the site is
      running but not recording, and you find that out the next time somebody asks
      who changed a price.

---

## Web hygiene

- [ ] **25. Sitemap submitted.** `curl -sS https://<domain>/sitemap.xml` is valid
      XML with no localhost URLs, and `/robots.txt` points at it. Then Google
      Search Console → verify the property → submit the sitemap.
      *Evidence:* the URL count, and the Search Console submission date.
- [ ] **26. Lighthouse recorded** on the live domain, **mobile**, on `/` and
      `/services`. Target 90+ for performance, accessibility, best practices and
      SEO.
      *Evidence:* the four scores per page, and the date. Record them even if one
      is short — the number you have is worth more than the number you meant to
      take.
- [ ] **27. Uptime monitor pointed at `/health`**, every five minutes, expecting
      200 and `{"ok":true}`.
      *Evidence:* the monitor's name and its first green check.

---

## Before you call it launched

- [ ] **28. Rollback rehearsed once, on the live host.** Upload the previous
      release zip, extract over the top, reset OPcache, `/health` green, then put
      the current release back the same way. Do it now, while nothing is wrong and
      nobody is shouting.
      *Evidence:* the time it took, and a note of anything that surprised you.
- [ ] **29. Backups confirmed and one restore rehearsed.** hPanel → Files →
      Backups shows a files backup and a database backup; a `.sql.gz` export is
      saved off Hostinger; it has been restored once into the local MariaDB.
      *Evidence:* the backup dates, the export filename, the restore date —
      written into [`runbook.md`](./runbook.md) §6 as the last rehearsal.
- [ ] **30. Hand-over done.** The owner has read
      [`admin-guide.md`](./admin-guide.md); whoever keeps it running has
      [`runbook.md`](./runbook.md) and can reach hPanel, phpMyAdmin, Firebase and
      the password manager.
      *Evidence:* named people against each account, dated.
- [ ] **31. The release is tagged and archived**, with its SHA-256 from item 1 and
      a copy of the zip kept off both the server and the build machine.
      *Evidence:* the tag and where the copy lives.

---

**Then, and only then, tell people the site is live.** Two things are deliberately
left for later, after a week or two of clean running: `security.hsts` (a browser
remembers it for a year — see [`deploy-hostinger.md`](./deploy-hostinger.md) §9,
and never add `preload`), and any ModSecurity exception, which is scoped to the
one admin path that needed it and never to the site.
