-- ---------------------------------------------------------------------------
-- A management role: sees the dashboards, and nothing else.
--
-- Run it in psql:
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_management_role.sql'
--
-- Safe to run twice.
-- ---------------------------------------------------------------------------
-- The constraint is dropped and rebuilt rather than added to, because a CHECK
-- cannot be extended in place. Rebuilding it also re-validates every existing
-- row, so if anything in this table has a role that is not one of these three,
-- this migration refuses to finish and tells you - which is the right moment to
-- find out, rather than when somebody cannot sign in.
-- ---------------------------------------------------------------------------

BEGIN;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;

ALTER TABLE users ADD CONSTRAINT users_role_check
    CHECK (role IN ('administrator', 'accounts', 'management'));

COMMIT;

-- ---------------------------------------------------------------------------
-- The role name is deliberately NOT a level.
--
-- 'management' says who the account belongs to, not how much power it has. The
-- powers live in app/bootstrap.php, in management_pages(), as a list of the
-- pages this role may open - and anything not on that list is refused, including
-- pages that do not exist yet.
--
-- That direction matters. A role defined as "everything except X" quietly gains
-- whatever gets built next; a role defined as "these pages only" does not.
-- ---------------------------------------------------------------------------

-- Who is on file now:
--   SELECT id, name, username, role, active FROM users ORDER BY role, name;
