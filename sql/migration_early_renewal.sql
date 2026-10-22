-- Paying for a renewal before the current certificate expires.
--
-- Run in psql, connected to the right database:
--     \c gaptech_certs gaptech
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_early_renewal.sql'
--
-- No new columns. An early renewal is an ordinary certificate whose issue_date is
-- in the future - it is approved, it has its number, and it simply has not started
-- yet. certificate_status() reports that as "Scheduled".

BEGIN;

-- How early a client may pay. Change in Settings, not in the code.
INSERT INTO settings (key, value) VALUES ('early_renewal_days', '60')
    ON CONFLICT (key) DO NOTHING;

COMMIT;

-- Worth knowing before this goes live: there should be no certificate already
-- carrying a future issue_date, because until now nothing created one. If this
-- returns rows, they will start showing as Scheduled and will stop being
-- downloadable, so check them before you run the rest of the change.
--
--   SELECT id, number, issue_date, expiry_date
--   FROM certificates
--   WHERE issue_date > current_date
--   ORDER BY issue_date;
