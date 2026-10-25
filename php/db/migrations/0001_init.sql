-- Speedat - initial schema for MySQL 8 / MariaDB 11.
--
-- A faithful port of the Postgres schema in /drizzle (0000_init,
-- 0001_leads_shipments_limits_totp, 0002_totp_last_step). The shape of the
-- data is unchanged; only the things MySQL spells differently have moved.
--
-- HOW IT IS APPLIED
--   Locally:    php bin/migrate.php
--   Hostinger:  php bin/migrate.php over SSH if the plan has it, or import this
--               file in hPanel -> phpMyAdmin. Every statement is IF NOT EXISTS,
--               so importing it twice is harmless.
--
-- CONVENTIONS
--   InnoDB, utf8mb4 / utf8mb4_unicode_ci everywhere. utf8mb4_unicode_ci rather
--   than MySQL 8's newer utf8mb4_0900_ai_ci because MariaDB does not have the
--   latter and this file has to import on both.
--
--   Hex columns (session ids, IP hashes, checksums) are CHARACTER SET ascii:
--   a quarter of the bytes, an exact-match collation, and there is no such
--   thing as a non-ASCII hex digit.
--
--   Every timestamp is DATETIME holding UTC. The application connects with
--   time_zone = '+00:00' (see app/Core/Db.php), so CURRENT_TIMESTAMP is UTC
--   too. TIMESTAMP columns were avoided deliberately: they are converted on
--   the way in and out according to the session zone, which is precisely the
--   ambiguity this project cannot afford - PHP runs in Asia/Karachi and the
--   database runs in UTC.
--
-- WHERE MYSQL DIFFERS FROM POSTGRES (each is noted again at the table):
--   * jsonb            -> JSON. Native in MySQL 8; in MariaDB it is LONGTEXT
--                         with a json_valid() CHECK. Both accept a JSON string,
--                         which is all the application writes.
--   * bigserial        -> BIGINT UNSIGNED AUTO_INCREMENT.
--   * text + index     -> VARCHAR(n). MySQL cannot index a TEXT column without
--                         a prefix length, so anything indexed is a VARCHAR.
--   * partial indexes  -> not supported. None were used; where Postgres would
--                         have wanted one, the note says so.
--   * RETURNING        -> not supported. The rate limiter uses MySQL's
--                         LAST_INSERT_ID(expr) trick instead; see RateLimit.
--   * reserved words   -> `key` and `value` (app_settings, rate_limits) are
--                         reserved in MySQL 8, so they are k / v. `by`
--                         (shipment_events) is kept and must always be written
--                         in backticks.

-- ---------------------------------------------------------------------------
-- The published site document
-- ---------------------------------------------------------------------------

