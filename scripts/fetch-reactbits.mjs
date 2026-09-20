/**
 * Pull React Bits components from their shadcn-style registry into
 * src/components/bits/. Usage: node scripts/fetch-reactbits.mjs SplitText CountUp ...
 * Prints each component's npm dependencies so they can be installed.
 */
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";

const names = process.argv.slice(2);
if (!names.length) {
  console.error("usage: node scripts/fetch-reactbits.mjs <Component> [...]");
  process.exit(1);
}
const outDir = path.join(process.cwd(), "src", "components", "bits");
await mkdir(outDir, { recursive: true });
const deps = new Set();
for (const name of names) {
  const url = `https://reactbits.dev/r/${name}-TS-TW.json`;
  const res = await fetch(url);
  if (!res.ok) {
    console.error(`${name}: HTTP ${res.status}`);
    continue;
  }
  const item = await res.json();
  for (const d of item.dependencies ?? []) deps.add(d);
  for (const f of item.files ?? []) {
    const file = path.join(outDir, path.basename(f.path));
    await writeFile(file, f.content, "utf8");
    console.log(`${name}: wrote ${path.relative(process.cwd(), file)} (${f.content.length} chars)`);
  }
}
console.log("dependencies:", [...deps].join(" ") || "(none)");
