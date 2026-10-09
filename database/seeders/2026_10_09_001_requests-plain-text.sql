-- ============================================================================
-- Date:     2026-10-09
-- Purpose:  Decode the HTML entities that request text was stored with before
--           2026-10-09, so old requests read and search like new ones.
-- Tables:   tickets (title, description, rejection_reason),
--           ticket_stage_progress (notes), data_migrations (new)
-- Feature:  Request module QA fixes 2026-10-09, QA-02.
-- ============================================================================
--
-- WHAT HAPPENED
--   PipelineManager ran htmlspecialchars() on request text BEFORE saving it,
--   and the UI escapes again when rendering, so a title typed as
--     Rahul's "new" server
--   displayed as
--     Rahul&#039;s &quot;new&quot; server
--   and searching for what was typed found nothing. Since 2026-10-09 17:23:40
--   (UTC, the database clock) text is stored as typed; this decodes what came
--   before. Only requests are touched (pipeline_template_id IS NOT NULL) —
--   PipelineManager::createPipeline() is the only writer of that text.
--
-- HOW
--   The exact inverse of htmlspecialchars(ENT_QUOTES): its five entities, with
--   &amp; LAST, so text someone literally typed as "&lt;" (stored "&amp;lt;")
--   comes back as "&lt;" and not "<".
--
--   Each column uses the timestamp that says when IT was written, so anything
--   written as plain text after the cutoff is left alone:
--     title, description   -> tickets.created_at
--     rejection_reason     -> tickets.updated_at (written on cancel / reject)
--     stage notes          -> ticket_stage_progress.updated_at
--   updated_at is assigned to itself so the list's "most recently moved" order
--   does not change.
--
--   Titles that were already CUT mid-entity by the 255-character column (#364
--   ends in "&qu") cannot be recovered; the partial entity is left as it is.
--
-- IDEMPOTENT, deliberately through a marker row and not by re-checking the
-- text: decoding twice would turn a literally typed "&lt;" into "<". The
-- updates do nothing once data_migrations holds this file's name, and the
-- whole run is one transaction, so a paste that stops part-way leaves no
-- marker and nothing half-applied. Safe to paste twice.
--
-- Verify afterwards:
--   SELECT * FROM data_migrations;
--   SELECT id, title FROM tickets WHERE id IN (357, 364);
--     357 -> QA-20261009-lifecycle "quotes" & <safe>

CREATE TABLE IF NOT EXISTS data_migrations (
    name        varchar(191) NOT NULL,
    applied_at  timestamp    NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

UPDATE tickets t
   SET t.title = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(t.title,
                     '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
       t.description = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(t.description,
                     '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
       t.updated_at = t.updated_at
 WHERE t.pipeline_template_id IS NOT NULL
   AND t.created_at < '2026-10-09 17:23:40'
   AND (t.title LIKE '%&%' OR t.description LIKE '%&%')
   AND NOT EXISTS (SELECT 1 FROM data_migrations m WHERE m.name = '2026_10_09_001_requests-plain-text');

UPDATE tickets t
   SET t.rejection_reason = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(t.rejection_reason,
                     '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
       t.updated_at = t.updated_at
 WHERE t.pipeline_template_id IS NOT NULL
   AND t.updated_at < '2026-10-09 17:23:40'
   AND t.rejection_reason LIKE '%&%'
   AND NOT EXISTS (SELECT 1 FROM data_migrations m WHERE m.name = '2026_10_09_001_requests-plain-text');

UPDATE ticket_stage_progress sp
  JOIN tickets t ON t.id = sp.ticket_id
   SET sp.notes = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(sp.notes,
                     '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
       sp.updated_at = sp.updated_at
 WHERE t.pipeline_template_id IS NOT NULL
   AND sp.updated_at < '2026-10-09 17:23:40'
   AND sp.notes LIKE '%&%'
   AND NOT EXISTS (SELECT 1 FROM data_migrations m WHERE m.name = '2026_10_09_001_requests-plain-text');

INSERT IGNORE INTO data_migrations (name) VALUES ('2026_10_09_001_requests-plain-text');

COMMIT;
