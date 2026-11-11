-- Bulk certificates: a fleet onboards many vehicles and pays once.
--
--     \c gaptech_certs gaptech
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_batches.sql'

BEGIN;

-- A batch is one fleet's intake: many vehicles, raised together, approved together.
CREATE TABLE IF NOT EXISTS certificate_batches (
    id               serial PRIMARY KEY,
    client_id        integer NOT NULL REFERENCES clients (id),
    label            text NOT NULL DEFAULT '',
    approval_status  text NOT NULL DEFAULT 'pending'
                     CHECK (approval_status IN ('pending', 'approved', 'rejected')),
    rejection_reason text,
    created_by       integer REFERENCES users (id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    approved_by      integer REFERENCES users (id),
    approved_at      timestamptz
);

ALTER TABLE certificates ADD COLUMN IF NOT EXISTS batch_id integer
    REFERENCES certificate_batches (id);
CREATE INDEX IF NOT EXISTS certificates_batch_idx ON certificates (batch_id);

-- The payment is its own record, not a column on the batch.
--
-- Usually there is one: a fleet pays once for forty lorries. But accounts can
-- hold back the two that were not paid for, and those are settled later on a
-- second reference - so a batch can have more than one payment, and the first
-- must not be overwritten by the second. One row per payment keeps both, and
-- keeps "a reference may only be used once" something the DATABASE enforces
-- rather than something the code has to remember.
CREATE TABLE IF NOT EXISTS batch_payments (
    id           serial PRIMARY KEY,
    batch_id     integer NOT NULL REFERENCES certificate_batches (id),
    payment_ref  text NOT NULL DEFAULT '',
    lpo_no       text NOT NULL DEFAULT '',
    note         text,
    certificates integer NOT NULL DEFAULT 0,
    received_by  integer REFERENCES users (id),
    received_at  timestamptz NOT NULL DEFAULT now()
);
-- A payment reference may be used once. An LPO may cover more than one batch -
-- the client is paying later and one order can carry several intakes - so it is
-- recorded but not made unique.
CREATE UNIQUE INDEX IF NOT EXISTS batch_payments_ref_idx
    ON batch_payments (upper(payment_ref)) WHERE payment_ref <> '';
ALTER TABLE batch_payments DROP CONSTRAINT IF EXISTS batch_payments_something_paid;
ALTER TABLE batch_payments ADD CONSTRAINT batch_payments_something_paid
    CHECK (payment_ref <> '' OR lpo_no <> '');
CREATE INDEX IF NOT EXISTS batch_payments_batch_idx ON batch_payments (batch_id);

-- A unit moved off another vehicle closes that fitting, and a closed fitting
-- with no reason is the thing nobody can explain six months later - the same
-- rule the certificates table already keeps.
ALTER TABLE installations ADD COLUMN IF NOT EXISTS removed_reason text;

-- A certificate raised on its own keeps the old rule - one payment, one
-- certificate - because it has no batch to hold the payment for it.
-- Certificates inside a batch share their payment, which is the point of a
-- batch, so they are excluded from this index rather than losing the control:
-- their reference is kept unique by batch_payments above.
DROP INDEX IF EXISTS certificates_payment_ref_idx;
CREATE UNIQUE INDEX IF NOT EXISTS certificates_payment_ref_single_idx
    ON certificates (upper(payment_ref))
    WHERE payment_ref <> '' AND batch_id IS NULL;

COMMIT;