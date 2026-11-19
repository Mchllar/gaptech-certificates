-- A password set by staff for a client works for one sign-in only: the portal then
-- makes the client choose their own. Safe to run more than once.
ALTER TABLE clients ADD COLUMN IF NOT EXISTS must_change_password boolean NOT NULL DEFAULT false;
