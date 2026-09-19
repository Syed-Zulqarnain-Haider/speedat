# Speedat International Courier

Instant Express / Normal courier prices from Pakistan by destination, weight and size,
booked on WhatsApp — plus the rates admin that keeps those prices current without
re-keying airline rate sheets.

## Stack

- Next.js 16 (App Router, TypeScript strict, Tailwind 4) on Vercel
- PostgreSQL (Neon in production) via Drizzle ORM
- Pricing engine shared by browser and server: `src/lib/pricing`
- Firebase Auth for the admin; the `admins` table decides who may edit

## Local development

```bash
pnpm install
cp .env.example .env.local        # fill DATABASE_URL at least
pnpm db:migrate                   # apply drizzle/ migrations
ADMIN_EMAIL=you@example.com pnpm db:seed   # version 1 = sample rates, first admin
pnpm dev
```

Checks: `pnpm check` (lint + typecheck + tests).

## Layout

```
src/app/            routes (site, admin, api)
src/lib/pricing/    pure pricing engine, dates, formatting (+ tests)
src/lib/site/       site document types, seed, migrate, repository
src/lib/import/     rate-sheet parsing and column mapping
src/lib/db/         Drizzle schema and client
drizzle/            SQL migrations
scripts/            seed
```
