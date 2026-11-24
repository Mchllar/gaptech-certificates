-- Printing tracking certificates onto the pre-printed blanks in store.
--
--     psql -U gaptech -d gaptech_certs -f sql/migration_tracking_preprinted.sql
--
-- Safe to run more than once.
--
-- The blank already carries the whole design - logos, title, table, footer - so
-- on that paper the system prints only the entries. Two things need storing:

BEGIN;

-- 1. Which blank a certificate went onto.
--
-- Most blanks have an empty "Serial No." space, and the system prints its own
-- number there - so paper and system agree, and nothing needs recording. Where a
-- blank already carries a number of its own, that number is kept here, so a
-- customer holding it can still be found (the register searches this column),
-- and the same blank cannot be booked against two vehicles.
ALTER TABLE tracking_certificates
    ADD COLUMN IF NOT EXISTS stock_serial text NOT NULL DEFAULT '';

CREATE UNIQUE INDEX IF NOT EXISTS tracking_certificates_stock_serial_idx
    ON tracking_certificates (upper(stock_serial)) WHERE stock_serial <> '';

-- 2. The usual paper, and the nudge each printer needs to land on the boxes.
--
-- Every printer feeds paper a fraction differently. These shift every entry
-- together, in millimetres, and are set once per printer from Settings.
INSERT INTO settings (key, value) VALUES
    ('tracking_print_stock', 'plain'),      -- plain | preprinted
    ('print_offset_x',       '0'),          -- millimetres, right is positive
    ('print_offset_y',       '0'),          -- millimetres, down is positive
    ('preprinted_font_pt',   '13'),         -- size of the entries on a blank, in points
    ('tracking_print_stamp', '1'),          -- 1 = print the stamp unless unticked
    ('tracking_print_signature', '1')       -- 1 = print the signature unless unticked
ON CONFLICT (key) DO NOTHING;

COMMIT;
