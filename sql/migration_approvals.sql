-- Accounts approval before a certificate can be printed or downloaded.
-- Run in psql, connected to the right database:
--     \c gaptech_certs gaptech
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_approvals.sql'

BEGIN;

-- 1. An accounts role, alongside administrator
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check
    CHECK (role IN ('administrator', 'accounts'));

-- 2. Approval is tracked separately from the certificate's lifecycle, because the
--    two are different questions: a certificate can be approved and expired, or
--    pending and still within its dates.
ALTER TABLE certificates ADD COLUMN IF NOT EXISTS approval_status text
    NOT NULL DEFAULT 'approved';
ALTER TABLE certificates DROP CONSTRAINT IF EXISTS certificates_approval_check;
ALTER TABLE certificates ADD CONSTRAINT certificates_approval_check
    CHECK (approval_status IN ('pending', 'approved', 'rejected'));

ALTER TABLE certificates ADD COLUMN IF NOT EXISTS payment_note      text;
ALTER TABLE certificates ADD COLUMN IF NOT EXISTS approved_by       integer REFERENCES users (id);
ALTER TABLE certificates ADD COLUMN IF NOT EXISTS approved_at       timestamptz;
ALTER TABLE certificates ADD COLUMN IF NOT EXISTS rejection_reason  text;

-- payment_ref replaces the old receipt_ref, which was filled in by whoever issued
-- the certificate. Carry across anything already recorded.
ALTER TABLE certificates ADD COLUMN IF NOT EXISTS payment_ref text NOT NULL DEFAULT '';
UPDATE certificates SET payment_ref = receipt_ref
WHERE payment_ref = '' AND receipt_ref IS NOT NULL AND receipt_ref <> '';

-- one payment may only approve one certificate
DROP INDEX IF EXISTS certificates_receipt_idx;
CREATE UNIQUE INDEX IF NOT EXISTS certificates_payment_ref_idx
    ON certificates (upper(payment_ref)) WHERE payment_ref <> '';

-- 3. Certificate numbers are now allocated at approval, so a pending certificate
--    has none yet. Everything already in the system keeps the number it has.
ALTER TABLE certificates ALTER COLUMN number DROP NOT NULL;

-- 4. Certificates issued before this change stay approved - they are already out
--    in the world and must keep working.
UPDATE certificates SET approval_status = 'approved',
       approved_at = COALESCE(approved_at, issued_at)
WHERE approval_status IS NULL OR approval_status = '';

COMMIT;

-- Afterwards, make at least one account the approver. Check who exists first:
--   SELECT id, name, username, role FROM users ORDER BY name;
--   UPDATE users SET role = 'accounts' WHERE username = 't.ochieng';
--
-- An administrator issues but cannot approve. An accounts user approves but
-- cannot issue. You need at least one of each for the system to work end to end.
