-- 2026_09_14_001_users-azure-oid.sql
--
-- Date:     2026-09-14
-- Purpose:  Link an IMS user to a Microsoft Entra ID account.
--
--           azure_oid holds the Entra `oid` claim -- the directory object id,
--           which is immutable per user per tenant. That is deliberately NOT
--           the email address: an address can be renamed, and worse, it can be
--           reassigned to a new employee, at which point matching on email
--           alone would hand that person the previous holder's IMS account.
--           Email is used once, to bootstrap the link; every sign-in after
--           that matches on this column.
--
--           NULL for every existing row and for every account that never uses
--           Microsoft sign-in, so password login is untouched. The UNIQUE key
--           is what stops one Microsoft account being linked to two IMS users;
--           MariaDB treats NULLs as distinct, so it does not constrain the
--           unlinked majority.
--
-- Tables:   users (new column + unique key)
-- Feature:  Microsoft (Entra ID) sign-in
--
-- Runnable in a single paste. Idempotent.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `azure_oid` VARCHAR(64) NULL DEFAULT NULL
  COMMENT 'Entra ID object id (oid claim); NULL = not linked to a Microsoft account';

ALTER TABLE `users`
  ADD UNIQUE KEY IF NOT EXISTS `uq_users_azure_oid` (`azure_oid`);

-- Verify (both should list the new column / key):
--   SHOW COLUMNS FROM `users` LIKE 'azure_oid';
--   SHOW INDEX FROM `users` WHERE Key_name = 'uq_users_azure_oid';
