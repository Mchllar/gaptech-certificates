
BEGIN;
 
-- Which blank was used.
--
-- A pre-printed certificate carries its own serial (01199) and the system
-- issues its own number (01193). They are different things and both matter:
-- the system number is what verification knows, the stock serial is what the
-- customer is holding and what the stationery is accounted for by.
ALTER TABLE tracking_certificates
    ADD COLUMN IF NOT EXISTS stock_serial text NOT NULL DEFAULT '';
 
-- One blank, one certificate. Accountable stationery is only accountable if
-- the same serial cannot be recorded twice - that is the whole reason it is
-- pre-numbered. Blanks are excluded, since most certificates have none.
CREATE UNIQUE INDEX IF NOT EXISTS tracking_certificates_stock_serial_idx
    ON tracking_certificates (upper(stock_serial)) WHERE stock_serial <> '';
 
-- The usual paper, and the nudge needed to land on it.
--
-- Every printer feeds paper a fraction differently, so text that lines up on
-- one machine sits two millimetres high on another. These two shift the whole
-- sheet; they are set once per printer and then left alone.
INSERT INTO settings (key, value) VALUES
    ('tracking_print_stock', 'plain'),      -- plain | preprinted
    ('print_offset_x',       '0'),          -- millimetres, right is positive
    ('print_offset_y',       '0')           -- millimetres, down is positive
ON CONFLICT (key) DO NOTHING;
 
COMMIT;
 