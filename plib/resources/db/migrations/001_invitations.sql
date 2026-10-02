-- One row per pending invitation. Only the SHA-256 hash of the access token is stored.
--   alias_id  Plesk's ID of the additional administrator (admin_aliases.id). Filled in by
--             library/EventListener.php when the delayed admin_alias_create event arrives.
--   email     The address the invitation was sent to (lower case). An invitation is only valid
--             while the account still has this address.
CREATE TABLE IF NOT EXISTS invitations (
    login      TEXT    NOT NULL PRIMARY KEY COLLATE NOCASE,
    token_hash TEXT    NOT NULL UNIQUE,
    alias_id   INTEGER UNIQUE,
    email      TEXT    NOT NULL,
    expires_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_invitations_expires_at ON invitations (expires_at);
