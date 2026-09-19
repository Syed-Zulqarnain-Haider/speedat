# Launch checklist

Tick each item with the evidence (a link, a screenshot, a date). Nothing goes live with an
unticked box.

## Accounts and secrets
- [ ] Neon project created; pooled `DATABASE_URL` in Vercel (Production and Preview).
- [ ] `pnpm db:migrate` run against production; `ADMIN_EMAIL=<owner> pnpm db:seed` run once.
- [ ] Firebase project: Google sign-in (and/or email-password) enabled; `NEXT_PUBLIC_FIREBASE_*`
      and `FIREBASE_SERVICE_ACCOUNT_B64` set on Vercel; the production domain added to
      Firebase → Authentication → Authorised domains.
- [ ] `APP_SECRET`, `IP_HASH_SALT`, `CRON_SECRET`, `INBOUND_EMAIL_SECRET`, `METRICS_TOKEN` set
      (long random strings).
- [ ] Postmark: outbound server token (`POSTMARK_SERVER_TOKEN`), `NOTIFY_FROM` verified sender,
      `OFFICE_EMAIL`; inbound server webhook set to
      `https://user:<INBOUND_EMAIL_SECRET>@<domain>/api/inbound/email`.
- [ ] `NEXT_PUBLIC_SITE_URL` = the public origin (https://speedat.net).
- [ ] Optional: `SENTRY_DSN` / `NEXT_PUBLIC_SENTRY_DSN`.

## Content and rates
- [ ] Real rates imported (upload) and published; *Rates are live* ticked so the sample notice
      is gone. Evidence: version number and date.
- [ ] Company details, address, hours, WhatsApp number checked on the live Contact page.
- [ ] FAQ, Services and About text reviewed by the owner.
- [ ] Holidays for the coming year entered in Settings.
- [ ] Email intake: allowed senders set; auto-publish tolerance decided (start with 0 or a
      small number); expected hour set.

## Flows tested on the live domain (phone and desktop)
- [ ] Quick rate → Book now → WhatsApp opens with the right message on the office phone.
- [ ] Detailed quote with two packages, dimensions and documents.
- [ ] Contact form message arrives in the admin Inbox and by email.
- [ ] Lead → shipment → status update → `/track` shows it; WhatsApp update message correct.
- [ ] Airline sheet emailed to the intake address is applied (known layout) or waits for
      mapping (new layout), and the office receives the alert.
- [ ] Admin sign-in with Google; 2FA enrolled for every owner.
- [ ] `/api/health` returns `"status":"ok"`; uptime monitor pointed at it.
- [ ] Vercel cron shows a successful run of `/api/cron/check-sheets`.

## Web hygiene
- [ ] Domain on Vercel with HTTPS; `www` redirects to the apex (or the reverse).
- [ ] Google Search Console: property verified, `/sitemap.xml` submitted.
- [ ] Security headers visible (`curl -I https://<domain>` shows CSP, HSTS, nosniff).
- [ ] Lighthouse mobile on `/` and `/services`: performance, accessibility, best practices,
      SEO — record the scores.

## Hand-over
- [ ] Owners have read `docs/admin-guide.md`; ops contact has `docs/runbook.md`.
- [ ] Restore procedure tested once (see runbook) and dated.
- [ ] Tag `v1.0.0` in git.
