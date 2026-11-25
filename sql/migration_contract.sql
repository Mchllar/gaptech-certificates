-- Release a certificate against a contract number.
--
-- Run in psql, connected to the right database:
--     \c gaptech_certs gaptech
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_contract.sql'
--
-- Some clients are on a contract with GAPTECH, and their certificates are
-- released against the contract rather than a payment or an LPO. Like an LPO,
-- one contract covers many vehicles, so it is DELIBERATELY NOT UNIQUE.
--
-- Safe to run twice.

BEGIN;

ALTER TABLE certificates   ADD COLUMN IF NOT EXISTS contract_no text NOT NULL DEFAULT '';
ALTER TABLE batch_payments ADD COLUMN IF NOT EXISTS contract_no text NOT NULL DEFAULT '';

CREATE INDEX IF NOT EXISTS certificates_contract_idx
    ON certificates (upper(contract_no)) WHERE contract_no <> '';

-- A batch payment row records what the batch was released on: a reference, an
-- LPO, or now a contract.
ALTER TABLE batch_payments DROP CONSTRAINT IF EXISTS batch_payments_something_paid;
ALTER TABLE batch_payments ADD CONSTRAINT batch_payments_something_paid
    CHECK (payment_ref <> '' OR lpo_no <> '' OR contract_no <> '');

COMMIT;
