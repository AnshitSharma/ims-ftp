-- =====================================================================
-- Date:     2026-08-10
-- Purpose:  Rotate the `superadmin` account password.
-- Tables:   users (UPDATE, single row)
-- Feature:  Account maintenance
--
-- The hash below is bcrypt, $2y$ prefix, cost 10 — the format produced by
-- password_hash($pw, PASSWORD_DEFAULT) on PHP 7.4 and accepted by
-- password_verify() in authenticateUser() (core/helpers/BaseFunctions.php).
--
-- Idempotent: re-running is a no-op once the hash matches, since the WHERE
-- clause targets the username and the value is fixed.
--
-- NOTE: user `Dev` (id 46) currently carries the *same* hash superadmin had
-- before this change, i.e. that account shares the old credential. Rotate it
-- separately if that is not intended.
-- =====================================================================

START TRANSACTION;

UPDATE `users`
   SET `password` = '$2y$10$gVaHwfriR5teko1/ZcBkSe8Lkern6ABAfDajepvfR5/SMolF.xUI6',
       `password_changed_at` = NOW()
 WHERE `username` = 'superadmin';

COMMIT;

-- Verification (expect 1 row, password_changed_at = today):
--   SELECT id, username, password_changed_at FROM users WHERE username = 'superadmin';
--
-- Then confirm end-to-end via the API:
--   action=auth-login  username=superadmin  password=<the new password>
