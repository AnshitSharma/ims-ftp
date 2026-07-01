-- =============================================================================
-- setup_scratch_db.sql  —  LOCAL TEST FIXTURE (NOT a production seeder)
-- =============================================================================
-- Purpose : Create the isolated, throwaway database used by the Phase 0
--           golden-master harness (tests/characterize_compatibility.php).
-- Scope   : Local developer machine only. This file MUST NOT be run against the
--           production database and does NOT live in ims-ftp/database/seeders/.
--           Phase 0 makes no production schema/data change, so it ships no seeder.
--
-- Usage (from repo root, Github IMS/):
--     mysql -u root < ims-ftp/tests/golden/setup_scratch_db.sql
--     mysql -u root ims_compat_golden < imsbdcmsbharatda_Ims_Production.sql
--     php ims-ftp/tests/characterize_compatibility.php
--
-- Idempotent: DROPs and recreates the scratch DB for a clean slate every time.
-- =============================================================================

DROP DATABASE IF EXISTS `ims_compat_golden`;
CREATE DATABASE `ims_compat_golden`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

-- Tables + data are loaded separately from the production dump (see Usage above),
-- because the dump is a phpMyAdmin export (CREATE TABLE + INSERT + a trigger using
-- DELIMITER) and must be sourced through the mysql CLI, not inlined here.
