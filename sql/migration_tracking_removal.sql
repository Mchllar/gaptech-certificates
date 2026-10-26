-- ---------------------------------------------------------------------------
-- Recording that a tracker has been taken off a vehicle.
--
-- Run it in psql:
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_tracking_removal.sql'
--
-- Safe to run twice.
--
-- ---------------------------------------------------------------------------
-- Why a new status rather than voiding
-- ---------------------------------------------------------------------------
-- GAPTECH recovers its own units - most often when a vehicle has been
-- repossessed - and refits them to another customer's vehicle, who buys a fresh
-- subscription. The second half of that already works: a new certificate with the
-- same IMEI, because tracking_imeis.imei was never unique.
--
-- The first half had nowhere to go. The only tool was Void, and void means "this
-- certificate should never have gone out". That is not what happened: the
-- certificate was perfectly good for the months it covered. It matters beyond
-- tidiness, because an officer checking the OLD vehicle's certificate deserves to
-- be told the unit was removed on a date - not that the paperwork was an error.
--
-- So 'removed' joins issued | superseded | voided:
--
--     issued       in force
--     superseded   replaced by a renewal of the same vehicle
--     voided       should never have been issued
--     removed      was valid; the unit has since been taken off the vehicle
--
-- The vehicle stops counting as covered from the removal date, because there is
-- no longer a box in it. The remaining subscription time dies with the removal -
-- it belonged to the customer who lost the vehicle, and the next customer buys
-- their own.
-- ---------------------------------------------------------------------------

BEGIN;

ALTER TABLE tracking_certificates
    ADD COLUMN IF NOT EXISTS removed_on     date,
    ADD COLUMN IF NOT EXISTS removed_reason text,
    ADD COLUMN IF NOT EXISTS removed_by     integer REFERENCES users (id);

-- The status vocabulary. A CHECK cannot be extended in place, so it is dropped and
-- rebuilt.
--
-- BOTH names are dropped on purpose. migration_tracking.sql named it
-- tracking_status_check; a table created without that migration would have
-- PostgreSQL's default name instead. Dropping only one of them leaves the other
-- quietly enforcing the old three values, and the first removal fails with a
-- constraint violation on a database that looked like it had been migrated.
ALTER TABLE tracking_certificates DROP CONSTRAINT IF EXISTS tracking_status_check;
ALTER TABLE tracking_certificates DROP CONSTRAINT IF EXISTS tracking_certificates_status_check;

ALTER TABLE tracking_certificates
    ADD CONSTRAINT tracking_status_check
    CHECK (status IN ('issued', 'superseded', 'voided', 'removed'));

-- A removed certificate must say when and why. Anything else is a status nobody
-- can act on: "the unit is gone, we think, at some point, for some reason".
ALTER TABLE tracking_certificates DROP CONSTRAINT IF EXISTS tracking_removed_check;
ALTER TABLE tracking_certificates DROP CONSTRAINT IF EXISTS tracking_certificates_removed_check;

ALTER TABLE tracking_certificates
    ADD CONSTRAINT tracking_removed_check
    CHECK (
        status <> 'removed'
        OR (removed_on IS NOT NULL AND coalesce(trim(removed_reason), '') <> '')
    );

-- "Where has this unit been, and is it free to fit?" - the two questions anybody
-- holding a recovered tracker asks. Answered from tracking_imeis, which already
-- has an index on imei; this one covers the certificates side of the join.
CREATE INDEX IF NOT EXISTS tracking_certificates_live_idx
    ON tracking_certificates (status, approval_status);

COMMIT;

-- ---------------------------------------------------------------------------
-- Check it took:
--
--   SELECT conname, pg_get_constraintdef(oid)
--     FROM pg_constraint
--    WHERE conrelid = 'tracking_certificates'::regclass
--      AND conname LIKE '%status%' OR conname LIKE '%removed%';
--
-- Nothing existing changes: every current row keeps status 'issued',
-- 'superseded' or 'voided', and the three new columns start empty.
-- ---------------------------------------------------------------------------
