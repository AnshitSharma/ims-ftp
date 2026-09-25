-- ============================================================================
-- Date:     2026-09-24
-- Purpose:  Record public and private IP addresses against a server. Records
--           only: nothing allocates, routes or checks these across servers.
-- Tables:   server_ip_addresses (new)
-- Feature:  Server IP addresses -- tasks/server-ip-addresses.md
-- ============================================================================
--
-- One row per address, so a server can carry any number (public, private LAN,
-- iDRAC/iLO management). Written by server-update-ips, read by
-- server-list-configs / server-get-config, searched by server-search-by-serial.
-- Gated by the existing server.edit_details permission, so no ACL rows here.
--
-- No foreign key to server_configurations on purpose: server-delete-config
-- removes a server's rows itself, and the code tolerates this table not
-- existing yet (it deploys before this seeder is run).
--
-- Idempotent: safe to run more than once.

CREATE TABLE IF NOT EXISTS server_ip_addresses (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    config_uuid VARCHAR(64)  NOT NULL,
    ip_address  VARCHAR(45)  NOT NULL,
    ip_type     ENUM('public', 'private') NOT NULL,
    label       VARCHAR(100) NULL,
    created_by  INT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_server_ip (config_uuid, ip_address),
    KEY idx_server_ip_address (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
