-- ---------------------------------------------------------------------------
-- A speed governor can come off a lorry, same as a tracker can.
--
-- Run it in psql:
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_governor_removal.sql'
--
-- Safe to run twice.
-- ---------------------------------------------------------------------------
-- Why the governor side needs this at all
-- ---------------------------------------------------------------------------
-- The tracking module already has it. The governor module never did, and the
-- consequence is quiet: certificate_new.php has NO check on the serial number,
-- so typing a serial that is already fitted to another lorry does not warn,
-- does not block, and does not fail. create_certificate() finds the device row,
-- moves it to the new vehicle, and the earlier lorry goes on holding a valid
-- certificate for a governor that is no longer in it.
--
-- Nothing on any screen looks wrong afterwards. Two lorries are certified as
-- having one sealed box, and the only way to notice is to go and look at both.
--
-- 'removed' is what the tracking certificates already use, and it means exactly
-- what it says: the unit came off, on this date, for this reason. It is not
-- 'voided' - the fitting was real and the certificate was correctly issued - and
-- it is not 'superseded', which is this vehicle getting a newer certificate.
-- ---------------------------------------------------------------------------

BEGIN;

ALTER TABLE certificates DROP CONSTRAINT IF EXISTS certificates_status_check;

ALTER TABLE certificates ADD CONSTRAINT certificates_status_check
    CHECK (status IN ('issued', 'voided', 'superseded', 'removed'));

ALTER TABLE certificates
    ADD COLUMN IF NOT EXISTS removed_on     date,
    ADD COLUMN IF NOT EXISTS removed_reason text,
    ADD COLUMN IF NOT EXISTS removed_by     integer REFERENCES users (id);

-- A removal with no date and no reason is the thing this is meant to prevent, so
-- the database refuses it rather than trusting every future caller to remember.
-- In six months the reason is the only way to tell a governor that was moved
-- from a serial number somebody mistyped.
ALTER TABLE certificates DROP CONSTRAINT IF EXISTS certificates_removed_detail;

ALTER TABLE certificates ADD CONSTRAINT certificates_removed_detail
    CHECK (
        status <> 'removed'
        OR (removed_on IS NOT NULL AND coalesce(trim(removed_reason), '') <> '')
    );

COMMIT;

-- ---------------------------------------------------------------------------
-- The installations table already had half of this
-- ---------------------------------------------------------------------------
-- installations.removed_on has existed all along, and create_certificate() reads
-- it - it looks for an installation "WHERE ... AND removed_on IS NULL". Nothing
-- has ever written to it, so every installation row ever created is still open.
--
-- remove_governor_unit() now closes both: the certificate says the cover ended,
-- and the installation row says the box came off. They are different facts and
-- both are needed - the certificate is what the customer holds, the installation
-- row is what the next certificate for that serial is checked against.
-- ---------------------------------------------------------------------------

-- Anything already double-booked, worth looking at before you rely on the check.
-- Each row is one serial number on more than one lorry's live certificate:
--
--   SELECT d.serial_no,
--          count(*)                        AS live_certificates,
--          string_agg(v.reg_no, ' | ')     AS lorries
--     FROM certificates c
--     JOIN devices  d ON d.id = c.device_id
--     JOIN vehicles v ON v.id = c.vehicle_id
--    WHERE c.status = 'issued'
--      AND c.approval_status IN ('approved', 'pending')
--    GROUP BY d.serial_no
--   HAVING count(*) > 1;