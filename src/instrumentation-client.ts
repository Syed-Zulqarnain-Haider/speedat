/**
 * Browser error tracking, on only when NEXT_PUBLIC_SENTRY_DSN is set.
 *
 * Next evaluates this file synchronously in the client entry, before React
 * hydrates anything, so whatever is imported here sits between the visitor
 * and the first tap. A static `import * as Sentry` put the whole browser
 * SDK and its dependencies (≈230 KB raw, ≈73 KB gzip: 30 % of the JS
 * before hydration, measured on a build) in that path on every page, DSN
 * or not. The SDK now loads in its own chunk
 * once the page is idle — and not at all without a DSN — so the calculator
 * hydrates first. The price: an error thrown before the SDK arrives (the
 * first few hundred milliseconds) is not reported; it still reaches the
 * console. Router transitions are forwarded once the SDK is up.
 */
type Sdk = typeof import("@sentry/nextjs");

let sdk: Sdk | undefined;
const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN;

if (dsn) {
  const load = () => {
    void import("@sentry/nextjs").then((m) => {
      m.init({
        dsn,
        environment: process.env.NEXT_PUBLIC_VERCEL_ENV ?? process.env.NODE_ENV,
        tracesSampleRate: 0,
        sendDefaultPii: false,
      });
      sdk = m;
    });
  };
  // After hydration and the first paint; the timeout keeps a busy page from postponing it for long.
  if (typeof window.requestIdleCallback === "function") window.requestIdleCallback(load, { timeout: 2000 });
  else setTimeout(load, 1);
}

export const onRouterTransitionStart: Sdk["captureRouterTransitionStart"] = (href, navigationType) => {
  sdk?.captureRouterTransitionStart(href, navigationType);
};
