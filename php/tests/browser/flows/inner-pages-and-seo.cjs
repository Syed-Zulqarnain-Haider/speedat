"use strict";

/**
 * The five public pages, the sitemap, the robots file and the redirects.
 *
 * The SEO half reads the bytes the server sent, never the DOM: a canonical tag
 * that JavaScript adds is a canonical tag Google's first pass does not see, and
 * checking it in the browser would hide exactly the bug that matters.
 *
 * The accessibility and typography half does use the browser, because "nothing
 * under 14px" and "no sideways scroll" are facts about rendering.
 *
 * Route list and titles: app/Domain/Seo/Routes.php. When a page is added there
 * it is added here, and the two disagreeing is the point.
 */

const contract = require("../contract.cjs");

const PAGES = [
  { path: "/", title: "Get a price", breadcrumb: false, faq: false },
  { path: "/services", title: "Services", breadcrumb: true, faq: false },
  { path: "/about", title: "About us", breadcrumb: true, faq: false },
  { path: "/contact", title: "Contact", breadcrumb: true, faq: false },
  { path: "/faq", title: "Questions", breadcrumb: true, faq: true },
];

module.exports = {
  name: "inner-pages-and-seo",
  order: 2,
  title: "five pages, their markup, the sitemap and the redirects",
  viewport: "desktop",

  async run(t) {
    /* ---- per page --------------------------------------------------- */

    for (const page of PAGES) {
      await t.check("SEO: " + page.path, async () =>
        contract.seoEssentials(t, page.path, { expectFaq: page.faq, expectBreadcrumb: page.breadcrumb }),
      );
    }

    await t.check("every page has its own title and description", async () => {
      const seen = [];
      for (const page of PAGES) {
        const res = await t.fetch(page.path);
        if (!res.ok || res.status !== 200) continue;
        const title = ((res.body.match(/<title[^>]*>([\s\S]*?)<\/title>/i) || [])[1] || "").trim();
        const desc = ((res.body.match(/<meta[^>]+name=["']description["'][^>]*content=["']([^"']*)["']/i) || [])[1] || "").trim();
        seen.push({ path: page.path, title, desc });
      }
      t.need(seen.length >= 2, "fewer than two pages are being served - nothing to compare");

      const clashes = [];
      for (let i = 0; i < seen.length; i += 1) {
        for (let j = i + 1; j < seen.length; j += 1) {
          if (seen[i].title && seen[i].title === seen[j].title) clashes.push(seen[i].path + " and " + seen[j].path + " share the title " + JSON.stringify(seen[i].title));
          if (seen[i].desc && seen[i].desc === seen[j].desc) clashes.push(seen[i].path + " and " + seen[j].path + " share the description");
        }
      }
      if (clashes.length) throw new Error(["duplicate metadata:", ...clashes.map((c) => "    " + c)].join("\n"));
      return seen.length + " page(s) served, all distinct";
    });

    /* ---- landmarks, headings and links ------------------------------ */

    for (const page of PAGES) {
      await t.check("structure and typography: " + page.path, async () => {
        await t.open(page.path);
        await t.page.waitForTimeout(250);

        const structure = await t.page.evaluate(() => {
          const headings = Array.from(document.querySelectorAll("h1,h2,h3,h4,h5,h6")).map((h) => ({
            level: Number(h.tagName.slice(1)),
            text: (h.textContent || "").replace(/\s+/g, " ").trim().slice(0, 50),
          }));
          const vague = Array.from(document.querySelectorAll("a[href]"))
            .map((a) => (a.textContent || "").replace(/\s+/g, " ").trim())
            .filter((txt) => /^(click here|here|read more|more|link|this)$/i.test(txt));
          const imgs = Array.from(document.querySelectorAll("img")).map((i) => ({
            src: (i.getAttribute("src") || "").slice(-40),
            alt: i.getAttribute("alt"),
            sized: !!(i.getAttribute("width") && i.getAttribute("height")) || !!(i.style.aspectRatio || getComputedStyle(i).aspectRatio !== "auto"),
          }));
          return {
            headings,
            vague,
            imgs,
            landmarks: {
              header: !!document.querySelector("header"),
              nav: !!document.querySelector("nav"),
              main: !!document.querySelector("main"),
              footer: !!document.querySelector("footer"),
            },
            skipLink: !!document.querySelector("a[href^='#']"),
          };
        });

        const problems = [];

        for (const [name, present] of Object.entries(structure.landmarks)) {
          if (!present) problems.push("no <" + name + "> landmark");
        }

        let previous = 0;
        for (const h of structure.headings) {
          if (previous && h.level > previous + 1) {
            problems.push("heading order jumps h" + previous + " → h" + h.level + " at " + JSON.stringify(h.text));
          }
          previous = h.level;
        }

        if (structure.vague.length) problems.push('link text that says nothing: ' + structure.vague.slice(0, 3).map((v) => JSON.stringify(v)).join(", "));

        const noAlt = structure.imgs.filter((i) => i.alt === null);
        if (noAlt.length) problems.push(noAlt.length + " image(s) with no alt attribute (decorative ones need alt=\"\")");
        const unsized = structure.imgs.filter((i) => !i.sized);
        if (unsized.length) problems.push(unsized.length + " image(s) with no reserved space, which shifts the layout as they load");

        if (problems.length) throw new Error([page.path + ":", ...problems.map((p) => "    " + p)].join("\n"));

        const small = await contract.nothingUnderMinFont(t.page);
        await t.shot("page" + page.path.replace(/\//g, "-"));
        return structure.headings.length + " headings in order, landmarks present, " + small;
      });
    }

    /* ---- the phone width -------------------------------------------- */

    await t.check("no page scrolls sideways at 375px", async () => {
      const phone = await t.browser.newContext({
        viewport: { width: 375, height: 812 },
        isMobile: true,
        hasTouch: true,
        ignoreHTTPSErrors: true,
      });
      try {
        const page = await phone.newPage();
        t.watch(page);
        const tested = [];
        for (const spec of PAGES) {
          const res = await page.goto(t.url(spec.path), { waitUntil: "domcontentloaded", timeout: 30000 });
          if (!res || res.status() !== 200) continue;
          await page.waitForTimeout(250);
          const saved = t.page;
          t.page = page;
          try {
            await contract.noHorizontalScroll(page);
          } finally {
            t.page = saved;
          }
          tested.push(spec.path);
        }
        t.need(tested.length, "no page is being served yet");
        return tested.length + " page(s) fit 375px: " + tested.join(" ");
      } finally {
        await phone.close();
      }
    });

    /* ---- sitemap and robots ----------------------------------------- */

    /*
     * "STAYS ON THIS HOST", not "free of dev hosts" (2026-09-29).
     *
     * The old rule was a word list - localhost, 127.0.0.1, :8081 - and it fired
     * on every run against the dev server, where the sitemap printing
     * http://127.0.0.1:8081/ is the sitemap being RIGHT. Five red entries plus
     * the robots line, every run, for correct output. The defect worth
     * catching is a sitemap that names a host this site is not being served
     * from: a base URL baked into the code, or copied from the wrong config.
     * That is one comparison and it works on every host - see
     * contract.foreignHostProblems().
     */
    await t.check("/sitemap.xml is valid, complete and stays on this host", async () => {
      const res = await t.fetch("/sitemap.xml");
      if (!res.ok) throw new Error("no answer: " + res.error);
      t.need(res.status !== 404, "/sitemap.xml answers 404 - Domain\\Seo has not shipped");
      if (res.status !== 200) throw new Error("answered " + res.status);

      const type = String((res.headers || {})["content-type"] || "");
      if (!/xml/i.test(type)) throw new Error("Content-Type is " + JSON.stringify(type) + ", not XML");

      const problems = [];
      if (!/<urlset[^>]+xmlns=["']http:\/\/www\.sitemaps\.org\/schemas\/sitemap\/0\.9["']/.test(res.body)) {
        problems.push("no sitemaps.org urlset namespace");
      }
      const locs = Array.from(res.body.matchAll(/<loc>([^<]+)<\/loc>/gi)).map((m) => m[1].trim());
      if (!locs.length) problems.push("no <loc> entries");

      for (const loc of locs) {
        problems.push(...contract.foreignHostProblems(t, loc, "a sitemap entry"));
        if (!/^https?:\/\//i.test(loc)) problems.push("not an absolute URL: " + loc);
        if (/\/admin|\/api/.test(new URL(loc, t.base).pathname)) problems.push("a disallowed path is listed: " + loc);
      }

      // Routes::all() is five pages. More than that means something grew into
      // the sitemap that nobody decided to put there.
      const paths = locs.map((l) => {
        try {
          return new URL(l).pathname.replace(/\/+$/, "") || "/";
        } catch (e) {
          return l;
        }
      });
      for (const want of ["/", "/services", "/about", "/contact", "/faq"]) {
        if (!paths.includes(want)) problems.push("missing from the sitemap: " + want);
      }
      const extra = paths.filter((p) => !["/", "/services", "/about", "/contact", "/faq"].includes(p));
      if (extra.length) problems.push("unexpected entries: " + extra.join(", "));

      for (const mod of Array.from(res.body.matchAll(/<lastmod>([^<]+)<\/lastmod>/gi)).map((m) => m[1].trim())) {
        if (Number.isNaN(Date.parse(mod))) problems.push("lastmod is not a date: " + mod);
      }

      if (problems.length) throw new Error(["/sitemap.xml:", ...problems.map((p) => "    " + p)].join("\n"));
      return locs.length + " URLs, absolute, all on " + new URL(t.base).host + ", no /admin or /api";
    });

    await t.check("/robots.txt answers and agrees with the sitemap", async () => {
      const res = await t.fetch("/robots.txt");
      if (!res.ok) throw new Error("no answer: " + res.error);
      t.need(res.status !== 404, "/robots.txt answers 404 - Domain\\Seo has not shipped");
      if (res.status !== 200) throw new Error("answered " + res.status);

      const type = String((res.headers || {})["content-type"] || "");
      if (!/text\/plain/i.test(type)) throw new Error("Content-Type is " + JSON.stringify(type) + ", not text/plain");

      const problems = [];
      if (!/^User-agent:\s*\*/im.test(res.body)) problems.push("no User-agent: * group");
      for (const prefix of ["/admin", "/api"]) {
        if (!new RegExp("^Disallow:\\s*" + prefix, "im").test(res.body)) problems.push("does not disallow " + prefix);
      }
      const sitemapLine = (res.body.match(/^Sitemap:\s*(\S+)/im) || [])[1];
      if (!sitemapLine) problems.push("no Sitemap: line");
      else {
        problems.push(...contract.foreignHostProblems(t, sitemapLine, "the Sitemap: line"));
        if (!/^https?:\/\//i.test(sitemapLine)) problems.push("the Sitemap: line is not absolute: " + sitemapLine);
      }
      if (/^Disallow:\s*\/\s*$/im.test(res.body)) problems.push("Disallow: / - the whole site is blocked from indexing");

      if (problems.length) throw new Error(["/robots.txt:", ...problems.map((p) => "    " + p)].join("\n"));
      return "User-agent, /admin and /api disallowed, absolute Sitemap line";
    });

    /* ---- one URL per page ------------------------------------------- */

    await t.check("a trailing slash redirects once, with a 301", async () => {
      const checked = [];
      for (const spec of PAGES.filter((p) => p.path !== "/")) {
        const plain = await t.fetch(spec.path);
        if (!plain.ok || plain.status !== 200) continue;

        const slashed = await t.fetch(spec.path + "/");
        if (!slashed.ok) throw new Error(spec.path + "/ did not answer: " + slashed.error);
        if (slashed.status === 200) {
          throw new Error(
            spec.path + " and " + spec.path + "/ both answer 200 - that is the same page at two URLs, which splits its ranking",
          );
        }
        if (slashed.status !== 301) {
          throw new Error(spec.path + "/ answered " + slashed.status + ", expected a 301");
        }
        const location = String((slashed.headers || {}).location || "");
        const got = location.replace(/^https?:\/\/[^/]+/, "") || location;
        if (got.replace(/\/+$/, "") !== spec.path) {
          throw new Error(spec.path + "/ redirects to " + location + ", expected " + spec.path);
        }
        checked.push(spec.path);
      }
      t.need(checked.length, "no inner page is being served yet");
      return checked.length + " page(s) redirect their slashed form with a 301";
    });

    await t.check("/index.php redirects to /", async () => {
      const res = await t.fetch("/index.php");
      if (!res.ok) throw new Error("no answer: " + res.error);
      if (res.status !== 301) throw new Error("/index.php answered " + res.status + ", expected 301");
      const location = String((res.headers || {}).location || "");
      if (!/^\/$|\/$/.test(location)) throw new Error("/index.php redirects to " + location + ", expected /");
      return "301 → " + location;
    });

    /* ---- and the takeover does not change what was printed ---------- */

    await t.check("JavaScript does not rewrite the server's page", async () => {
      t.need(t.probe.ready("/"), "the home page is not being served");
      return contract.noHydrationStyleMismatch(t, "/", { selectors: ["h1", "title", "header nav"] });
    });

    await t.check("the browser stayed quiet over the whole flow", async () => contract.noConsoleErrors(t));
  },
};
