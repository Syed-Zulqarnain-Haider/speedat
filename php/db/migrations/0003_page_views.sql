-- Speedat - landings: one counter row per day, page, kind of screen and
-- referring site. Written and read by app/Domain/Analytics/PageViews.php.
--
-- WHY THIS FILE EXISTS AFTER THE TABLE DID
--   page_views shipped on 2026-09-30 and the live database's table was created
--   by hand. This migration is what every other database (a fresh install, the
--   local dev and test databases) was missing. It is CREATE TABLE IF NOT
--   EXISTS, so on the live database it is a no-op and the hand-made table, and
--   its rows, are left exactly as they are.
--
-- HOW IT IS APPLIED
--   php bin/migrate.php, or import it in hPanel -> phpMyAdmin after 0001 and
--   0002. Importing it twice is harmless.
--
-- THE SHAPE
--   * One row per (day, path, device, referrer_host); a page view is
--     INSERT ... ON DUPLICATE KEY UPDATE views = views + 1, so the UNIQUE KEY
--     below is what makes the counter count rather than add a row per visit.
--   * Widths match app/Core/Caps.php (path 190, device 7, referrer_host 190);
--     PageViews::record() fits each value to them before writing.
--   * referrer_host is '' (not NULL) for a direct visit or an unusable
--     Referer: a NULL in a unique key never collides, so NULL would quietly
--     turn every direct visit into a row of its own.
--   * The key is 3 + 4*190 + 4*7 + 4*190 = 1551 bytes in utf8mb4, inside
--     InnoDB's 3072-byte limit for an index.
--   * `day` is the owner's day (Asia/Karachi), as PageViews writes it, not UTC.
--
-- NO IP ADDRESS, NOT EVEN HASHED, and no visitor id: a count of arrivals is
-- all the owner asked for, and a table that cannot identify anyone cannot leak
-- anyone.

CREATE TABLE IF NOT EXISTS page_views (
    day           DATE         NOT NULL,
    path          VARCHAR(190) NOT NULL,
    device        VARCHAR(7)   NOT NULL,
    referrer_host VARCHAR(190) NOT NULL DEFAULT '',
    views         INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY page_views_day_path_device_referrer_uq (day, path, device, referrer_host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