-- Every publish, INSERT-only; the site serves the highest `version`.
-- Postgres had a separate bigserial id with a unique index on version; here the
-- version IS the primary key, which is what it always meant.
CREATE TABLE IF NOT EXISTS versions (
    version       INT UNSIGNED NOT NULL,
    published_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_by  VARCHAR(190) NOT NULL,
    -- "admin" | "restore:<n>" | "seed". Rows migrated from the old site may
    -- still say "import:<file>" or "email:<from>"; nothing writes those any
    -- more (the Excel rate-sheet feature went on 2026-09-27) and the column is
    -- free text precisely so history stays readable instead of being rewritten.
    source        VARCHAR(255) NOT NULL,
    summary       TEXT         NOT NULL,
    change_count  INT          NOT NULL DEFAULT 0,
    -- The whole site document. The calculator needs the entire card at once and
    -- the admin edits it as a whole, so normalising rates into rows would only
    -- add joins; history, diffs and restores all work on snapshots.
    data          JSON         NOT NULL,
    PRIMARY KEY (version),
    KEY versions_published_idx (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The single working copy the admin edits. There is exactly one row, id = 1.
CREATE TABLE IF NOT EXISTS draft (
    id           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    -- Version the draft was branched from; publishing when a newer version
    -- exists is a conflict the admin has to resolve.
    base_version INT UNSIGNED     NOT NULL,
    data         JSON             NOT NULL,
    updated_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by   VARCHAR(190)     NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT draft_single_row CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operational settings that are NOT part of the published document: the price
-- hold (the switch that takes every price off the site without publishing
-- anything), and the once-a-day markers the cron keeps so an alert or a log
-- line happens once however often the job runs.
-- `key` and `value` are reserved-ish in MySQL, hence k / v.
CREATE TABLE IF NOT EXISTS app_settings (
    k          VARCHAR(64)  NOT NULL,
    v          JSON         NOT NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(190) NOT NULL DEFAULT '',
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Who may open the admin
-- ---------------------------------------------------------------------------

-- Identity comes from Firebase (or a password, if the owner enables it);
-- AUTHORISATION comes from this table. A valid Google account that is not
-- listed here gets nothing.
--
-- VARCHAR(190), not 255: an index on a utf8mb4 column costs four bytes per
-- character, and 190 is the length that fits the older 767-byte index limit
-- some shared hosts still run with.
CREATE TABLE IF NOT EXISTS admins (
    email           VARCHAR(190)            NOT NULL,
    name            VARCHAR(190)            NOT NULL DEFAULT '',
    -- "owner" publishes and manages admins; "editor" edits the draft. ENUM
    -- rather than a free-text column: an unknown role is refused by the
    -- database, not discovered later by a permission check.
    role            ENUM('owner','editor')  NOT NULL DEFAULT 'editor',
    added_by        VARCHAR(190)            NOT NULL DEFAULT '',
    -- Named created_at here; the Postgres column was added_at.
    created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- password_hash() output: bcrypt is 60 characters, argon2id about 100.
    -- NULL until (and unless) the owner turns on password sign-in.
    password_hash   VARCHAR(255)                     DEFAULT NULL,
    -- The TOTP secret, AES-256-GCM encrypted with the application key and
    -- base64-encoded. Base64 in a VARCHAR rather than raw bytes in a VARBINARY
    -- so that nothing in the stack has to be told this column is not text.
    totp_secret_enc VARCHAR(255)                     DEFAULT NULL,
    totp_enabled    TINYINT(1)              NOT NULL DEFAULT 0,
    -- Last accepted time-step, so a code cannot be replayed inside its window.
    totp_last_step  BIGINT                           DEFAULT NULL,
    PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Signed-in admins. New in the PHP port: the Next.js app kept the session in a
-- Firebase cookie, and PHP's own file-based sessions are not something to rely
-- on on shared hosting.
--
-- `id` IS NOT THE COOKIE. The browser holds 32 random bytes; this column holds
-- their SHA-256. A dump of this table therefore cannot be replayed as a login,
-- for the same reason password hashes exist.
CREATE TABLE IF NOT EXISTS sessions (
    id         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email      VARCHAR(190)                                   NOT NULL,
    created_at DATETIME                                       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen  DATETIME                                       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hash    CHAR(32) CHARACTER SET ascii COLLATE ascii_bin          DEFAULT NULL,
    user_agent VARCHAR(300)                                   NOT NULL DEFAULT '',
    expires_at DATETIME                                       NOT NULL,
    PRIMARY KEY (id),
    KEY sessions_email_idx (email),
    KEY sessions_expires_idx (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Customers
-- ---------------------------------------------------------------------------

-- Someone who wants to ship: a booked quote or a contact-form message.
-- Worked from the admin inbox.
--
-- The unique index on quote_id allows many NULLs on both engines - one lead per
-- quote, and any number of leads that came from no quote at all.
CREATE TABLE IF NOT EXISTS leads (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- "quote" | "message"
    kind       VARCHAR(16)     NOT NULL,
    quote_id   VARCHAR(32)              DEFAULT NULL,
    name       VARCHAR(190)    NOT NULL DEFAULT '',
    phone      VARCHAR(40)     NOT NULL DEFAULT '',
    email      VARCHAR(190)    NOT NULL DEFAULT '',
    -- TEXT has no DEFAULT on every supported engine, so the application always
    -- supplies '' rather than relying on one.
    message    TEXT            NOT NULL,
    dest_id    VARCHAR(64)              DEFAULT NULL,
    weight_g   INT UNSIGNED             DEFAULT NULL,
    -- "new" | "contacted" | "booked" | "lost"
    status     VARCHAR(16)     NOT NULL DEFAULT 'new',
    notes      TEXT            NOT NULL,
    updated_by VARCHAR(190)    NOT NULL DEFAULT '',
    -- Hashed, never the address itself. See app/Core/Ip.php.
    ip_hash    CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    PRIMARY KEY (id),
    KEY leads_status_idx (status, created_at),
    UNIQUE KEY leads_quote_idx (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quotes customers generated on the site. Written fire-and-forget: a failure
-- here must never stop a price being shown.
CREATE TABLE IF NOT EXISTS quotes (
    id         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Which published version priced it. Without this, a quote cannot be
    -- explained after the next publish.
    version    INT UNSIGNED    NOT NULL,
    dest_id    VARCHAR(64)     NOT NULL,
    service_id VARCHAR(64)     NOT NULL,
    -- "pkg" | "doc"
    type       VARCHAR(8)      NOT NULL,
    billable_g INT UNSIGNED    NOT NULL,
    -- Whole rupees, as the engine computes them. Never a float: money that has
    -- been through a float is money that disagrees with the printed sheet.
    total      INT             NOT NULL,
    -- Pieces, add-ons, ETA, and whether the WhatsApp button was pressed.
    detail     JSON            NOT NULL,
    booked     TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY quotes_created_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A booked shipment and its progress. Ids are random, not sequential, so /track
-- cannot be walked from one customer's shipment to the next.
CREATE TABLE IF NOT EXISTS shipments (
    id             VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lead_id        BIGINT UNSIGNED          DEFAULT NULL,
    quote_id       VARCHAR(32)              DEFAULT NULL,
    tracking_no    VARCHAR(64)              DEFAULT NULL,
    carrier        VARCHAR(80)     NOT NULL DEFAULT '',
    customer_name  VARCHAR(190)    NOT NULL DEFAULT '',
    customer_phone VARCHAR(40)     NOT NULL DEFAULT '',
    receiver_name  VARCHAR(190)    NOT NULL DEFAULT '',
    dest_id        VARCHAR(64)     NOT NULL,
    service_id     VARCHAR(64)     NOT NULL,
    -- booked | picked_up | in_transit | customs | out_for_delivery | delivered | exception
    status         VARCHAR(24)     NOT NULL DEFAULT 'booked',
    notes          TEXT            NOT NULL,
    created_by     VARCHAR(190)    NOT NULL,
    PRIMARY KEY (id),
    KEY shipments_status_idx (status, updated_at),
    KEY shipments_tracking_idx (tracking_no),
    KEY shipments_quote_idx (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The progress of a shipment, one row per update.
--
-- `by` IS A RESERVED WORD IN MySQL. The column keeps its Postgres name so that
-- moving the data across is a straight copy, which means every statement that
-- touches it must write it in backticks: INSERT INTO shipment_events (..., `by`).
-- No foreign key to shipments, matching the Postgres schema: the events are
-- written by the same code that writes the shipment, and a constraint here
-- would only dictate the order of a future data migration.
CREATE TABLE IF NOT EXISTS shipment_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shipment_id VARCHAR(32)     NOT NULL,
    status      VARCHAR(24)     NOT NULL,
    note        TEXT            NOT NULL,
    at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `by`        VARCHAR(190)    NOT NULL,
    PRIMARY KEY (id),
    KEY shipment_events_shipment_idx (shipment_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- THERE IS NO `imports` TABLE. The Postgres schema had one - a rate sheet that
-- had been uploaded or emailed, what the parser made of it, and what an admin
-- decided about it. The owner removed the Excel rate-sheet feature from the
-- product on 2026-09-27: there is no upload, no mapper, no inbound mailbox and
-- no review queue, so the table is not ported. It is not commented out either.
-- A schema that installs an empty table for a feature nobody can reach is how
-- the feature comes back by accident, one "while we are in here" at a time.
--
-- The rates CSV EXPORT is unaffected. It reads the published document and sends
-- nothing, so it never needed a table.

-- ---------------------------------------------------------------------------
-- Operational tables
-- ---------------------------------------------------------------------------

-- APPEND-ONLY. Everything an admin does, in the order it happened.
--
-- The application enforces this: app/Core/Audit.php contains exactly one
-- statement and it is an INSERT, and no code path anywhere updates or deletes
-- a row here. On Postgres the api role was additionally granted INSERT and
-- SELECT only; Hostinger gives one database user with full rights, so that
-- grant cannot be reproduced.
--
-- If the host allows triggers (most do), run these after importing this file
-- and the database will refuse to rewrite history even if the application is
-- compromised:
--
--   DELIMITER //
--   CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
--     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';//
--   CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
--     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';//
--   DELIMITER ;
--
-- They are not in this file because a host without the TRIGGER privilege would
-- fail the whole import, and a schema that will not install is worse than a
-- schema whose last lock is enforced in PHP.
--
-- `ip` became `ip_hash`: the raw address was never needed to answer the only
-- question anybody asks of it, which is whether two entries came from the same
-- place.
CREATE TABLE IF NOT EXISTS audit_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor      VARCHAR(190)    NOT NULL,
    action     VARCHAR(64)     NOT NULL,
    detail     JSON            NOT NULL,
    ip_hash    CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    user_agent VARCHAR(300)             DEFAULT NULL,
    PRIMARY KEY (id),
    KEY audit_log_at_idx (at),
    KEY audit_log_actor_idx (actor, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window counters for per-IP limits. In the database rather than in
-- memory because a shared host gives no Redis and no guarantee that two
-- requests even land on the same process.
--
-- `key` is reserved in MySQL, hence k. The key is "<bucket>:<hashed ip>", which
-- is ASCII by construction.
-- Old windows are swept opportunistically by the limiter and by bin/cron.php.
CREATE TABLE IF NOT EXISTS rate_limits (
    k            VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start DATETIME     NOT NULL,
    count        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (k, window_start),
    KEY rate_limits_window_idx (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What bin/migrate.php has already applied. The checksum catches a migration
-- that was edited after it ran, which is the failure mode that otherwise turns
-- up as "it works on my machine".
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(190) NOT NULL,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checksum   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
