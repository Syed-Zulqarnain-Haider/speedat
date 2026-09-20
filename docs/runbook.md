# Speedat runbook

Operational procedures for the production site (Vercel + Neon). Every step here can be
done by someone who has never seen the code, given the accounts.

## Where things are

| What | Where |
|---|---|
| Hosting, logs, cron, env vars | Vercel project → Deployments / Logs / Settings → Environment Variables |
| Database (Postgres) | Neon project → branch `main`; connection string in Vercel env `DATABASE_URL` |
| Admin sign-in | Firebase project → Authentication; who may enter = `admins` table |
| Email in (rate sheets) | Postmark inbound server → webhook `https://…/api/inbound/email` |
| Email out (alerts, leads) | Postmark outbound server token `POSTMARK_SERVER_TOKEN` |
| Health | `GET /api/health` (200 = serving, 503 = database unreachable) |
| Metrics | `GET /api/metrics` with `Authorization: Bearer $METRICS_TOKEN` |
| Errors | Vercel Logs (structured JSON lines) and Sentry if `SENTRY_DSN` is set |

## Deploy

Every push to `main` deploys automatically on Vercel. Preview deployments are created for
branches. Database migrations are **not** automatic:

1. `pnpm db:migrate` against the production `DATABASE_URL` **before** merging a change that
   adds a migration (migrations are additive; the old code keeps working during the window).
2. Merge / push. Watch the deployment finish, then open `/api/health`.

### First deployment on Vercel

From a machine logged in with `vercel login`, in the repository root:

1. `vercel link --yes --project speedat` — creates the project (Next.js is detected).
2. Database: Vercel dashboard → the project → *Storage* → *Create database* → **Neon**
   (Marketplace). This injects `DATABASE_URL` (pooled) into the project. Any other Postgres
   works too: set `DATABASE_URL` by hand with `vercel env add DATABASE_URL production`.
3. Secrets: `vercel env add <NAME> production` for every variable in `.env.example` marked
   required — `NEXT_PUBLIC_FIREBASE_*`, `FIREBASE_SERVICE_ACCOUNT_B64`, `APP_SECRET`,
   `IP_HASH_SALT`, `CRON_SECRET`, `INBOUND_EMAIL_SECRET`, `METRICS_TOKEN`,
   `NEXT_PUBLIC_SITE_URL`. Generate secrets with `openssl rand -base64 48`; never reuse the
   development values.
4. `vercel env pull .env.production.local` then
   `pnpm exec dotenv -e .env.production.local -- drizzle-kit migrate` and
   `ADMIN_EMAIL=<owner email> pnpm exec dotenv -e .env.production.local -- tsx scripts/seed.ts`
   (seeds version 1 with per-kilogram sample boxes and the first owner). Delete
   `.env.production.local` afterwards.
5. `vercel deploy --prod`. Add the deployment domain (and later the real domain) under
   Firebase → Authentication → Settings → *Authorised domains*, or sign-in fails.
6. Open `/api/health`, sign in at `/admin`, import the real rates, publish with *Rates are live*.

### Running on a Hostinger VPS instead

The app is a plain Node server, so it moves to any VPS without code changes; only the
environment and the cron differ. Hostinger's shared/"Website" plans cannot run it (they serve
PHP); use a **VPS** (KVM 1 is enough for this traffic) with Ubuntu.

1. Install Node 22 and pnpm (`corepack enable && corepack prepare pnpm@latest --activate`),
   Nginx, and either Postgres 16 locally (`apt install postgresql`) or keep Neon.
2. `git clone` the repository to `/srv/speedat`, `pnpm install --frozen-lockfile`,
   put the production variables in `/srv/speedat/.env.production.local` (mode 600; same
   list as the Vercel step; `NEXT_PUBLIC_SITE_URL` = the real domain), then
   `pnpm db:migrate`, seed once, `pnpm build`.
3. Run it under a supervisor: `pm2 start "pnpm start" --name speedat` (`pnpm start` binds
   port 3000) and `pm2 save && pm2 startup` so it survives reboots.
4. Nginx: a server block for the domain proxying to `http://127.0.0.1:3000` with
   `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` and
   `X-Forwarded-Proto https` (the rate limiter and audit log read the client IP from that
   header), `client_max_body_size 10m` for sheet uploads, and TLS from certbot.
5. Cron: Vercel's `vercel.json` cron does not exist here. Add to root's crontab:
   `0 */2 * * * curl -fsS -H "Authorization: Bearer $CRON_SECRET" https://<domain>/api/cron/check-sheets >/dev/null`
6. Deploy updates with `git pull && pnpm install --frozen-lockfile && pnpm db:migrate && pnpm build && pm2 restart speedat`.
   Backups: `pg_dump` nightly (see below) if Postgres runs on the VPS.

