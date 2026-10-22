-- A tracking certificate can cover more than one unit.
--
-- Run in psql, connected to the right database:
--     \c gaptech_certs gaptech
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_tracking_imeis.sql'
--
-- Some vehicles have two trackers fitted. On the printed certificate they share
-- one box, separated by a slash. In the database they get a row each, so either
-- one can be searched for and neither has to be picked apart from a string.
--
-- Safe to run twice.

BEGIN;

CREATE TABLE IF NOT EXISTS tracking_imeis (
    id          serial PRIMARY KEY,
    cert_id     integer NOT NULL REFERENCES tracking_certificates (id) ON DELETE CASCADE,
    imei        text    NOT NULL,
    position    integer NOT NULL DEFAULT 1,       -- 1 is the first box on the form
    created_at  timestamptz NOT NULL DEFAULT now()
);

-- One row per slot per certificate, so saving twice cannot double them up.
CREATE UNIQUE INDEX IF NOT EXISTS tracking_imeis_slot_idx
    ON tracking_imeis (cert_id, position);

-- "Which certificate is this unit on?" - the question anybody holding an IMEI
-- asks. NOT unique: the same unit legitimately appears on every renewal of the
-- same vehicle, and on the superseded certificates behind it.
CREATE INDEX IF NOT EXISTS tracking_imeis_imei_idx
    ON tracking_imeis (imei);

CREATE INDEX IF NOT EXISTS tracking_imeis_cert_idx
    ON tracking_imeis (cert_id, position);

-- Move what is already there. Every existing certificate has at most one IMEI,
-- so it becomes slot 1.
INSERT INTO tracking_imeis (cert_id, imei, position)
SELECT t.id, trim(t.imei), 1
  FROM tracking_certificates t
 WHERE coalesce(trim(t.imei), '') <> ''
   AND NOT EXISTS (SELECT 1 FROM tracking_imeis x WHERE x.cert_id = t.id)
;

-- tracking_certificates.imei is DELIBERATELY LEFT IN PLACE, holding the printed
-- string: one IMEI, or two joined with ' / '.
--
-- Not as a second source of truth - tracking_imeis is the truth, and the app
-- rewrites this column from it on every save. It stays because the list page,
-- the search box and the certificate view all read one field, and because every
-- certificate already approved has this value frozen in its snapshot. Dropping
-- it would mean rewriting snapshots, and a snapshot is a record of what was
-- printed. It must never be rewritten.

COMMIT;

-- Afterwards:
--
--   -- every certificate a unit has ever appeared on
--   SELECT t.number, t.reg_no, t.status, i.position
--     FROM tracking_imeis i
--     JOIN tracking_certificates t ON t.id = i.cert_id
--    WHERE i.imei = '865784051234567'
--    ORDER BY t.number DESC;
--
--   -- vehicles carrying two units
--   SELECT t.number, t.reg_no, string_agg(i.imei, ' / ' ORDER BY i.position)
--     FROM tracking_certificates t
--     JOIN tracking_imeis i ON i.cert_id = t.id
--    WHERE t.approval_status = 'approved' AND t.status = 'issued'
--    GROUP BY t.id HAVING count(*) > 1;
