#!/bin/sh
# nightly.sh — the daily archived battery run.
#
# This is the cron U-X.2 step 2 requires, and the SAME archive is the 30-day
# evidence U-D.3 requires (U-X.2: "this cron is the 30-day evidence U-D.3
# requires -- F-4"). Steps 4, 6 and 7 of the cutover all rest on this one file
# running once a day and leaving a durable record behind.
#
# It writes ONE json record per UTC day to reports/archive/battery-<date>.json.
# Read the accumulated archive with:
#     php scripts/verify/soak_status.php
#
# Usage:
#     sh scripts/ci/nightly.sh            # --quick (the daily run)
#     sh scripts/ci/nightly.sh --gate P8  # a fuller battery (the weekly run)
#
# Exit: 0 if the battery was GREEN, 1 if RED, 2 if it could not run at all.
# A cron that mails on non-zero gives you alert-on-red for free.
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

# Capture stdout+stderr and the exit code without set -e killing us mid-run.
RAW=$("$PHP" "$ROOT/scripts/verify/run_all.php" "$@" 2>&1) && CODE=0 || CODE=$?

case "$CODE" in
    0) STATUS=GREEN ;;
    1) STATUS=RED ;;
    *) STATUS=ERROR ;;
esac

# Escape the captured output for embedding as a JSON string. Doing this in php
# avoids hand-rolling escaping in sh, and php is already a hard dependency here.
ESCAPED=$(printf '%s' "$RAW" | "$PHP" -r 'echo json_encode(stream_get_contents(STDIN));' 2>/dev/null || printf '""')
ARGS_JSON=$(printf '%s' "$*" | "$PHP" -r 'echo json_encode(stream_get_contents(STDIN));' 2>/dev/null || printf '""')

# A day that already has a record is OVERWRITTEN, not appended: soak_status.php
# counts DAYS, and two records for one day would inflate a streak the same way
# F-8's duplicate rows inflated parity's denominator. One day, one verdict.
cat > "$OUT" <<JSON
{
    "run_at": "$STAMP",
    "day": "$DAY",
    "args": $ARGS_JSON,
    "status": "$STATUS",
    "exit_code": $CODE,
    "host": "$(hostname 2>/dev/null || echo unknown)",
    "output": $ESCAPED
}
JSON

echo "nightly: $STATUS (exit $CODE) -> $OUT"
[ "$CODE" -eq 0 ] || exit "$CODE"
