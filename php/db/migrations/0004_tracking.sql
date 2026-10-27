-- Speedat - parcel tracking: what the carrier says about a shipment, kept so
-- /track can answer from the database. Written by app/Domain/Tracking/*.
--
-- HOW IT IS APPLIED
--   php bin/migrate.php, or import it in hPanel -> phpMyAdmin after 0001-0003.
--   Both tables are CREATE TABLE IF NOT EXISTS, so importing it twice is
--   harmless. The shipments table is NOT altered: tracking state lives beside
--   it, one row per shipment, so a release that is rolled back leaves the
--   shipments table exactly as the old code expects it.
--
-- WHO CALLS THE CARRIER. Only the hourly cron (Tracking\Refresh). A visitor to
-- /track never causes an outbound request; the page reads these two tables and
-- nothing else, which is the cache: one call an hour per open shipment at most,
-- however many times a customer presses reload.
--
-- NO NAMES, NO PHONES, NO ADDRESSES. A carrier's own event text says things like
-- "Delivered - signed for by AHMED" and its address block can carry a street.
-- None of that is stored. A checkpoint is a time, a city, a country code and
-- one of our own stage words; the fingerprint is a hash, so the carrier's text
-- that went into it cannot be read back out of this table.

-- One row per shipment that has been handed to the tracking service.
--
--   track_key     what was registered: provider + carrier code + number. When
--                 the office corrects the carrier or the number the key no
--                 longer matches and the cron registers the new one.
--   carrier_code  the provider's numeric carrier id (0 = let it detect).
--   state         registered | active | done | error | manual
--   error         the provider's reason, short, for the admin desk only.
CREATE TABLE IF NOT EXISTS shipment_tracking (
    shipment_id   VARCHAR(32)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    track_key     VARCHAR(120) NOT NULL DEFAULT '',
    carrier_code  INT UNSIGNED NOT NULL DEFAULT 0,
    state         VARCHAR(16)  NOT NULL DEFAULT 'registered',
    error         VARCHAR(190) NOT NULL DEFAULT '',
    registered_at DATETIME              DEFAULT NULL,
    checked_at    DATETIME              DEFAULT NULL,
    PRIMARY KEY (shipment_id),
    KEY shipment_tracking_due_idx (state, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The carrier's timeline, one row per scan.
--
--   at           UTC, as the provider reports it.
--   city         letters, spaces and a few separators only (Checkpoint::place).
--   country      ISO 3166-1 alpha-2, or ''.
--   stage        one of Tracking\Stage::ALL.
--   fingerprint  sha1 of the provider's own time, text and place, so the same
--                scan reported again by the next hourly call is one row, not two.
CREATE TABLE IF NOT EXISTS tracking_checkpoints (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shipment_id VARCHAR(32)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    at          DATETIME     NOT NULL,
    city        VARCHAR(80)  NOT NULL DEFAULT '',
    country     VARCHAR(2)   NOT NULL DEFAULT '',
    stage       VARCHAR(24)  NOT NULL,
    fingerprint VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY tracking_checkpoints_fp_uq (shipment_id, fingerprint),
    KEY tracking_checkpoints_shipment_idx (shipment_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
