# AGENTS.md — ims-ftp (BDC IMS backend)

**The instructions for this directory live in `CLAUDE.md`,** alongside the monorepo root
`../CLAUDE.md`. Read both before making changes. This file is a pointer so the two never
drift apart — the previous copy here had gone stale and contradicted `CLAUDE.md` on the
component-type count and the architecture.

Three things are dangerous enough to repeat here, in case you read nothing else:

1. **Every PHP file you save uploads to production within ~20s.** There is no staging and no
   local server, so a parse error is a live 500. Lint with `/c/xampp/php/php.exe -l` (php is
   not on PATH) and keep each edit individually valid.
2. **Database changes are never applied by code.** They ship as a new
   `database/seeders/YYYY_MM_DD_NNN_*.sql` file that a human runs by hand. Never edit an
   existing seeder, and never use `information_schema` in one — the production DB user is
   denied it and the guard fails open, reporting success while changing nothing. Because
   code deploys before the seeder runs, anything referencing a new column must tolerate that
   column not existing yet.
3. **A new API action is not done** until it is registered in `api/permission_map.php`
   (unmapped operations are rejected), consumed by the frontend, and has a seeder for any ACL
   rows it needs.
