/**
 * Runs before every test file. Tests may only ever reach the scratch
 * database: DATABASE_URL is replaced by TEST_DATABASE_URL, or emptied when
 * there is none, so a test that opens the database client too early (a static
 * import of a server module) can never truncate the development database.
 */
process.env.DATABASE_URL = process.env.TEST_DATABASE_URL ?? "";
