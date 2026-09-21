/**
 * Copies every 4:3 flag from the MIT-licensed flag-icons package into
 * public/flags/ (plus its licence notice) so the site serves flags as local
 * SVG files under the CSP's img-src 'self'. Idempotent: run `pnpm flags`
 * after upgrading flag-icons; the copied files are committed, so Vercel
 * needs no build step.
 */
import { copyFileSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const src = join(root, "node_modules", "flag-icons", "flags", "4x3");
const dest = join(root, "public", "flags");

mkdirSync(dest, { recursive: true });
const files = readdirSync(src).filter((f) => f.endsWith(".svg"));
for (const f of files) copyFileSync(join(src, f), join(dest, f));

const pkg = JSON.parse(readFileSync(join(root, "node_modules", "flag-icons", "package.json"), "utf8"));
const licence = readFileSync(join(root, "node_modules", "flag-icons", "LICENSE"), "utf8");
writeFileSync(
  join(dest, "LICENSE.txt"),
  `The flags in this folder are copied unchanged from flag-icons ${pkg.version}\n(https://github.com/lipis/flag-icons), distributed under the MIT License:\n\n${licence}`,
);

console.log(`Copied ${files.length} flags from flag-icons ${pkg.version} to public/flags/`);
