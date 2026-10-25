"use strict";

/**
 * The runner.
 *
 *   node run.cjs                              every flow, against the local dev server
 *   node run.cjs quick-rate-phone             one flow by name
 *   node run.cjs admin-login admin-hold       several
 *   node run.cjs --base http://127.0.0.1:8085 against staging over the SSH tunnel
 *   node run.cjs --list                       what flows exist
 *
 * Exit codes, because this is meant to be called from a script as well as read:
 *
 *   0  nothing failed (some flows may be NOT READY - see --strict)
 *   1  at least one check FAILED
 *   2  the site is not up; nothing was tested
 *   3  the harness could not start (no Playwright, bad arguments)
 *
 * --strict turns NOT READY into a failure. That is the release gate: when the
 * port is finished, `node run.cjs --strict` must exit 0, and until then the
 * same command tells you exactly how much is left.
 */

const fs = require("fs");
const path = require("path");

let harness;
try {
  harness = require("./harness.cjs");
} catch (e) {
  process.stderr.write("harness.cjs failed to load:\n" + (e && e.stack ? e.stack : e) + "\n");
  process.exit(3);
}

const { resolvePlaywright, resolveBaseUrl, preflight, loadFlows, runFlows, summarise, COLOUR, SHOTS_DIR } = harness;

function usage() {
  return [
    "Usage: node run.cjs [flow …] [options]",
    "",
    "Options:",
    "  -b, --base URL     the site to test (default $SPEEDAT_BASE_URL, else http://127.0.0.1:8081)",
    "      --list         list the flows and exit",
    "      --headed       show the browser",
    "      --slow MS      slow every action down by MS, to watch it work",
    "      --strict       treat NOT READY as a failure (the release gate)",
    "      --no-shots     do not write screenshots",
    "      --json FILE    also write the whole report as JSON",
    "  -v, --verbose      print measurements and notes as they happen",
    "  -h, --help         this",
    "",
    "Screenshots land in php/tests/browser/shots/. See php/docs/browser-tests.md.",
  ].join("\n");
}

function parseArgs(argv) {
  const opts = {
    names: [],
    base: null,
    list: false,
    headed: false,
    slowMo: 0,
    strict: false,
    noShots: false,
    json: null,
    verbose: false,
  };

  for (let i = 0; i < argv.length; i += 1) {
    const a = argv[i];
    const next = () => {
      i += 1;
      if (i >= argv.length) throw new Error(a + " needs a value");
      return argv[i];
    };
    if (a === "-h" || a === "--help") return { help: true };
    else if (a === "-b" || a === "--base") opts.base = next();
    else if (a.startsWith("--base=")) opts.base = a.slice(7);
    else if (a === "--list") opts.list = true;
    else if (a === "--headed") opts.headed = true;
    else if (a === "--slow") opts.slowMo = Number(next()) || 0;
    else if (a === "--strict") opts.strict = true;
    else if (a === "--no-shots") opts.noShots = true;
    else if (a === "--json") opts.json = next();
    else if (a === "-v" || a === "--verbose") opts.verbose = true;
    else if (a.startsWith("-")) throw new Error("unknown option: " + a);
    else opts.names.push(a);
  }
  return opts;
}

