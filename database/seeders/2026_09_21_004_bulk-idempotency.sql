-- ============================================================================
-- Date:     2026-09-21
-- Purpose:  Give bulk-add an idempotency key, so a retried request after a
--           timeout stops creating a second set of inventory rows.
-- Tables:   bulk_operation_keys (new)
-- Feature:  Backend & database audit 2026-09-21, §9.5 (Phase I.4).
-- ============================================================================
--
-- THE HAZARD THIS CLOSES
--   bulk-add loops over up to 100 items, each in its own transaction, with
--   deliberate partial-success semantics and no rollback. That is a defensible
--   design for data entry. What is not defensible is that a retry after a
--   timeout creates a SECOND set of rows and nothing can tell: every unit gets
--   its own auto-increment AssetTag, so the duplicates violate no constraint,
--   and serial-less units are explicitly normal in this system. Only a duplicate
--   SerialNumber would be caught.
--
--   It has already been managed by remembering not to do it — a previous session
--   recorded "192 CPU/RAM/storage rows loaded 2026-09-06 … nothing dedupes, so
--   never re-run either load". This replaces remembering with a constraint.
--
-- HOW IT IS USED
--   The caller sends an `idempotency_key` it generates once per logical
--   operation and reuses across retries. The handler claims (user, module, key)
--   with INSERT IGNORE: winning the race means this is the first run, losing it
--   means a previous run BY THE SAME USER ON THE SAME MODULE already owns it, and
--   its stored response is replayed instead of re-executing. A claimed-but-unfinished key (response_json IS NULL) answers
--   409 rather than running again, because the first attempt may still be
--   mid-flight.
--
--   Sending no key preserves today's behaviour exactly, so no client breaks.
--
-- THE KEY IS SCOPED PER USER AND MODULE, and that is a security property, not a
-- convenience. An idempotency_key is a string the CLIENT chooses; two clients can
-- and will choose the same one. With a single-column key, the second caller would
-- lose the claim race and be handed the FIRST caller's stored response — another
-- user's component ids, uuids and asset tags. So the uniqueness domain is
-- (user_id, module, idempotency_key): a collision between two users is not a
-- collision at all, and a replay can only ever return the caller's own answer.
--
-- The PRIMARY KEY is also what makes the claim atomic — an INSERT IGNORE, not a
-- SELECT-then-INSERT — so two concurrent retries by the SAME user cannot both win.
--
-- response_json is MEDIUMTEXT: a 100-item result with per-item asset tags runs to
-- a few tens of KB, well inside it.
--
-- Idempotent via CREATE TABLE IF NOT EXISTS. Safe to paste twice.
-- Verify with SHOW CREATE TABLE bulk_operation_keys;

CREATE TABLE IF NOT EXISTS bulk_operation_keys (
    user_id         int(11)      NOT NULL,
    module          varchar(32)  NOT NULL,
    idempotency_key varchar(100) NOT NULL,
    operation       varchar(32)  DEFAULT NULL,
    http_code       int(11)      DEFAULT NULL,
    response_json   mediumtext   DEFAULT NULL,
    created_at      timestamp    NOT NULL DEFAULT current_timestamp(),
    completed_at    timestamp    NULL DEFAULT NULL,
    PRIMARY KEY (user_id, module, idempotency_key),
    KEY ix_bok_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Housekeeping note, not a statement to run now: nothing prunes this table yet.
-- Keys older than ~30 days are of no further use, since no client retries that
-- late. ix_bok_created is here so that sweep is cheap when it is added.

-- IF YOU ALREADY RAN AN EARLIER DRAFT of this file, which had
-- PRIMARY KEY (idempotency_key) alone, run these two as well. The earlier shape
-- let one user's key collide with another's and replay their response.
--   ALTER TABLE bulk_operation_keys DROP PRIMARY KEY;
--   ALTER TABLE bulk_operation_keys ADD PRIMARY KEY (user_id, module, idempotency_key);
-- Confirmed unnecessary on production 2026-09-21: the table did not exist, so the
-- earlier shape was never applied anywhere.
