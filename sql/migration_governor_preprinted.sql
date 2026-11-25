-- Printing speed governor certificates onto the pre-printed stationery.
--
--     psql -U gaptech -d gaptech_certs -f sql/migration_governor_preprinted.sql
--
-- Safe to run more than once. The tracking module has its own:
-- migration_tracking_preprinted.sql.

BEGIN;

-- The Cert No. printed on the blank a certificate went onto.
--
-- The stationery is numbered in four places (certificate, warranty card,
-- duplicate stub, sticker). Usually that is the same number the system gave
-- the certificate, because last_cert_number is kept in step with the stock.
-- When it is not, the blank's number is kept here: the register searches it,
-- and the same blank cannot be booked against two vehicles.
ALTER TABLE certificates
    ADD COLUMN IF NOT EXISTS stock_serial text NOT NULL DEFAULT '';

CREATE UNIQUE INDEX IF NOT EXISTS certificates_stock_serial_idx
    ON certificates (upper(stock_serial)) WHERE stock_serial <> '';

-- The usual paper, the signature default, and this sheet's printer nudge.
-- Separate from the tracking ones: this sheet is landscape, so the same
-- printer can drift differently with it.
INSERT INTO settings (key, value) VALUES
    ('governor_print_stock',     'plain'),   -- plain | preprinted
    ('governor_print_signature', '1'),       -- 1 = print the signature unless unticked
    ('governor_offset_x',        '0'),       -- millimetres, right is positive
    ('governor_offset_y',        '0')        -- millimetres, down is positive
ON CONFLICT (key) DO NOTHING;

COMMIT;
