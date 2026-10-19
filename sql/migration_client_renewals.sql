-- Clients renewing their own certificates from the portal.
--
-- Run in psql, connected to the right database:
--     \c gaptech_certs gaptech
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_client_renewals.sql'

BEGIN;

-- A renewal raised by a client has no member of staff behind it, so issued_by is
-- null and this records who actually asked. Without it the audit trail would say
-- nobody raised the certificate.
ALTER TABLE certificates ADD COLUMN IF NOT EXISTS requested_by_client
    integer REFERENCES clients (id);

CREATE INDEX IF NOT EXISTS certificates_requested_by_client_idx
    ON certificates (requested_by_client) WHERE requested_by_client IS NOT NULL;

-- What the portal tells a client to pay, and where.
--
-- The paybill is deliberately left EMPTY. Set it in Settings to GAPTECH's real
-- paybill before telling any client about this. Until it is set the portal says
-- the details are not available and refuses the renewal, rather than showing a
-- number that is not yours.
INSERT INTO settings (key, value) VALUES
    ('mpesa_paybill', ''),
    ('renewal_fee', '7000')
    ON CONFLICT (key) DO NOTHING;

COMMIT;

-- Afterwards, set the paybill:
--   UPDATE settings SET value = '123456' WHERE key = 'mpesa_paybill';
