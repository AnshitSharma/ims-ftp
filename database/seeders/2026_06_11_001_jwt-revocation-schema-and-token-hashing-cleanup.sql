-- ============================================================================
-- Date:     2026-06-11
-- Purpose:  Auth security hardening (security review fixes)
--           1. Create the JWT revocation schema that JWTHelper::verifyToken()
--              checks (revoked_tokens table + users.password_changed_at).
--              Without it, logout / password-reset revocation silently fails
--              open (schema-missing exception in verifyToken).
--           2. Purge legacy plaintext tokens that the hardened code can no
--              longer match: refresh tokens (auth_tokens now stores SHA-256
--              hashes) and unused password-reset tokens (password_resets now
--              stores SHA-256 hashes).
-- Affected: revoked_tokens (new), users (new column), auth_tokens (data),
--           password_resets (data)
-- Related:  Authentication flow security review — issues #7 (fail-open
--           revocation) and #11 (unhashed reset tokens); broken
--           auth-refresh fix in api.php::handleTokenRefresh
-- Idempotent: yes — safe to run more than once.
-- ============================================================================

-- 1. Per-token blacklist consulted by JWTHelper::verifyToken().
--    jti is bin2hex(random_bytes(16)) = 32 hex chars.
CREATE TABLE IF NOT EXISTS `revoked_tokens` (
  `jti` VARCHAR(64) NOT NULL,
  `user_id` INT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`jti`),
  KEY `idx_revoked_tokens_expires_at` (`expires_at`),
  KEY `idx_revoked_tokens_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Global per-user cutoff: tokens issued before this timestamp are
--    rejected by JWTHelper::verifyToken(). Stamped by handleResetPassword.
--    NULL = never changed → no cutoff applies.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `password_changed_at` DATETIME NULL DEFAULT NULL;

-- 3. Purge legacy refresh tokens. auth_tokens.token now stores SHA-256
--    hashes; rows written by the old plaintext code can never match again
--    and are a credential-at-rest liability. Users simply log in again
--    (the auth-refresh endpoint never worked against these rows anyway).
DELETE FROM `auth_tokens`;

-- 4. Purge unused plaintext password-reset tokens. The reset handler now
--    looks tokens up by SHA-256 hash, so pending plaintext tokens can never
--    match; they are 1-hour-lived anyway. Used rows are inert and kept for
--    audit history.
DELETE FROM `password_resets` WHERE `used_at` IS NULL;
