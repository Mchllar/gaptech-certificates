-- Printing options for both certificates.
--
-- Run in psql, connected to the right database:
--     \c gaptech_certs gaptech
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_print_options.sql'
--
-- Replaces migration_print_stock.sql, migration_tracking_preprinted.sql and
-- migration_governor_preprinted.sql. Printing onto pre-printed stationery was
-- removed, so all that is left of it is:
--
--   stock_serial   the number on a pre-printed blank a certificate was printed
--                  on before that option went. Still shown on the certificate
--                  and searchable from the register. One blank, one certificate.
--
--   the stamp / signature defaults for printing (1 = print unless unticked).
--
-- The settings the pre-printed option used are deleted if they exist.
--
-- Safe to run twice, and safe on a database that ran the old files.

BEGIN;

ALTER TABLE certificates
    ADD COLUMN IF NOT EXISTS stock_serial text NOT NULL DEFAULT '';
CREATE UNIQUE INDEX IF NOT EXISTS certificates_stock_serial_idx
    ON certificates (upper(stock_serial)) WHERE stock_serial <> '';

ALTER TABLE tracking_certificates
    ADD COLUMN IF NOT EXISTS stock_serial text NOT NULL DEFAULT '';
CREATE UNIQUE INDEX IF NOT EXISTS tracking_certificates_stock_serial_idx
    ON tracking_certificates (upper(stock_serial)) WHERE stock_serial <> '';

INSERT INTO settings (key, value) VALUES
    ('governor_print_signature', '1'),
    ('tracking_print_stamp',     '1'),
    ('tracking_print_signature', '1')
ON CONFLICT (key) DO NOTHING;

DELETE FROM settings
 WHERE key IN ('tracking_print_stock', 'print_offset_x', 'print_offset_y', 'preprinted_font_pt',
               'governor_print_stock', 'governor_offset_x', 'governor_offset_y');

COMMIT;