One `next start` process keeps the site cache and the price hold in memory and on disk under
`.next/cache`; publishing and holding from the admin expire it in the same process, so
nothing else is needed. Do not run two instances behind Nginx without a shared cache handler.

## Rollback

- **Code**: Vercel → Deployments → previous deployment → *Promote to Production*. Under a minute.
- **Rates**: Admin → Rates → *Version history* → *Restore into editor* on the last good
  version → *Review and publish*. The site updates immediately.

## Backups and restore

Neon keeps point-in-time history for the project (default 24 h on the free tier, longer on
paid). To restore:

1. Neon → Branches → *Restore* → choose the time → this creates a branch with the data as of
   that moment.
2. Test it: point a preview deployment's `DATABASE_URL` at the branch, open `/admin`.
3. Either promote the branch to `main` in Neon or copy the needed rows back.

Every publish is also an immutable row in `versions` with the full document, so "restore the
rates" never needs a database restore — only "restore leads/shipments" does. Test the restore
procedure once a quarter and note the date here: _last tested: —_.

## Rotate secrets

| Secret | Effect of rotation | How |
|---|---|---|
| `DATABASE_URL` | none for users | Neon → reset password → update Vercel env → redeploy |
| `FIREBASE_SERVICE_ACCOUNT_B64` | admins must sign in again | Firebase → Service accounts → new key → base64 → Vercel env |
| `INBOUND_EMAIL_SECRET` | emailed sheets refused until Postmark updated | change env, then update the webhook URL in Postmark |
| `CRON_SECRET`, `METRICS_TOKEN` | none | change env; update the metrics scraper |
| `APP_SECRET` / `TOTP_ENCRYPTION_KEY` | **all admins' 2FA breaks** (secrets become undecryptable); contact-form tokens issued before are invalid for a minute | change env, then every admin re-enrols 2FA under Security |
| `POSTMARK_SERVER_TOKEN` | alerts silently become "logged only" until fixed — check the Security page's deployment checks | Postmark → API tokens |

## Incidents

### "The site shows wrong prices"

1. Open `/admin` → *Version history*. Find the version whose date matches the change.
   The summary says whether it came from `admin`, `email:<sender>` (auto-published sheet) or `restore`.
2. If a sheet was auto-published with bad numbers: *Restore into editor* the previous
   version → *Review and publish*. Then lower *Auto-publish tolerance* (Import from Excel →
   Email intake settings) or set it to 0 to require manual publishing.
3. If a human published it: same restore; the audit log (Security → Recent activity) shows who.
4. Quotes customers already made are stored with the version that priced them
   (`quotes.version`), so disputes can be checked.

### "Prices must come off the site now"

`/admin` → *Hold prices* (any admin). The site shows the hold message and a WhatsApp form
instead of prices; `/api/quotes` answers 409 `held`. Publishing new rates with *Show prices
again after publishing* ticked, or *Resume showing prices* (owner), puts them back. The switch
is the `hold` row in `app_settings`, outside the published document, so it never touches the
draft or the version history; the cron alerts once a hold passes `HOLD_ALERT_HOURS` (12).

### "No rates arrived today"

The cron emails once per day when nothing came by the expected hour. Check the airline
actually sent it (spam folder on the forwarding mailbox), then either forward the email to the
intake address or upload the file under *Import from Excel*.

### "A sheet is waiting / needs mapping"

Admin → Import from Excel: emailed sheets with an unknown layout wait at the top with a
*Map columns* button; sheets that were applied but held show the reason in *Recent sheets*.
Map once — the layout is remembered.

### "Admin cannot sign in"

- *This account is not an admin*: add the email to `admins` (`ADMIN_EMAIL=… pnpm db:seed`).
- Lost authenticator: an owner clears `totp_enabled`/`totp_secret` for that email in the
  `admins` table (Neon SQL editor); the admin re-enrols under Security.
- *Sign-in is not configured*: `FIREBASE_SERVICE_ACCOUNT_B64` or the `NEXT_PUBLIC_FIREBASE_*`
  values are missing on Vercel.

### "/api/health returns 503"

Database unreachable. The public site keeps serving the last cached rates (5-minute cache
per instance) but the admin, quotes log and tracking are down. Check Neon status and the
connection string; nothing on the site needs to be restarted.

## Monitoring checklist

- Uptime check on `GET /api/health` expecting `"status":"ok"` every 5 minutes.
- Scrape `/api/metrics`; alert on `speedat_rates_publish_age_seconds > 7 days`,
  `speedat_imports_pending > 0` for more than a day, `speedat_notify_failed_24h > 0`.
- Vercel cron runs `/api/cron/check-sheets` every two hours; its JSON response shows what it
  checked. Alerts are emailed at most once per day per kind.
