-- Speedat - a CSRF secret that belongs to the SESSION, not to the cookie.
--
-- WHY THIS EXISTS
--   Form tokens are signed with a key derived from the application key AND a
--   per-session secret (see app/Core/Csrf.php). That secret used to be the
--   session cookie's token, which broke in two ways:
--
--     1. ORDER. The cookie token is only known to app/Core/Session.php after
--        the sessions row has been read, and the row is read lazily. A token
--        issued before anything else touched the session was therefore signed
--        with the anonymous-visitor secret, and verified against the signed-in
--        one (or the other way round) - so an admin form could fail with
--        "this form expired" purely because of the order of two method calls.
--     2. ROTATION. rotate() issues a new cookie token after the second factor.
--        Every form rendered in that same request, and every tab already open,
--        then carried a token that could no longer be verified.
--
--   A column on the row is known the moment the row is read, and rotate()
--   carries it across, so the secret is a property of the session itself.
--   Signing out still kills it: the row goes.
--
--   CHAR(64) ascii: 32 random bytes as hex, the same shape as `id`.
--
-- IDEMPOTENT ON BOTH ENGINES
--   MariaDB has ALTER TABLE ... ADD COLUMN IF NOT EXISTS; MySQL 8 does not and
--   would not even parse it. information_schema is in both, so the ALTER is
--   prepared only when the column is missing. The no-op branch is `DO 0` and
--   not `SELECT 1` on purpose: a SELECT would leave an unread result set on
--   the connection that the next statement then trips over.
--
-- IF YOU IMPORT BY HAND (phpMyAdmin), import 0001 first, then this file.
-- The application survives a host that never ran this migration - it falls
-- back to a secret derived from the row - but sessions are then reset by the
-- second factor, so do run it.

SET @speedat_has_csrf_secret := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'sessions'
       AND COLUMN_NAME  = 'csrf_secret'
);

SET @speedat_add_csrf_secret := IF(
    @speedat_has_csrf_secret > 0,
    'DO 0',
    'ALTER TABLE sessions ADD COLUMN csrf_secret CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER user_agent'
);

PREPARE speedat_add_csrf_secret FROM @speedat_add_csrf_secret;
EXECUTE speedat_add_csrf_secret;
DEALLOCATE PREPARE speedat_add_csrf_secret;
