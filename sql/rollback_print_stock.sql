-- Undo migration_print_stock.sql.
--
--     \c gaptech_certs gaptech
--    c
BEGIN;

-- Anything recorded against a blank, before it goes. If this returns rows,
-- stop and copy them somewhere first - dropping the column throws them away.
\echo ''
\echo '=== certificates with a blank recorded (these would be lost) ==='
SELECT id, number, stock_serial FROM tracking_certificates WHERE stock_serial <> '';

DROP INDEX IF EXISTS tracking_certificates_stock_serial_idx;
ALTER TABLE tracking_certificates DROP COLUMN IF EXISTS stock_serial;

DELETE FROM settings
 WHERE key IN ('tracking_print_stock', 'print_offset_x', 'print_offset_y');

COMMIT;

\echo ''
\echo 'Done. Nothing else in the tracking module referred to these.'