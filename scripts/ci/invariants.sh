#!/bin/sh
# invariants.sh — U-P.1. The architectural invariants, enforced by a machine.
#
# There is no .github/ and no .gitlab-ci.yml in this repo (checked 2026-08-24),
# so per the pack this is the CI-agnostic form: a POSIX sh script any runner can
# call in one line. GitHub Actions, GitLab, a cPanel cron, or a laptop — all the
# same command:
#
#     sh ims-ftp/scripts/ci/invariants.sh
#
# WHAT IT RUNS
#   1. Every CHECK the invariants document defines, VERBATIM. Not a copy of them:
#      scripts/ci/inv_extract.php parses migration/ARCHITECTURAL_INVARIANTS.md at
#      run time and hands the command text over unmodified. Editing the document
#      changes what this script enforces, immediately, with no code change — and
#      an edit into a shape the parser cannot execute turns the run RED rather
#      than silently dropping the check. Read that file's header for the parse
#      rules and for the single interpolation it performs.
#   2. scripts/verify/run_all.php --quick against the scratch DB.
#   3. Every suite in tests/regression/ and tests/unit/rules/.
#
# EXIT
#   0  GREEN — every gating check passed.
#   1  RED   — at least one gating check failed.
#   2  Could not run (bad environment, unparseable invariants document). Never
#      confused with 0: "we did not check" is not "it passed". That distinction
#      is this repo's most expensive recurring bug (F-10, F-18, F-21, F-23).
#
# ENVIRONMENT
#   PHP_BIN            php binary                        (default: php)
#   MYSQL_BIN          mysql client                      (default: mysql)
#   GOLDEN_DB_HOST     scratch DB host                   (default: 127.0.0.1)
#   GOLDEN_DB_NAME     scratch DB name                   (default: ims_compat_golden)
#   GOLDEN_DB_USER     scratch DB user                   (default: root)
#   GOLDEN_DB_PASS     scratch DB password               (default: empty)
#   GOLDEN_DB_PASS_FILE  file containing the password. Honoured here for BOTH the
#                      mysql client and the PHP suites: this script exports
#                      GOLDEN_DB_PASS from it when GOLDEN_DB_PASS is unset. That
#                      is a workaround at the CI boundary, not the fix — only
#                      tests/regression/serial_less_unit_identity_test.php reads
#                      the file form itself, so anyone running the suites WITHOUT
#                      this script still needs both variables. See migration/BACKLOG.md.
#   IMS_DATA_PATH      absolute path to the ims-data component-spec tree
#   IMS_CI_REBUILD_DB=1        rebuild the scratch DB before running (see below)
#   IMS_CI_ALLOW_NONSCRATCH=1  permit a DB name that does not look like a scratch DB
#
# THE SCRATCH DB
#   The pack says run_all.php --quick runs "against a scratch DB built from
#   tests/golden/setup_scratch_db.sql". That file DROPs and recreates the
#   database, and the tables/data come separately from the repo-root production
#   dump. Rebuilding is therefore destructive, so it is opt-in
#   (--rebuild-db / IMS_CI_REBUILD_DB=1) — a fresh CI runner wants it, a laptop
#   with a provisioned fixture does not. Without it the existing DB is used and
#   its presence is verified, never assumed.
#
#   run_all.php's child reports connect through core/config/app.php, which reads
#   DB_HOST/DB_USER/DB_PASS/DB_NAME — a DIFFERENT set of names from the suites'
#   GOLDEN_DB_*. When DB_NAME is unset this script derives the DB_* set from
#   GOLDEN_DB_*, so a CI job configures one set and nothing quietly falls back to
#   the .env on disk, which points at PRODUCTION. If DB_NAME is set to something
#   that does not look like a scratch database, the run aborts.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
PHP="${PHP_BIN:-php}"
MYSQL="${MYSQL_BIN:-mysql}"
REBUILD="${IMS_CI_REBUILD_DB:-0}"

for arg in "$@"; do
    case "$arg" in
        --rebuild-db) REBUILD=1 ;;
        -h|--help) sed -n '2,70p' "$0"; exit 0 ;;
        *) echo "invariants: unknown argument '$arg'" >&2; exit 2 ;;
    esac
