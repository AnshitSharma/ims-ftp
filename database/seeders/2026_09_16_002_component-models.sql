-- ============================================================================
-- Date:     2026-09-16
-- Purpose:  Make the model layer visible to the database. Today a "model" exists only as a
--           node inside a hand-edited JSON file; the DB knows only serial-numbered units.
--           This table is the projection of ims-data that SQL can index, join and search.
-- Tables:   component_models (new)
-- Feature:  JSON & data-model audit, Phase 2.1 -- the keystone. JSON-001, -003, -010, -011,
--           -014 all reduce to "there is no model table" and are unblocked by this one.
-- ============================================================================
--
-- THIS TABLE IS DERIVED. NEVER HAND-EDIT IT.
--   ims-data/*.json stays the authoring format -- git-tracked, diffable, reviewable. That
--   separation is deliberate and every phase of the remediation preserves it. This table is
--   a rebuildable index over those files, nothing more. It is populated by:
--
--       php ims-ftp/database/spec_build.php
--
--   which validates, indexes, checksums and upserts. Run it after every ims-data upload.
--   Rows whose source spec has vanished are marked retired, never deleted, because
--   inventory rows may still point at them.
--
-- WHY THE PROJECTION COLUMNS ARE THE ONES THEY ARE
--   Each is a field that some query needs to filter or sort on, lifted out of the JSON body
--   so it can carry an index. They are DERIVED COPIES: `specs` holds the model object
--   verbatim and is the source of truth for everything else. A projection column that
--   disagrees with `specs` is a spec_build bug, not a data edit.
--
--   The corpus is inconsistent in ways that made these necessary (surveyed 2026-09-16 across
--   all 12 files, 378 model objects):
--     - the uuid key is `uuid` on 8 types and `UUID` on cpu/pciecard/risercard/hbacard
--     - the model-name key is `model` on 10 types, `label` on ram (ram has no `model` at all),
--       and present on only 46 of 67 storage records
--     - brand lives on the PARENT group, not the model -- and is called `manufacturer` on
--       chassis and is absent entirely on caddy
--   spec_build resolves all of that once, here, instead of at 5 resolvers and 23 rules.
--
-- NULL IS MEANINGFUL: it means "this type does not have this attribute" (a caddy has no
-- socket) or "this record omits it". Neither is an error. Do not default these to ''.
--
-- manufacturer_id / family_id are Phase 3 and are deliberately ABSENT rather than present
-- and unused -- a nullable column nothing writes is indistinguishable from a bug.
--
-- SAFE TO RUN REPEATEDLY. Creates nothing twice, drops nothing, rewrites no rows.
--
-- Guarded with MariaDB's own IF NOT EXISTS clauses only, and verified with SHOW COLUMNS /
-- SHOW INDEX, per the house rule for this directory.
-- ============================================================================

CREATE TABLE IF NOT EXISTS component_models (
    id BIGINT NOT NULL AUTO_INCREMENT,

    -- Identity. spec_uuid is the authoring key and the ONLY join to {type}inventory.
    -- UNIQUE is the point of the table: it is the constraint ims-data cannot express, and
    -- the absence of it is how two live collisions survived until the audit.
    spec_uuid CHAR(36) NOT NULL,
    component_type VARCHAR(20) NOT NULL COMMENT 'cpu|ram|storage|motherboard|nic|caddy|chassis|pciecard|risercard|hbacard|sfp|serverplatform',

    -- Naming. model_name is the raw catalogue name; display_name is the resolved
    -- brand+model string the UI shows, built by the same rules as ComponentNamer so the
    -- list endpoints and the model picker cannot disagree.
    model_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(320) DEFAULT NULL,
    brand VARCHAR(100) DEFAULT NULL COMMENT 'from the parent group; `manufacturer` on chassis, absent on caddy',
    series VARCHAR(120) DEFAULT NULL,
    part_number VARCHAR(100) DEFAULT NULL,

    -- Indexed projections. Every one of these is a copy of a value inside `specs`.
    capacity_gb INT DEFAULT NULL COMMENT 'ram capacity_GB, storage capacity_GB',
    socket VARCHAR(60) DEFAULT NULL COMMENT 'cpu, motherboard',
    form_factor VARCHAR(60) DEFAULT NULL,
    interface VARCHAR(60) DEFAULT NULL COMMENT 'storage, hbacard, pciecard, riser, nic',
    memory_type VARCHAR(40) DEFAULT NULL COMMENT 'ram memory_type',
    tdp_w INT DEFAULT NULL COMMENT 'cpu tdp_W',
    ports INT DEFAULT NULL COMMENT 'nic port count',

    -- The body, verbatim. Source of truth for anything not projected above.
    -- LONGTEXT, not JSON: MariaDB's JSON is an alias for LONGTEXT with a validity CHECK, and
    -- the CHECK would make spec_build fail on a malformed body at INSERT time rather than at
    -- validation time, where the error can name the file and the record.
    specs LONGTEXT NOT NULL,

    -- Staleness detection. source_checksum is over the model object alone, so an unrelated
    -- edit elsewhere in the file does not churn every row. source_file records which file it
    -- came from, for the "table is stale vs. files" check.
    source_checksum CHAR(40) NOT NULL COMMENT 'sha1 of the canonicalised model object',
    source_file VARCHAR(120) NOT NULL,

    -- Rows are retired, never deleted: inventory may still reference a withdrawn model, and
    -- a dangling join is a worse outcome than a row flagged gone.
    is_retired TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id),
    UNIQUE KEY uq_spec_uuid (spec_uuid),
    KEY idx_type (component_type),
    KEY idx_type_retired (component_type, is_retired),
    KEY idx_model_name (model_name),
    KEY idx_part_number (part_number),
    KEY idx_brand (brand),
    KEY idx_type_capacity (component_type, capacity_gb),
    KEY idx_type_socket (component_type, socket),
    KEY idx_type_form_factor (component_type, form_factor),
    KEY idx_checksum (source_checksum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FULLTEXT is what makes JSON-011 (search that reaches brand, model and part number) a join
-- instead of twelve sequential scans merged in PHP. It is a separate statement so that a
-- table created by an earlier partial run also picks it up; IF NOT EXISTS makes it a no-op
-- when CREATE TABLE above already built it. Verified on MariaDB 10.4 and 10.11.
ALTER TABLE component_models
    ADD FULLTEXT KEY IF NOT EXISTS ft_model_search (model_name, display_name, brand, part_number);

-- Verification (run by hand):
--   SHOW COLUMNS FROM component_models;
--   SHOW INDEX FROM component_models WHERE Key_name = 'uq_spec_uuid';
--   SHOW INDEX FROM component_models WHERE Key_name = 'ft_model_search';
--   SELECT component_type, COUNT(*) FROM component_models GROUP BY component_type;
-- After running spec_build.php the last query should total 378 rows across 12 types.
