# Partner logos

The "Our partners" section on the home page shows one logo per carrier. Drop the
files here with these exact names (all lowercase, PNG):

| File             | Partner    |
| ---------------- | ---------- |
| `dhl.png`        | DHL        |
| `fedex.png`      | FedEx      |
| `teamglobal.png` | TeamGlobal |
| `ups.png`        | UPS        |
| `dpt.png`        | DPT        |
| `skynet.png`     | Skynet     |

Notes:

- The filename (the "slug") is fixed in `app/Views/site/partials/partners.php`.
  Match it byte for byte or the logo will not show.
- Any logo that is missing falls back to the partner name shown as plain text,
  so the section never breaks while you are still collecting files.
- PNG with a transparent background reads best in both light and dark mode. Aim
  for roughly 240px wide (logos are shown small); the layout does not depend on
  an exact size.
- The content security policy only allows images served from this site, so the
  files must live here and be referenced by their own path (which they are).
