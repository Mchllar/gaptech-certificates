-- Record an LPO against a certificate.
--
-- Run in psql, connected to the right database:
--     \c gaptech_certs gaptech
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_lpo.sql'
--
-- Some clients do not pay before the certificate is issued - they send a local
-- purchase order and settle later. That is a different fact from a payment, so
-- it gets its own column rather than being squeezed into payment_ref:
--
--   payment_ref   money received. One payment, one certificate - enforced.
--   lpo_no        a promise to pay. One LPO often covers a whole fleet, so it is
--                 DELIBERATELY NOT UNIQUE.
--
-- Safe to run twice.

BEGIN;

ALTER TABLE certificates ADD COLUMN IF NOT EXISTS lpo_no text NOT NULL DEFAULT '';

-- For "show me everything issued against an LPO", which is the question whoever
-- chases payment will ask.
CREATE INDEX IF NOT EXISTS certificates_lpo_idx
    ON certificates (upper(lpo_no)) WHERE lpo_no <> '';

COMMIT;

-- Afterwards, the two questions this makes answerable:
--
--   -- everything issued against an LPO and the client it belongs to
--   SELECT c.number, c.lpo_no, c.issue_date, v.reg_no, cl.name
--     FROM certificates c
--     JOIN vehicles v  ON v.id = c.vehicle_id
--     LEFT JOIN clients cl ON cl.id = c.client_id
--    WHERE c.lpo_no <> '' AND c.approval_status = 'approved'
--    ORDER BY c.issue_date DESC;
--
--   -- one LPO, every vehicle it covered
--   SELECT c.number, v.reg_no, c.issue_date
--     FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
--    WHERE upper(c.lpo_no) = upper('WSS/2026/118');
