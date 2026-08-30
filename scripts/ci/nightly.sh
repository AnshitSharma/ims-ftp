#!/bin/sh
# nightly.sh — the daily archived battery run.
#
# This is the cron U-X.2 step 2 requires, and the SAME archive is the 30-day
# evidence U-D.3 requires (U-X.2: "this cron is the 30-day evidence U-D.3
# requires -- F-4"). Steps 4, 6 and 7 of the cutover all rest on this one file
# running once a day and leaving a durable record behind.
#
# U-P.1 (2026-08-24) formalized it: the run is now the FULL battery — the
# verification reports AND the architectural invariants — and a non-GREEN result
# fires an alert hook instead of relying on someone reading cron mail.
#
# It writes ONE json record per UTC day to reports/archive/battery-<date>.json,
# plus the invariant runner's full output alongside it at
# reports/archive/invariants-<date>.log.
# The archive is a plain per-day record; soak_status.php, which used to count
# GREEN days out of it to certify a migration soak, was deleted 2026-08-31 along
# with the soaks. The records are still worth writing -- they are the only
# history of whether the battery was green on a given day.
#
# Usage:
#     sh scripts/ci/nightly.sh            # --quick (the daily run)
#     sh scripts/ci/nightly.sh --gate P8  # a fuller battery (the weekly run)
#
# Exit: 0 if the battery was GREEN, 1 if RED, 2 if it could not run at all.
# A cron that mails on non-zero gives you alert-on-red for free.
#
# ALERT-ON-RED HOOK
#   Set IMS_ALERT_HOOK to a command. On any non-GREEN day it is invoked as:
#       "$IMS_ALERT_HOOK" <status> <record-path>
#   with a one-line human summary on its stdin. Anything works — `mail -s`, a
#   curl to a webhook, a script that opens a ticket. It is called at most once
#   per run, its own failure is logged and never masks the battery's verdict,
#   and it is NEVER called on a GREEN day.
#   With IMS_ALERT_HOOK unset the summary goes to stderr, which is what makes a
#   plain cron entry mail it. That is the stub, and it is a working default
#   rather than a placeholder.
#
# WHY THE INVARIANTS RUN HERE TOO
#   A day's "status" used to mean "the verification reports passed" and said
#   nothing at all about the architectural invariants — so a tree could read
#   GREEN for a month while violating INV-5. The record carries run_all_status
#   and invariants_status separately, and "status" is GREEN only when BOTH are.
#   That is still the right shape now that nothing counts streaks: one word per
#   day that is only green when the whole tree is.
#
# Deliberately POSIX sh and dependency-free: this has to run from cron on shared
# hosting (cPanel "Cron Jobs"), from a laptop, or from a CI runner, unchanged.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
ARCHIVE_DIR="$ROOT/reports/archive"
PHP="${PHP_BIN:-php}"

# Default to the quick battery; pass through anything the caller gave us.
if [ "$#" -eq 0 ]; then
    set -- --quick
fi

if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "nightly: php not found (set PHP_BIN to its full path, e.g. PHP_BIN=/usr/local/bin/php)" >&2
    exit 2
fi

mkdir -p "$ARCHIVE_DIR"

STAMP=$(date -u +%Y-%m-%dT%H:%M:%SZ)
DAY=$(date -u +%Y%m%d)
OUT="$ARCHIVE_DIR/battery-$DAY.json"
INV_LOG="$ARCHIVE_DIR/invariants-$DAY.log"

# --- 1. the verification reports -------------------------------------------
# Capture stdout+stderr and the exit code without set -e killing us mid-run.
RAW=$("$PHP" "$ROOT/scripts/verify/run_all.php" "$@" 2>&1) && CODE=0 || CODE=$?

case "$CODE" in
    0) RUN_ALL_STATUS=GREEN ;;
    1) RUN_ALL_STATUS=RED ;;
    *) RUN_ALL_STATUS=ERROR ;;
esac

# --- 2. the architectural invariants ----------------------------------------
# Every INV CHECK, extracted verbatim from migration/ARCHITECTURAL_INVARIANTS.md
# at run time. See scripts/ci/invariants.sh.
INV_CODE=0
sh "$ROOT/scripts/ci/invariants.sh" >"$INV_LOG" 2>&1 || INV_CODE=$?
case "$INV_CODE" in
    0) INV_STATUS=GREEN ;;
    1) INV_STATUS=RED ;;
    *) INV_STATUS=ERROR ;;
esac

# --- 3. the day's single verdict --------------------------------------------
if [ "$RUN_ALL_STATUS" = GREEN ] && [ "$INV_STATUS" = GREEN ]; then
    STATUS=GREEN
    EXIT=0
elif [ "$RUN_ALL_STATUS" = ERROR ] || [ "$INV_STATUS" = ERROR ]; then
    # "Could not run" is not "failed" and neither of them is "passed".
    STATUS=ERROR
    EXIT=2
else
    STATUS=RED
    EXIT=1
fi

# Escape the captured output for embedding as a JSON string. Doing this in php
# avoids hand-rolling escaping in sh, and php is already a hard dependency here.
ESCAPED=$(printf '%s' "$RAW" | "$PHP" -r 'echo json_encode(stream_get_contents(STDIN));' 2>/dev/null || printf '""')
ARGS_JSON=$(printf '%s' "$*" | "$PHP" -r 'echo json_encode(stream_get_contents(STDIN));' 2>/dev/null || printf '""')
INV_TAIL=$(tail -n 40 "$INV_LOG" 2>/dev/null || printf '')
INV_ESCAPED=$(printf '%s' "$INV_TAIL" | "$PHP" -r 'echo json_encode(stream_get_contents(STDIN));' 2>/dev/null || printf '""')

# A day that already has a record is OVERWRITTEN, not appended: the archive
# counts DAYS, and two records for one day would inflate a streak the same way
# F-8's duplicate rows inflated parity's denominator. One day, one verdict.
cat > "$OUT" <<JSON
{
    "run_at": "$STAMP",
    "day": "$DAY",
    "args": $ARGS_JSON,
    "status": "$STATUS",
    "exit_code": $EXIT,
    "run_all_status": "$RUN_ALL_STATUS",
    "run_all_exit_code": $CODE,
    "invariants_status": "$INV_STATUS",
    "invariants_exit_code": $INV_CODE,
    "invariants_log": "reports/archive/invariants-$DAY.log",
    "host": "$(hostname 2>/dev/null || echo unknown)",
    "output": $ESCAPED,
    "invariants_output_tail": $INV_ESCAPED
}
JSON

SUMMARY="ims nightly $DAY: $STATUS (reports=$RUN_ALL_STATUS, invariants=$INV_STATUS) -> $OUT"
echo "nightly: $SUMMARY"

# --- 4. alert on red --------------------------------------------------------
if [ "$STATUS" != GREEN ]; then
    if [ -n "${IMS_ALERT_HOOK:-}" ]; then
        # The hook's own failure is reported but must not change the verdict:
        # a broken alerter turning a RED day into an ERROR day would lose the
        # only fact that mattered.
        printf '%s\n' "$SUMMARY" | "$IMS_ALERT_HOOK" "$STATUS" "$OUT" \
            || echo "nightly: alert hook '$IMS_ALERT_HOOK' failed (exit $?) — verdict unchanged" >&2
    else
        # Default stub: stderr, so a bare cron entry mails it.
        echo "$SUMMARY" >&2
        echo "nightly: set IMS_ALERT_HOOK to route this somewhere better than cron mail." >&2
    fi
fi

exit "$EXIT"
