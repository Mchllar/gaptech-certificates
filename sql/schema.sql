-- GAPTECH speed governor certificate system - PostgreSQL schema
-- Run once:  psql -U gaptech -d gaptech_certs -f sql/schema.sql

BEGIN;

-- Staff who use the system
CREATE TABLE IF NOT EXISTS users (
    id            serial PRIMARY KEY,
    name          text NOT NULL,
    email         text NOT NULL UNIQUE,
    password_hash text NOT NULL,
    role          text NOT NULL DEFAULT 'staff' CHECK (role IN ('staff', 'supervisor')),
    active        boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now()
);

-- Clients. password_hash is set only for clients who use the download portal.
CREATE TABLE IF NOT EXISTS clients (
    id            serial PRIMARY KEY,
    name          text NOT NULL,
    phone         text NOT NULL DEFAULT '',
    address       text NOT NULL DEFAULT '',
    email         text NOT NULL DEFAULT '',
    password_hash text,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS clients_name_idx ON clients (lower(name));
CREATE UNIQUE INDEX IF NOT EXISTS clients_email_idx ON clients (lower(email)) WHERE email <> '';

CREATE TABLE IF NOT EXISTS vehicles (
    id         serial PRIMARY KEY,
    client_id  integer NOT NULL REFERENCES clients (id),
    reg_no     text NOT NULL UNIQUE,
    make       text NOT NULL DEFAULT '',
    chassis_no text NOT NULL DEFAULT '',
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS devices (
    id            serial PRIMARY KEY,
    model         text NOT NULL DEFAULT 'INTELSPEEDGAP™',
    serial_no     text NOT NULL UNIQUE,
    unit_code     text NOT NULL DEFAULT '',
    set_speed_kmh integer NOT NULL DEFAULT 80,
    created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS installations (
    id           serial PRIMARY KEY,
    vehicle_id   integer NOT NULL REFERENCES vehicles (id),
    device_id    integer NOT NULL REFERENCES devices (id),
    technician   text NOT NULL DEFAULT 'GAPTECH',
    installed_on date NOT NULL,
    removed_on   date
);

-- One row per certificate ever issued. snapshot holds every value printed on the
-- sheet, so a reprint years later is identical even if the records changed since.
CREATE TABLE IF NOT EXISTS certificates (
    id              serial PRIMARY KEY,
    number          integer NOT NULL UNIQUE,
    type            text NOT NULL CHECK (type IN ('Fitting', 'Renewal')),
    client_id       integer NOT NULL REFERENCES clients (id),
    vehicle_id      integer NOT NULL REFERENCES vehicles (id),
    device_id       integer NOT NULL REFERENCES devices (id),
    installation_id integer NOT NULL REFERENCES installations (id),
    issue_date      date NOT NULL,
    expiry_date     date NOT NULL,
    receipt_ref     text NOT NULL DEFAULT '',
    snapshot        jsonb NOT NULL,
    verify_token    text NOT NULL,
    status          text NOT NULL DEFAULT 'issued' CHECK (status IN ('issued', 'voided')),
    pdf_path        text,
    pdf_sha256      text,
    print_count     integer NOT NULL DEFAULT 0,
    download_count  integer NOT NULL DEFAULT 0,
    issued_by       integer REFERENCES users (id),
    issued_at       timestamptz NOT NULL DEFAULT now(),
    void_reason     text,
    voided_by       integer REFERENCES users (id),
    voided_at       timestamptz,
    --When it was first downloaded by the client/staff
    released_at       timestamptz
);
CREATE INDEX IF NOT EXISTS certificates_vehicle_idx ON certificates (vehicle_id);
CREATE INDEX IF NOT EXISTS certificates_client_idx ON certificates (client_id);
CREATE INDEX IF NOT EXISTS certificates_expiry_idx ON certificates (expiry_date);
-- a receipt reference may only be used once
CREATE UNIQUE INDEX IF NOT EXISTS certificates_receipt_idx ON certificates (receipt_ref) WHERE receipt_ref <> '';

CREATE TABLE IF NOT EXISTS settings (
    key   text PRIMARY KEY,
    value text NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS audit_log (
    id         bigserial PRIMARY KEY,
    actor_type text NOT NULL,
    actor_id   integer,
    action     text NOT NULL,
    entity     text NOT NULL,
    entity_id  integer,
    details    jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Company details as printed on the certificate. Edit these in Settings, not here.
INSERT INTO settings (key, value) VALUES
    ('company_name',     'GAPTECH Solutions Ltd'),
    ('tagline',          'Vehicle tracking, Fleet Management & IT Solutions'),
    ('address_line1',    '3rd Floor Wing A , Plaza 2000 Mombasa Road'),
    ('address_line2',    'P.O. Box 28172 00100, Nairobi, Kenya'),
    ('cell',             '0797 719 161 / 0791 745 821'),
    ('landline',         '+254 (020) 4401377'),
    ('email',            'info@gaptechsolutions.com'),
    ('reg_no',           'CPR/2015/79108'),
    ('dealer_no',        '2019033'),
    ('dealer_name',      'GAP TECH SOLUTIONS LTD'),
    ('kebs_permit',      'SM#81402'),
    ('signatory_name',   ''),
    ('signatory_title',  ''),
    ('signature_image',  ''),
    ('stamp_image',      ''),
    ('default_model',    'INTELSPEEDGAP™'),
    ('default_speed',    '80'),

    -- IMPORTANT: set this to the highest number already used on paper, so the
    -- system never reissues a number that exists on a printed certificate.
    ('last_cert_number', '13621')
ON CONFLICT (key) DO NOTHING;

INSERT INTO settings (key, value) VALUES 
('default_technician', 'GAPTECH')
ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value;

COMMIT;
