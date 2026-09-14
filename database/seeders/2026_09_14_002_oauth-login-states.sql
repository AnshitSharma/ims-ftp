-- 2026_09_14_002_oauth-login-states.sql
--
-- Date:     2026-09-14
-- Purpose:  Server-side memory for an in-flight Microsoft sign-in.
--
--           The authorization-code + PKCE flow needs three secrets to survive
--           the round trip through the user's browser without travelling in it:
--
--             code_verifier -- proves the callback came from the same server
--                              that started the flow, so an authorization code
--                              lifted out of the address bar is inert.
--             nonce         -- ties the returned id_token to THIS attempt, so
--                              a token minted for another session cannot be
--                              replayed into it.
--             state         -- the handle the browser carries back. Only its
--                              SHA-256 hash is stored, so a leaked table cannot
--                              be used to resume somebody's sign-in.
--
--           Single use: the callback flips used_at with a conditional UPDATE
--           and refuses if it affects no row -- the same arbitration
--           password_resets uses. Rows expire after 10 minutes and are swept
--           opportunistically by the next auth-microsoft_start.
--
--           Nothing here is a session or a credential for IMS itself; a row is
--           worthless once used_at is set.
--
-- Tables:   oauth_login_states (new)
-- Feature:  Microsoft (Entra ID) sign-in
--
-- Runnable in a single paste. Idempotent.

CREATE TABLE IF NOT EXISTS `oauth_login_states` (
  `state_hash`    CHAR(64)     NOT NULL COMMENT 'SHA-256 of the state value handed to the browser',
  `code_verifier` VARCHAR(128) NOT NULL COMMENT 'PKCE verifier; never leaves the server',
  `nonce`         VARCHAR(128) NOT NULL COMMENT 'Expected id_token nonce claim',
  `client_ip`     VARCHAR(45)      NULL DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`    DATETIME     NOT NULL,
  `used_at`       DATETIME         NULL DEFAULT NULL,
  PRIMARY KEY (`state_hash`),
  KEY `idx_oauth_states_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Verify:
--   SHOW COLUMNS FROM `oauth_login_states`;
