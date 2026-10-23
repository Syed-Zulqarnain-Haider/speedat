import { defineConfig } from "vitest/config";
import path from "node:path";

/**
 * Suites that migrate and truncate the shared TEST_DATABASE_URL. They run one
 * file at a time: in parallel, two of them migrating or truncating the same
 * database at once made the suite flaky.
 */
const DB_SUITES = ["src/lib/auth/totp.test.ts", "src/lib/site/hold.test.ts", "src/lib/site/repo.test.ts"];

const shared = {
  resolve: { alias: { "@": path.resolve(import.meta.dirname, "src"), "server-only": path.resolve(import.meta.dirname, "tests/stubs/server-only.ts") } },
};

export default defineConfig({
  ...shared,
  test: {
    coverage: { provider: "v8", include: ["src/lib/**"] },
    projects: [
      { ...shared, test: { name: "unit", include: ["src/**/*.test.ts"], exclude: DB_SUITES, environment: "node", setupFiles: ["tests/setup.ts"] } },
      { ...shared, test: { name: "db", include: DB_SUITES, environment: "node", setupFiles: ["tests/setup.ts"], fileParallelism: false } },
    ],
  },
});
