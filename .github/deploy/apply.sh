#!/usr/bin/env bash
# Runs on the server against the bundle deploy.sh streamed over. Replaces only files whose bytes
# differ: each is copied beside its target and renamed over it, so a request never runs a
# half-written file. Deletes files that dropped out of the previous deploy's manifest; anything
# never tracked in git (.env, ims-data, uploads) is never touched. Prints relative paths only.
set -uo pipefail

b="$1"
cd "$HOME" || exit 1
dest="$(cat "$b/target")"
name="$(cat "$b/state-name")"
dry="$(cat "$b/dry-run")"
commit="$(cat "$b/commit")"

case "$dest" in ""|"/"|"$HOME"|"$HOME/") echo "refusing this deploy path"; exit 1 ;; esac
[ -d "$dest" ] || { echo "deploy path does not exist on the server"; exit 1; }

state_dir="$HOME/.deploy-state"
prev="$state_dir/$name.manifest"
umask 022
added=0 updated=0 deleted=0 same=0 failed=0

fail() { echo "FAILED $*"; failed=$((failed + 1)); }
unsafe() { case "$1" in ""|/*|../*|*/../*) return 0 ;; esac; return 1; }

while IFS= read -r f; do
  if unsafe "$f"; then fail "unsafe path: $f"; continue; fi
  s="$b/files/$f"
  t="$dest/$f"
  if [ -f "$t" ] && cmp -s "$s" "$t"; then same=$((same + 1)); continue; fi
  if [ -e "$t" ]; then kind=update; else kind=add; fi
  echo "$kind $f"
  if [ "$dry" != 1 ]; then
    dir="$(dirname "$t")"
    mkdir -p "$dir" || { fail "$f (mkdir)"; continue; }
    if [ -w "$dir" ]; then
      tmp="$dir/.deploy-$$.tmp"
      if ! { cp "$s" "$tmp" &&
             if [ -e "$t" ]; then chmod --reference="$t" "$tmp"; else chmod 644 "$tmp"; fi &&
             mv -f "$tmp" "$t"; }; then
        rm -f "$tmp"; fail "$f"; continue
      fi
    else
      # Read-only folder (api/handlers is 555 on purpose): rewrite in place. Not atomic.
      cat "$s" > "$t" || { fail "$f (read-only folder)"; continue; }
    fi
  fi
  if [ "$kind" = add ]; then added=$((added + 1)); else updated=$((updated + 1)); fi
done < "$b/manifest"

if [ -f "$prev" ]; then
  while IFS= read -r f; do
    unsafe "$f" && continue
    t="$dest/$f"
    [ -f "$t" ] || continue
    echo "delete $f"
    if [ "$dry" != 1 ]; then
      rm -f "$t" || { fail "$f (delete)"; continue; }
    fi
    deleted=$((deleted + 1))
  done < <(LC_ALL=C comm -23 <(LC_ALL=C sort -u "$prev") <(LC_ALL=C sort -u "$b/manifest"))
else
  echo "no previous deploy recorded, so nothing is deleted this time"
fi

echo "added $added, updated $updated, deleted $deleted, unchanged $same, failed $failed"
if [ "$dry" = 1 ]; then echo "dry run: the server was not changed"; exit 0; fi
[ "$failed" -eq 0 ] || exit 1

# Recorded only after a clean run, so a failed delete is retried by the next deploy.
mkdir -p "$state_dir" &&
  cp "$b/manifest" "$prev" &&
  printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$commit" >> "$state_dir/$name.log"
