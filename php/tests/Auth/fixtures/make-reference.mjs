/**
 * Regenerates qr-reference.json: known-good QR matrices from the `qrcode` npm
 * package - the same encoder the Next.js app used - for the PHP encoder to be
 * checked against, module for module.
 *
 * THIS IS A DEVELOPMENT TOOL AND IS NEVER UPLOADED TO THE SERVER. The test that
 * reads the fixture needs nothing but PHP; only regenerating it needs Node and
 * the Next.js app's node_modules.
 *
 *   cd /d/speedat && node php/tests/Auth/fixtures/make-reference.mjs
 *
 * Every case is pinned to one version, error correction M, byte mode and one
 * explicit mask, so the fixture records the encoder's output and not its
 * choices - the PHP side is then free to pick masks by its own penalty scoring
 * without the comparison becoming a comparison of heuristics.
 */
import { writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import QRCode from "qrcode";

// Byte-mode capacity at error correction level M, versions 1..10.
const CAPACITY = [14, 26, 42, 62, 84, 106, 122, 152, 180, 213];

/** Deterministic filler that exercises the full byte range, not just ASCII. */
function filler(length) {
  let out = "";
  for (let i = 0; i < length; i++) {
    // Printable ASCII, cycling - kept readable so a failing diff is legible.
    out += String.fromCharCode(33 + ((i * 7) % 94));
  }
  return out;
}

const cases = [
  { name: "hello", text: "HELLO WORLD" },
  {
    name: "otpauth",
    text:
      "otpauth://totp/Speedat%20Admin:owner%40speedat.pk?issuer=Speedat%20Admin" +
      "&secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&algorithm=SHA1&digits=6&period=30",
  },
  { name: "single", text: "a" },
  { name: "binaryish", text: "\u0000\u0001\u0002 tab\there ÿ" },
];

for (let version = 1; version <= 10; version++) {
  cases.push({ name: `full-v${version}`, text: filler(CAPACITY[version - 1]) });
}

const out = { generator: "npm qrcode " + (await packageVersion()), cases: [] };

for (const testCase of cases) {
  const bytes = Buffer.from(testCase.text, "binary");
  const masks = [];

  for (let mask = 0; mask <= 7; mask++) {
    const qr = QRCode.create([{ data: bytes, mode: "byte" }], {
      errorCorrectionLevel: "M",
      maskPattern: mask,
    });

    let rows = [];
    for (let row = 0; row < qr.modules.size; row++) {
      let line = "";
      for (let col = 0; col < qr.modules.size; col++) {
        line += qr.modules.data[row * qr.modules.size + col] ? "1" : "0";
      }
      rows.push(line);
    }

    masks.push({ mask, version: qr.version, size: qr.modules.size, rows });
  }

  out.cases.push({
    name: testCase.name,
    // Latin-1 bytes, so the fixture is plain JSON and the PHP side gets the
    // exact same bytes back without guessing an encoding.
    textBase64: Buffer.from(testCase.text, "binary").toString("base64"),
    masks,
  });
}

async function packageVersion() {
  const { readFileSync } = await import("node:fs");
  const path = join(process.cwd(), "node_modules", "qrcode", "package.json");
  return JSON.parse(readFileSync(path, "utf8")).version;
}

const target = join(dirname(fileURLToPath(import.meta.url)), "qr-reference.json");
writeFileSync(target, JSON.stringify(out, null, 1) + "\n");
console.log("wrote " + target + " (" + out.cases.length + " cases x 8 masks)");
