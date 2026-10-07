-- Administrators sign in with a username. E-mail becomes optional, and is used
-- only for password-reset links.
BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS username text;

-- existing accounts: take the part before the @ as the username
UPDATE users SET username = split_part(email, '@', 1) WHERE username IS NULL OR username = '';

ALTER TABLE users ALTER COLUMN username SET NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS users_username_idx ON users (lower(username));

-- e-mail is no longer required, but must still be unique when given
ALTER TABLE users ALTER COLUMN email DROP NOT NULL;
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_key;
CREATE UNIQUE INDEX IF NOT EXISTS users_email_idx ON users (lower(email))
    WHERE email IS NOT NULL AND email <> '';

COMMIT;

-- Check what each account's username became, and change any you dislike:
--   SELECT id, name, username, email FROM users ORDER BY name;
--   UPDATE users SET username = 'michelle' WHERE id = 1;