async function main() {
  let opts;
  try {
    opts = parseArgs(process.argv.slice(2));
  } catch (e) {
    process.stderr.write(e.message + "\n\n" + usage() + "\n");
    return 3;
  }
  if (opts.help) {
    process.stdout.write(usage() + "\n");
    return 0;
  }

  let all;
  try {
    all = loadFlows();
  } catch (e) {
    process.stderr.write("could not load the flows: " + e.message + "\n");
    return 3;
  }

  if (opts.list) {
    process.stdout.write("\n" + all.length + " flows:\n\n");
    for (const f of all) {
      process.stdout.write("  " + f.name.padEnd(26) + COLOUR.dim + (f.title || "") + COLOUR.off + "\n");
    }
    process.stdout.write("\n");
    return 0;
  }

  let flows = all;
  if (opts.names.length) {
    flows = [];
    for (const wanted of opts.names) {
      const found = all.filter((f) => f.name === wanted);
      if (!found.length) {
        const near = all.filter((f) => f.name.includes(wanted)).map((f) => f.name);
        process.stderr.write(
          'no flow called "' + wanted + '"' + (near.length ? " - did you mean " + near.join(", ") + "?" : "") + "\n" +
            "Run with --list to see them all.\n",
        );
        return 3;
      }
      flows.push(...found);
    }
  }

  const base = resolveBaseUrl(opts.base);

  process.stdout.write("\n" + COLOUR.bold + "Speedat browser flows" + COLOUR.off + "\n");
  process.stdout.write("  site      : " + base + "\n");
  process.stdout.write("  flows     : " + flows.length + " of " + all.length + "\n");
  process.stdout.write("  shots     : " + (opts.noShots ? "off" : SHOTS_DIR) + "\n");

  /* ---- is it even up? ------------------------------------------------ */

  let probe;
  try {
    probe = await preflight(base);
  } catch (e) {
    if (e && e.siteDown) {
      process.stderr.write("\n" + COLOUR.fail + e.message + COLOUR.off + "\n\n");
      return 2;
    }
    process.stderr.write("\npreflight failed: " + (e && e.stack ? e.stack : e) + "\n");
    return 3;
  }

  const served = Object.keys(probe.status).filter((p) => probe.status[p] === 200);
  const missing = Object.keys(probe.status).filter((p) => probe.status[p] === 404);
  process.stdout.write("  answering : " + (served.join(" ") || "nothing") + "\n");
  if (missing.length) process.stdout.write("  404       : " + COLOUR.skip + missing.join(" ") + COLOUR.off + "\n");
  const markers = Object.keys(probe.has).filter((k) => probe.has[k]);
  process.stdout.write("  shipped   : " + (markers.join(", ") || COLOUR.skip + "no calculator, no hold panel, no SEO partial" + COLOUR.off) + "\n");

  /* ---- go ------------------------------------------------------------ */

  let pw;
  try {
    pw = resolvePlaywright();
  } catch (e) {
    process.stderr.write("\n" + e.message + "\n\n");
    return 3;
  }

  let browser;
  try {
    browser = await pw.chromium.launch({
      channel: "chrome",
      headless: !opts.headed,
      slowMo: opts.slowMo,
    });
  } catch (e) {
    process.stderr.write(
      "\ncould not launch Chrome: " + e.message + "\n\nThe harness uses the installed Chrome (channel: \"chrome\"), not a downloaded browser.\n",
    );
    return 3;
  }

  let report;
  try {
    report = await runFlows({ flows, base, probe, browser, options: opts });
  } finally {
    await browser.close().catch(() => {});
  }

  /* ---- the summary --------------------------------------------------- */

  const sum = summarise(report);
  process.stdout.write("\n" + "-".repeat(72) + "\n");
  process.stdout.write(
    COLOUR.bold +
      "  " +
      sum.pass +
      " passed" +
      COLOUR.off +
      "   " +
      (sum.fail ? COLOUR.fail : COLOUR.dim) +
      sum.fail +
      " failed" +
      COLOUR.off +
      "   " +
      (sum.notReady ? COLOUR.skip : COLOUR.dim) +
      sum.notReady +
      " not ready" +
      COLOUR.off +
      "   of " +
      sum.total +
      " checks in " +
      flows.length +
      " flows\n",
  );

  const blocked = report.filter((f) => f.results.every((r) => r.verdict === "NOT READY"));
  if (blocked.length) {
    process.stdout.write(
      "\n  " + COLOUR.skip + blocked.length + " flow(s) could not run at all:" + COLOUR.off + " " + blocked.map((f) => f.name).join(", ") + "\n",
    );
  }

  const failedFlows = report.filter((f) => f.results.some((r) => r.verdict === "FAIL"));
  if (failedFlows.length) {
    process.stdout.write("\n  " + COLOUR.fail + "failures:" + COLOUR.off + "\n");
    for (const f of failedFlows) {
      for (const r of f.results.filter((x) => x.verdict === "FAIL")) {
        process.stdout.write("    " + f.name + " › " + r.name + "\n");
      }
    }
  }

  if (opts.json) {
    const out = { base, at: new Date().toISOString(), probe: { status: probe.status, has: probe.has }, summary: sum, flows: report };
    fs.mkdirSync(path.dirname(path.resolve(opts.json)), { recursive: true });
    fs.writeFileSync(path.resolve(opts.json), JSON.stringify(out, null, 2));
    process.stdout.write("\n  report    : " + path.resolve(opts.json) + "\n");
  }

  process.stdout.write("\n");

  if (sum.fail > 0) return 1;
  if (opts.strict && sum.notReady > 0) {
    process.stdout.write("  --strict: " + sum.notReady + " check(s) are NOT READY, which is a failure under --strict.\n\n");
    return 1;
  }
  return 0;
}

main()
  .then((code) => process.exit(code))
  .catch((e) => {
    process.stderr.write("\nthe harness itself crashed:\n" + (e && e.stack ? e.stack : e) + "\n");
    process.exit(3);
  });
