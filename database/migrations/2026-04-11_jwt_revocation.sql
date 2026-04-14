-- =============================================================================
-- Migration: 2026-04-11_jwt_revocation.sql
-- Purpose:   Enable real JWT access-token revocation.
--
-- Previously, handleLogout() and handleResetPassword() only deleted rows
-- from auth_tokens (which stores refresh tokens). Any already-issued access
-- JWT remained valid until its natural expiry because verifyToken() never
-- consulted the database. This migration adds the backing store needed to
-- reject access tokens on logout AND to force-invalidate every still-valid
-- token a user holds after a password reset.
--
-- Two mechanisms:
--   1. revoked_tokens  : per-token blacklist keyed on the JWT's `jti` claim.
--                        Used by handleLogout() for precise, targeted revoke.
--   2. users.password_changed_at : wall-clock cutoff. verifyToken() rejects
--                        any token whose `iat` predates this value. Used by
--                        handleResetPassword() to nuke every outstanding
--                        token for the user in one atomic update.
-- =============================================================================

-- --- 1. Per-token blacklist ---------------------------------------------------
CREATE TABLE IF NOT EXISTS revoked_tokens (
    jti         VARCHAR(64)  NOT NULL,
    user_id     INT          NOT NULL,
    revoked_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME     NOT NULL,
    PRIMARY KEY (jti),
    KEY idx_revoked_user (user_id),
    KEY idx_revoked_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- 2. Global cutoff per user for bulk revocation ----------------------------
-- NULL means "no cutoff" (never reset). When set, every token issued before
-- this timestamp is treated as revoked.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL DEFAULT NULL
    AFTER password;
