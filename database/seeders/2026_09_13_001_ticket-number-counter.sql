-- 2026_09_13_001_ticket-number-counter.sql
--
-- Date:     2026-09-13
-- Purpose:  Give ticket numbers a real day-scoped counter and make duplicates
--           impossible at the database level. [M-14]
--
--           PipelineManager::generateTicketNumber() read
--           MAX(CAST(SUBSTRING(ticket_number, -4) AS UNSIGNED)) and added one.
--           Two people raising a request in the same moment read the same
--           maximum, were handed the same number, and both rows were written --
--           so two different requests answered to one TKT-YYYYMMDD-NNNN and
--           every later reference to it was ambiguous. Reading only the last
--           four characters also meant that past 9999 in a day it read '9999'
--           out of '...-10000' and proposed 10000 for ever.
--
--           ticket_number_counters is claimed atomically with
--           INSERT ... ON DUPLICATE KEY UPDATE next_seq = LAST_INSERT_ID(next_seq + 1),
--           which holds the row lock to commit and returns a number that is
--           nobody else's. The UNIQUE index is the backstop: any path that
--           somehow proposes a number twice is refused by MariaDB rather than
--           quietly duplicating.
--
-- Tables:   ticket_number_counters (new), tickets (new unique index)
-- Feature:  Requests / pipelines
--
-- Runnable in a single paste. Idempotent.

CREATE TABLE IF NOT EXISTS `ticket_number_counters` (
  `counter_date` DATE NOT NULL,
  `next_seq` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`counter_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Start each existing day at the highest number already issued for it, so the
-- counter never re-proposes a number that is already on a ticket. Read from the
-- part of the string AFTER the prefix, not the last four characters.
INSERT INTO `ticket_number_counters` (`counter_date`, `next_seq`)
SELECT
  STR_TO_DATE(SUBSTRING(`ticket_number`, 5, 8), '%Y%m%d') AS `counter_date`,
  MAX(CAST(SUBSTRING(`ticket_number`, 14) AS UNSIGNED))   AS `next_seq`
FROM `tickets`
WHERE `ticket_number` LIKE 'TKT-________-%'
GROUP BY STR_TO_DATE(SUBSTRING(`ticket_number`, 5, 8), '%Y%m%d')
ON DUPLICATE KEY UPDATE
  `next_seq` = GREATEST(`ticket_number_counters`.`next_seq`, VALUES(`next_seq`));

-- MariaDB 10.11 supports IF NOT EXISTS on ADD UNIQUE INDEX, so this is safe to
-- re-run. If it fails with "Duplicate entry", two tickets already share a
-- number: find them with the query in the comment below, renumber the later
-- one by hand, then re-run this file.
--
--   SELECT ticket_number, COUNT(*) c FROM tickets
--    GROUP BY ticket_number HAVING c > 1;
ALTER TABLE `tickets`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_tickets_ticket_number` (`ticket_number`);
