# app/Domain

The business of the site, split by what it is about rather than by what kind
of file it is. `Core/` is machinery — routing, database, sessions, templates —
and knows nothing about couriers. Everything in here knows about couriers and
nothing about HTTP.

Each folder is filled by the phase that ports the TypeScript behind it; the
ones still empty have not had their phase yet. The namespace is
`Speedat\Domain\<Folder>` and the autoloader already resolves it.

| Folder       | Ports from the Next.js app | Holds |
|--------------|----------------------------|-------|
| `Pricing/`   | `src/lib/pricing/{types,engine,dates,format,quote}.ts` | **Ported.** The price boxes, volumetric weight, the document rate, tax and rounding, delivery estimates, the cargo threshold, the WhatsApp messages. The numbers match the TypeScript exactly and are held there by 940 golden vectors, which also check the browser twin in `public/assets/js/pricing.js` — see [`docs/engine-parity.md`](../../docs/engine-parity.md). |
| `SiteDoc/`   | `src/lib/site/{types,schema,migrate,seed,diff,repo,live,hold,countries}.ts` | The published site document, the draft, versions, validation and diffs, and the price hold. One immutable JSON snapshot per publish, exactly as the Postgres version stores it. **Partly ported:** `Validate` (the `validateSite`/`warnSite` half of `diff.ts`) and a minimal `SiteData`, because the pricing vectors cover the publishing rules; the rest is the next phase. |
| `Import/`    | `src/lib/import/**` | Excel intake. XLSX is read with `ZipArchive` + `XMLReader` — no Composer, no PhpSpreadsheet. |
| `Leads/`     | `src/lib/leads.ts` | Quote requests and contact messages, and the admin inbox that works them. |
| `Shipments/` | `src/lib/shipments.ts` | Booked shipments, their events and the public tracking page. |
| `Admins/`    | `src/lib/auth/session.ts` (the `admins` half) | Who may open the admin, and what `owner` may do that `editor` may not. |
| `Auth/`      | `src/lib/auth/{firebase-admin,firebase-client,totp}.ts` | Firebase ID-token verification (RS256 against Google's published certificates, via `openssl_verify`) and TOTP (`hash_hmac`). Secrets are encrypted at rest with the application key. |

Two rules that apply to everything placed here:

1. **No SQL outside a repository class.** A domain folder may hold one class
   that talks to `Core\Db`; the rest work on values. That is what keeps the
   pricing engine testable without a database.
2. **No `$_GET`, `$_POST`, `$_SERVER` or `echo`.** A domain class receives
   values and returns values. HTTP belongs to `Core\Request`, `Core\Response`
   and the route handlers.
