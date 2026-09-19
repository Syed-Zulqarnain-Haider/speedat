# Speedat International Courier

Instant Express / Normal courier prices from Pakistan by destination, weight and size,
booked on WhatsApp — plus the rates admin that keeps those prices current **without
re-keying airline rate sheets**: sheets are uploaded or emailed in, their column layout is
learned once, and known layouts are applied (and, within a tolerance you set, published)
automatically.

## How it fits together

```
 airline Excel ──► email webhook / admin upload ──► parse + remembered layout ──► draft
                                                                                  │
                                                     review · diff · validate · publish
                                                                                  │
 customer ──► static site + in-browser calculator ◄── published version (immutable JSONB)
```

- **Versions are immutable.** Every publish stores the whole site document (rates, settings,
  page text) as a new row in `versions`; the site serves the highest version and is
  re-generated on publish (`revalidateTag`). Older versions can be restored into the draft.
- **One draft.** The admin edits `draft` (autosaved); publishing refuses a stale base.
- **Pricing runs in the browser** from the published document (`src/lib/pricing`), so quotes
  cost nothing per user. The same engine re-prices logged quotes server-side.
- **Admin identity = Firebase Auth; admin authorisation = `admins` table.** A valid Google
  account that is not listed gets nothing. Owners publish; editors stage.
- **Audit log is append-only** and records logins, publishes, imports, intake decisions.

## Stack

Next.js 16 (App Router, TypeScript strict, Tailwind 4) · PostgreSQL (Neon) via Drizzle ·
SheetJS for workbooks · Firebase Auth · Vercel (hosting + cron) · Postmark inbound email.

## Local development

```bash
pnpm install
cp .env.example .env.local           # set DATABASE_URL (any Postgres 14+)
pnpm db:migrate                      # apply drizzle/ migrations
ADMIN_EMAIL=you@example.com pnpm db:seed   # version 1 = sample rates, first owner
pnpm dev
```

To open the admin locally without Firebase, add `DEV_ADMIN_EMAIL=you@example.com` to
`.env.local` (the address must exist in `admins`). This bypass only works when
`NODE_ENV=development`.

Checks: `pnpm check` (lint + typecheck + tests). Set `TEST_DATABASE_URL` to a scratch
database to include the version-store integration test.

## Deploying

1. **Neon** — create a project, copy the *pooled* connection string into `DATABASE_URL`.
   Run `pnpm db:migrate` and `ADMIN_EMAIL=<owner> pnpm db:seed` once against it.
2. **Firebase** — create a project, enable Google and/or Email-Password sign-in, add the
   web app config to `NEXT_PUBLIC_FIREBASE_*`, and put a service-account key (base64 of
   the JSON) in `FIREBASE_SERVICE_ACCOUNT_B64`. Add each admin's email to `admins`
   (seed, or insert directly).
3. **Vercel** — import the repo, set every variable from `.env.example`, add
   `CRON_SECRET`; `vercel.json` schedules `/api/cron/check-sheets` every two hours.
4. **Email intake (Postmark)** — create an inbound server, point its webhook at
   `https://anything:<INBOUND_EMAIL_SECRET>@<your-domain>/api/inbound/email`, and give
   the inbound address (or a forwarding alias such as `rates@speedat.net`) to the airlines.
   In the admin's *Email intake settings* choose the auto-publish tolerance, the allowed
   senders and the hour by which a sheet is expected.
5. **Domain** — set `NEXT_PUBLIC_SITE_URL` to the public origin (used for metadata, sitemap).

## Daily operation

- A sheet arrives by email → if its layout is known it is applied to the draft; if every
  validation passes and no price moves more than the tolerance it is published, otherwise
  it waits under *Import from Excel* with the reason. Unknown layouts wait for a one-time
  column mapping.
- Uploading a sheet in the admin always opens the mapper (pre-filled) so you can check it.
- *Review and publish* shows every change against the live version; moves ≥ 25 % are flagged.
- The public site shows "Rates updated <date>" and keeps serving the last version if the
  database is ever unreachable.

## Layout

```
src/app/(site)/        public pages (static, ISR 5 min, revalidated on publish)
src/app/admin/         admin page, login, server actions
src/app/api/           quotes log, auth session, inbound email webhook, cron
src/components/        calculator, admin editor, icons
src/lib/pricing/       pure pricing engine, dates, formatting, quote text (+ tests)
src/lib/site/          site document types, seed, migrate, diff/validate, repository
src/lib/import/        sheet parsing, mapping memory, intake pipeline, workbook reader
src/lib/auth/          Firebase admin/client, session cookie, admin lookup
src/lib/db/            Drizzle schema and client
drizzle/               SQL migrations
scripts/seed.ts        first version + first admin
tests/fixtures/        realistic carrier workbooks used by the tests
```

## Not built (on purpose, for now)

- Notifications (WhatsApp/email) when a sheet is held or missing — the admin shows both;
  wiring an outbound provider is a small follow-up once one is chosen.
- Rate limiting on the public quote log beyond input caps — enable Vercel's WAF rate
  limiting or add an edge limiter if abuse appears.
- Weight-slab *grid* sheets (0.5 / 1 / 1.5 … kg columns). The importer reads
  "first + additional" layouts; a grid mode is straightforward once a real sheet is available.
