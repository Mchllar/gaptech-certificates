-- One-time codes e-mailed to staff: account setup, password reset, password change.
-- Only an HMAC of each code is stored. Safe to run more than once.
CREATE TABLE IF NOT EXISTS email_codes (
    id           serial PRIMARY KEY,
    user_id      integer NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    purpose      text NOT NULL CHECK (purpose IN ('setup', 'reset', 'change')),
    code_hash    text NOT NULL,
    expires_at   timestamptz NOT NULL,
    attempts     integer NOT NULL DEFAULT 0,
    used_at      timestamptz,
    requested_ip text NOT NULL DEFAULT '',
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS email_codes_user_idx ON email_codes (user_id, purpose, created_at);
