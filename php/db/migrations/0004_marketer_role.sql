-- Speedat - a third admin role: "marketer".
--
-- WHAT IT IS FOR
--   A marketer signs in like anybody else and may change the words and the
--   search titles on the public pages, and nothing else: no prices, inbox,
--   shipments, analytics or settings. See app/Http/Admin/Guard.php.
--
-- HOW IT IS APPLIED
--   php bin/migrate.php, or import it in hPanel -> phpMyAdmin after 0003.
--   Importing it twice is harmless: the column ends up the same either way.
--   The site works without it; only adding a marketer needs it.
--
-- IF YOU EVER GO BACK TO AN OLDER RELEASE, delete the marketers FIRST:
--     DELETE FROM admins WHERE role = 'marketer';
--   Releases before this one read any role that is not "owner" as "editor",
--   which may edit prices and read the inbox. They also drop the hand-written
--   Google titles, descriptions and headings the next time anything is saved.

ALTER TABLE admins
    MODIFY role ENUM('owner','editor','marketer') NOT NULL DEFAULT 'editor';