done

if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "invariants: php not found (set PHP_BIN to its full path)" >&2
    exit 2
fi

# INV-8's CHECK is the literal string `php scripts/verify/equivalence_report.php
# --all`. It is run verbatim, which means a bare `php` has to resolve — so when
# PHP_BIN points somewhere off PATH, put its directory ON the PATH rather than
# rewriting the document's command. Changing the environment to suit the check
# is legitimate; changing the check to suit the environment is the drift this
# unit exists to prevent.
case "$PHP" in
    */*) PHP_DIR=$(CDPATH= cd -- "$(dirname -- "$PHP")" && pwd)
         PATH="$PHP_DIR:$PATH"; export PATH ;;
esac

# --- credentials ------------------------------------------------------------
GOLDEN_DB_HOST="${GOLDEN_DB_HOST:-127.0.0.1}"
GOLDEN_DB_NAME="${GOLDEN_DB_NAME:-ims_compat_golden}"
GOLDEN_DB_USER="${GOLDEN_DB_USER:-root}"
if [ -z "${GOLDEN_DB_PASS:-}" ] && [ -n "${GOLDEN_DB_PASS_FILE:-}" ] && [ -r "${GOLDEN_DB_PASS_FILE}" ]; then
    GOLDEN_DB_PASS=$(cat "${GOLDEN_DB_PASS_FILE}")
fi
GOLDEN_DB_PASS="${GOLDEN_DB_PASS:-}"
export GOLDEN_DB_HOST GOLDEN_DB_NAME GOLDEN_DB_USER GOLDEN_DB_PASS

# The app-side names run_all.php's children use. Derived, never guessed.
if [ -z "${DB_NAME:-}" ]; then
    DB_HOST="$GOLDEN_DB_HOST"; DB_NAME="$GOLDEN_DB_NAME"
    DB_USER="$GOLDEN_DB_USER"; DB_PASS="$GOLDEN_DB_PASS"
    export DB_HOST DB_NAME DB_USER DB_PASS
fi
case "$DB_NAME" in
    *golden*|*scratch*|*test*) ;;
    *)
        if [ "${IMS_CI_ALLOW_NONSCRATCH:-0}" != "1" ]; then
            echo "invariants: DB_NAME='$DB_NAME' does not look like a scratch database." >&2
            echo "            Refusing to run destructive checks against it. Set" >&2
            echo "            IMS_CI_ALLOW_NONSCRATCH=1 only if you are certain." >&2
            exit 2
        fi
        ;;
esac

# MYSQL_PWD keeps the password out of argv (and therefore out of ps and any CI
# log that echoes the command line).
if [ -n "$GOLDEN_DB_PASS" ]; then MYSQL_PWD="$GOLDEN_DB_PASS"; export MYSQL_PWD; fi

mysql_q() {  # mysql_q <sql>  -> tab-separated rows, no header
    "$MYSQL" -h "$GOLDEN_DB_HOST" -u "$GOLDEN_DB_USER" -N -B -D "$GOLDEN_DB_NAME" -e "$1"
}

echo "================================================================"
echo " invariants.sh — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo " repo : $ROOT"
echo " db   : $GOLDEN_DB_USER@$GOLDEN_DB_HOST/$GOLDEN_DB_NAME"
echo "================================================================"

# --- optional scratch DB rebuild -------------------------------------------
if [ "$REBUILD" = "1" ]; then
    DUMP="$ROOT/../imsbdcmsbharatda_Ims_Production.sql"
    SETUP="$ROOT/tests/golden/setup_scratch_db.sql"
    echo
    echo "--- rebuilding scratch DB from tests/golden/setup_scratch_db.sql ---"
    if ! command -v "$MYSQL" >/dev/null 2>&1; then
        echo "invariants: mysql client not found (set MYSQL_BIN)" >&2; exit 2
    fi
    [ -f "$SETUP" ] || { echo "invariants: missing $SETUP" >&2; exit 2; }
    [ -f "$DUMP" ]  || { echo "invariants: missing repo-root production dump" >&2; exit 2; }
    "$MYSQL" -h "$GOLDEN_DB_HOST" -u "$GOLDEN_DB_USER" < "$SETUP" || {
        echo "invariants: setup_scratch_db.sql failed" >&2; exit 2; }
    "$MYSQL" -h "$GOLDEN_DB_HOST" -u "$GOLDEN_DB_USER" "$GOLDEN_DB_NAME" < "$DUMP" || {
        echo "invariants: loading the dump failed" >&2; exit 2; }
    echo "rebuilt."
fi

# The DB must be reachable BEFORE anything runs. A missing DB used to make
# several suites print "ALL CHECKS PASS" and exit 0; the runner is not allowed
# to inherit that.
if command -v "$MYSQL" >/dev/null 2>&1; then
    if ! mysql_q "SELECT 1" >/dev/null 2>&1; then
        echo "invariants: cannot reach $GOLDEN_DB_NAME on $GOLDEN_DB_HOST." >&2
        echo "            The DB-backed checks cannot run and will not be reported as passing." >&2
        exit 2
    fi
else
    echo "invariants: mysql client not found (set MYSQL_BIN) — SQL invariants cannot run." >&2
    exit 2
fi

WORK=$(mktemp -d 2>/dev/null || echo "${TMPDIR:-/tmp}/invariants.$$")
mkdir -p "$WORK"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT INT TERM

RED=0
INFO_FAIL=0
MANUAL_LIST=""

# ---------------------------------------------------------------------------
# 1. The invariant CHECK blocks, extracted verbatim from the document.
# ---------------------------------------------------------------------------
echo
echo "--- 1/3  ARCHITECTURAL_INVARIANTS.md (extracted verbatim) ---"
if ! "$PHP" "$ROOT/scripts/ci/inv_extract.php" --outdir "$WORK/inv"; then
    echo "invariants: could not extract checks from the invariants document." >&2
    exit 2
fi

# Records are 0x1F-separated, not tab-separated — see inv_extract.php's emit
# step for why (tab is IFS whitespace, so `read` swallows empty fields and every
# later field shifts left).
US=$(printf '\037')
while IFS="$US" read -r INV SEQ KIND ASSERT GATING NOTE CMDFILE; do
    [ -n "${INV:-}" ] || continue
    LABEL="$INV/$SEQ"

    if [ "$GATING" = "manual" ]; then
        MANUAL_LIST="$MANUAL_LIST
  $INV  $NOTE"
        printf '  %-10s %-8s %s\n' "$LABEL" "MANUAL" "$NOTE"
        continue
    fi

    CMD=$(cat "$CMDFILE")
    OUT=""
    RC=0
    case "$KIND" in
        sh)
            OUT=$(cd "$ROOT" && sh -c "$CMD" 2>"$WORK/err") || RC=$?
            ;;
        sql)
            OUT=$(mysql_q "$CMD" 2>"$WORK/err") || RC=$?
            ;;
        exit0)
            # The document names the artifact; a bare .php path is launched with
            # the PHP binary. This is the only text this runner adds (see
            # inv_extract.php's header).
            case "$CMD" in
                *.php) RUN="$PHP $CMD" ;;
                *)     RUN="$CMD" ;;
            esac
            OUT=$(cd "$ROOT" && sh -c "$RUN" 2>"$WORK/err") || RC=$?
            ;;
        *)
            printf '  %-10s %-8s unknown check kind "%s"\n' "$LABEL" "ERROR" "$KIND"
            RED=1
            continue
            ;;
    esac

    VERDICT=PASS
    case "$ASSERT" in
        empty) [ -z "$OUT" ] || VERDICT=FAIL ;;
        exit0) [ "$RC" -eq 0 ] || VERDICT=FAIL ;;
        *)     VERDICT=UNENFORCED ;;
    esac

    if [ "$VERDICT" = "PASS" ]; then
        printf '  %-10s %-8s %s\n' "$LABEL" "PASS" "$([ "$GATING" = info ] && echo "(informational) $NOTE" || echo "$NOTE")"
    else
        if [ "$GATING" = "info" ]; then
            printf '  %-10s %-8s %s\n' "$LABEL" "info-RED" "$NOTE"
            INFO_FAIL=$((INFO_FAIL + 1))
        else
            printf '  %-10s %-8s (gating) %s\n' "$LABEL" "$VERDICT" "$NOTE"
            RED=1
        fi
        echo "      command: $CMD" | sed 's/$//'
        if [ -n "$OUT" ]; then
            echo "$OUT" | sed 's/^/      | /'
        fi
        if [ -s "$WORK/err" ]; then
            sed 's/^/      ! /' "$WORK/err"
        fi
    fi
done < "$WORK/inv/manifest.tsv"

# ---------------------------------------------------------------------------
# 2. run_all.php --quick against the scratch DB.
# ---------------------------------------------------------------------------
echo
echo "--- 2/3  scripts/verify/run_all.php --quick ---"
QRC=0
# Not a pipe: a pipeline would report sed's status, not run_all's, and this
# runner must never mistake "the formatter succeeded" for "the battery passed".
(cd "$ROOT" && "$PHP" scripts/verify/run_all.php --quick) >"$WORK/quick.out" 2>&1 || QRC=$?
sed 's/^/  /' "$WORK/quick.out"
if [ "$QRC" -ne 0 ]; then
    echo "  run_all --quick: RED"
    RED=1
else
    echo "  run_all --quick: GREEN"
fi

# ---------------------------------------------------------------------------
# 3. tests/regression/ and tests/unit/rules/.
# ---------------------------------------------------------------------------
echo
echo "--- 3/3  tests/regression/ + tests/unit/rules/ ---"
SUITES=0; PASSED=0; FAILED=0; NOTHING=0
for f in "$ROOT"/tests/regression/*.php "$ROOT"/tests/unit/rules/*.php; do
    [ -f "$f" ] || continue
    b=$(basename "$f")
    # Same exclusions tests/run_tests.php applies: shared helpers (_*.php) and
    # the single-purpose probe that is not a suite.
    case "$b" in _*|run_serial_less_check.php) continue ;; esac
    SUITES=$((SUITES + 1))
    SRC=0
    SOUT=$(cd "$ROOT" && "$PHP" "$f" 2>&1) || SRC=$?
    # "Exited 0 having run nothing" is its own outcome and is NOT a pass.
    case "$SOUT" in
        *"SKIPPED: 0 check(s) run"*)
            printf '  %-46s %s\n' "$b" "RAN NOTHING"
            NOTHING=$((NOTHING + 1)); RED=1; continue ;;
    esac
    if [ "$SRC" -eq 0 ]; then
        printf '  %-46s %s\n' "$b" "pass"
        PASSED=$((PASSED + 1))
    else
        printf '  %-46s %s\n' "$b" "FAILED (exit $SRC)"
        echo "$SOUT" | tail -n 15 | sed 's/^/      | /'
        FAILED=$((FAILED + 1)); RED=1
    fi
done
if [ "$SUITES" -eq 0 ]; then
    echo "  discovered NO suites — refusing to call that green" >&2
    exit 2
fi
echo "  $SUITES suite(s): $PASSED passed, $FAILED failed, $NOTHING ran nothing"

# ---------------------------------------------------------------------------
# Summary.
# ---------------------------------------------------------------------------
echo
echo "================================================================"
if [ -n "$MANUAL_LIST" ]; then
    echo " NOT MECHANICALLY ENFORCED — a human still has to check these:$MANUAL_LIST"
    echo " (this list comes from the document, not from a list kept here;"
    echo "  see migration/BACKLOG.md for the amendment that would shrink it)"
    echo "----------------------------------------------------------------"
fi
if [ "$INFO_FAIL" -gt 0 ]; then
    echo " $INFO_FAIL informational check(s) are RED. They do not gate yet because"
    echo " the document conditions them on a unit that is not verified. They will"
    echo " gate the moment it is."
    echo "----------------------------------------------------------------"
fi
if [ "$RED" -eq 0 ]; then
    echo " RESULT: GREEN"
else
    echo " RESULT: RED"
fi
echo "================================================================"
exit "$RED"
