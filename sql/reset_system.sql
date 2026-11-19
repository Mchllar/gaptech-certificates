
\pset pager off
\set ON_ERROR_STOP on


-- ------------------------------------------------------------ 1. WHAT IS THERE
\echo ''
\echo '=============================================================='
\echo ' 1. What is in the system now'
\echo '=============================================================='

SELECT c.relname AS table_name, c.reltuples::bigint AS approx_rows
  FROM pg_class c
  JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind = 'r'
 ORDER BY c.relname;

\echo ''
\echo '--- the accounts, and which survive ---'
SELECT id, name, email, role,
       CASE WHEN role = 'administrator' THEN 'KEPT' ELSE 'deleted' END AS what_happens
  FROM users ORDER BY role, id;

\echo ''
\echo '--- the counters, which are NOT reset by this script ---'
SELECT key, value FROM settings WHERE key LIKE '%number%' OR key LIKE '%count%';


-- ---------------------------------------------------------------- 2. BACKUP
\echo ''
\echo '=============================================================='
\echo ' 2. Back it up FIRST - from a command prompt, not from here:'
\echo ''
\echo '   "C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe" -U gaptech -d gaptech_certs -f C:\\gaptech-backup.sql'
\echo ''
\echo ' Check the file exists and is more than a few KB before going on.'
\echo '=============================================================='


-- ------------------------------------------------------------------ 3. WIPE
BEGIN;

-- Every table except the two that are configuration rather than data.
--
-- Built from the catalogue rather than a list I typed out: a list goes stale
-- the moment a table is added - certificate_batches and batch_payments did not
-- exist a week ago - and the table it forgets is the one left holding data
-- that points at rows which no longer exist.
--
-- TRUNCATE ... CASCADE sorts out the foreign keys itself, so there is no
-- delete-in-the-right-order to get wrong. RESTART IDENTITY puts the id
-- sequences back to 1.
DO $$
DECLARE
    keep text[] := ARRAY['users', 'settings'];
    list text;
BEGIN
    SELECT string_agg(format('%I.%I', schemaname, tablename), ', ')
      INTO list
      FROM pg_tables
     WHERE schemaname = 'public'
       AND tablename <> ALL (keep);

    IF list IS NOT NULL THEN
        RAISE NOTICE 'emptying: %', list;
        EXECUTE 'TRUNCATE TABLE ' || list || ' RESTART IDENTITY CASCADE';
    END IF;
END $$;

-- The accounts. Administrators stay - that is the way back in. Everyone else
-- goes, so the system starts with the people who set it up and no one else.
--
-- If you would rather keep Moses and the management accounts, comment this out.
DELETE FROM users WHERE role <> 'administrator';

\echo ''
\echo '=============================================================='
\echo ' 3. After the wipe (not yet saved)'
\echo '=============================================================='

SELECT 'certificates'          AS table_name, count(*) FROM certificates
UNION ALL SELECT 'tracking_certificates', count(*) FROM tracking_certificates
UNION ALL SELECT 'clients',               count(*) FROM clients
UNION ALL SELECT 'vehicles',              count(*) FROM vehicles
UNION ALL SELECT 'devices',               count(*) FROM devices
UNION ALL SELECT 'installations',         count(*) FROM installations
UNION ALL SELECT 'users (kept)',          count(*) FROM users
UNION ALL SELECT 'settings (kept)',       count(*) FROM settings;

\echo ''
\echo '--- who can still sign in ---'
SELECT id, name, email, role FROM users ORDER BY id;

\echo ''
\echo '*** Nothing has been saved. Read the numbers above. ***'
\echo '*** If they are right, change ROLLBACK to COMMIT at the bottom'
\echo '*** of this file and run it again.'

COMMIT;
