-- Tracking installation certificates.
--
-- A separate module: its own table, its own number series, its own customer and
-- vehicle details. Nothing here touches the speed governor certificates.
--
-- Run in psql, connected to the right database:
--     \c gaptech_certs gaptech
--     \i 'C:/xampp/htdocs/gaptech-certificates/sql/migration_tracking.sql'

BEGIN;

CREATE TABLE IF NOT EXISTS tracking_certificates (
    id              serial PRIMARY KEY,
    number          integer UNIQUE,                 -- null until accounts approves

    -- who and what
    client_name     text NOT NULL,
    client_phone    text NOT NULL DEFAULT '',
    reg_no          text NOT NULL,
    make_model      text NOT NULL DEFAULT '',
    chassis_no      text NOT NULL DEFAULT '',
    engine_no       text NOT NULL DEFAULT '',
    colour          text NOT NULL DEFAULT '',
    ownership       text NOT NULL DEFAULT '',
    financier       text NOT NULL DEFAULT '',
    imei            text NOT NULL DEFAULT '',

    -- dates. installed_on is historic: it stays as it was on a renewal, because
    -- the unit was only ever fitted once.
    installed_on      date NOT NULL,
    subscription_date date NOT NULL,
    expiry_date       date NOT NULL,

    signed_by       text NOT NULL DEFAULT '',

    -- lifecycle, mirroring the speed governor module
    status          text NOT NULL DEFAULT 'issued',    -- issued | superseded | voided
    approval_status text NOT NULL DEFAULT 'pending',   -- pending | approved | rejected

    payment_ref      text NOT NULL DEFAULT '',
    payment_note     text NOT NULL DEFAULT '',
    rejection_reason text,
    void_reason      text,

    approved_by     integer REFERENCES users(id),
    approved_at     timestamptz,
    issued_by       integer REFERENCES users(id),
    issued_at       timestamptz NOT NULL DEFAULT now(),
    renewed_from    integer REFERENCES tracking_certificates(id),

    verify_token    text NOT NULL,
    snapshot        jsonb NOT NULL,                 -- frozen copy of what was printed
    print_count     integer NOT NULL DEFAULT 0,
    pdf_path        text,
    pdf_sha256      text
);

ALTER TABLE tracking_certificates DROP CONSTRAINT IF EXISTS tracking_status_check;
ALTER TABLE tracking_certificates ADD CONSTRAINT tracking_status_check
    CHECK (status IN ('issued', 'superseded', 'voided'));

ALTER TABLE tracking_certificates DROP CONSTRAINT IF EXISTS tracking_approval_check;
ALTER TABLE tracking_certificates ADD CONSTRAINT tracking_approval_check
    CHECK (approval_status IN ('pending', 'approved', 'rejected'));

-- Registrations are matched on letters and digits only, so KDF745B finds KDF 745B.
CREATE INDEX IF NOT EXISTS idx_tracking_plate
    ON tracking_certificates ((regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g')));

CREATE INDEX IF NOT EXISTS idx_tracking_pending
    ON tracking_certificates (approval_status, issued_at DESC);

-- One payment may only approve one tracking certificate.
CREATE UNIQUE INDEX IF NOT EXISTS idx_tracking_payment_ref
    ON tracking_certificates (upper(payment_ref)) WHERE payment_ref <> '';

-- Its own counter, independent of the speed governor series. The pre-printed books
-- ran to 01189, so the first system-generated certificate is 01190.
INSERT INTO settings (key, value) VALUES ('last_tracking_number', '1189')
    ON CONFLICT (key) DO NOTHING;

-- Printed on the certificate. Change in Settings later if the office moves.
INSERT INTO settings (key, value) VALUES
    ('tracking_address', 'GAP TECH SOLUTIONS LIMITED. 3RD FLOOR WING A, PLAZA 2000 MOMBASA ROAD, P.O. BOX 28172-00100, NAIROBI, KENYA. TEL: +254 720917733, +254 20 440 1377'),
    ('tracking_emergency', '0720917733 / 0722785285 / 0733785285'),
    ('tracking_email', 'p.abeno@gaptechsolutions.com'),
    ('tracking_website', 'www.gaptechsolutions.com')
    ON CONFLICT (key) DO NOTHING;

-- Every installation certificate is signed and approved by the same person, so the
-- name is a setting rather than something retyped on each form. Change it here if
-- that ever changes; certificates already issued keep the name they were signed with.
INSERT INTO settings (key, value) VALUES ('tracking_signatory', 'MOSES CLAYTONE')
    ON CONFLICT (key) DO UPDATE SET value = 'MOSES CLAYTONE';

COMMIT;
