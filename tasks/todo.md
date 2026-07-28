# Git Push/Merge Issue Resolution Plan

Goal: Safely merge remote `origin/main` changes into local `main`, resolve file & seeder filename conflicts, preserve all local work, and successfully push to `origin/main`.

## Tasks

- [x] **Phase 1: Preparation & Conflict Prevention**
  - [x] Rename conflicting local seeder `2026_07_27_001_revert-019eca1d-partial-application.sql` to avoid collision with remote `2026_07_27_001_revoke-wildcard-user-role-write-grants.sql`
  - [x] Stage and commit current working tree changes into a temporary/local commit on `main` to ensure zero work is lost during merge

- [x] **Phase 2: Remote Integration & Conflict Resolution**
  - [x] Fetch and merge `origin/main` into local `main`
  - [x] Inspect and resolve any merge conflicts in `ServerBuilder.php`, `JWTAuthFunctions.php`, `api/handlers/server/server_api.php`, or other overlapping files
  - [x] Verify clean merge state with `git status`

- [x] **Phase 3: Verification & Push**
  - [x] Run PHP tests (`tests/state_machine_unit.php`, `tests/regression/replace_command_test.php`, etc.) to confirm parity and sanity
  - [x] Push local `main` to `origin/main`
  - [x] Verify `git status` shows branch is up to date with `origin/main` and working tree clean


