-- Fleet installations for trackers: many vehicles raised together, released on one payment.
-- The tracking twin of migration_batches.sql. Safe to run more than once.

BEGIN;

-- One fleet's intake. Tracking keeps its customer as text on each certificate (there is no
-- clients row to point at), so the batch carries the customer the same way.
CREATE TABLE IF NOT EXISTS tracking_batches (
    id               serial PRIMARY KEY,
    client_name      text NOT NULL,
    client_phone     text NOT NULL DEFAULT '',
    label            text NOT NULL DEFAULT '',
    approval_status  text NOT NULL DEFAULT 'pending'
                     CHECK (approval_status IN ('pending', 'approved', 'rejected')),
    rejection_reason text,
    created_by       integer REFERENCES users (id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    approved_by      integer REFERENCES users (id),
    approved_at      timestamptz
);

ALTER TABLE tracking_certificates ADD COLUMN IF NOT EXISTS batch_id integer
    REFERENCES tracking_batches (id);
CREATE INDEX IF NOT EXISTS tracking_certificates_batch_idx ON tracking_certificates (batch_id);

-- The payment is its own record. A fleet usually pays once, but accounts can hold back the
-- vehicles that were not paid for and release them later on a second reference - so one row
-- per payment, and the first is never overwritten by the second.
CREATE TABLE IF NOT EXISTS tracking_batch_payments (
    id           serial PRIMARY KEY,
    batch_id     integer NOT NULL REFERENCES tracking_batches (id),
    payment_ref  text NOT NULL,
    note         text,
    certificates integer NOT NULL DEFAULT 0,
    received_by  integer REFERENCES users (id),
    received_at  timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS tracking_batch_payments_ref_idx
    ON tracking_batch_payments (upper(payment_ref));
CREATE INDEX IF NOT EXISTS tracking_batch_payments_batch_idx ON tracking_batch_payments (batch_id);

-- A certificate raised on its own keeps "one payment, one certificate". Certificates in a
-- fleet share their payment - the point of a fleet - so they are left out of this index;
-- their reference is kept unique by tracking_batch_payments above instead.
DROP INDEX IF EXISTS idx_tracking_payment_ref;
CREATE UNIQUE INDEX IF NOT EXISTS idx_tracking_payment_ref_single
    ON tracking_certificates (upper(payment_ref))
    WHERE payment_ref <> '' AND batch_id IS NULL;

COMMIT;
