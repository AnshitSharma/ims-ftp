#!/usr/bin/env bash
# Runs on the GitHub runner. Bundles every deployable tracked file, streams the bundle to the
# server over ONE ssh connection (the server drops back-to-back connections) and runs apply.sh
# there, which replaces only the files whose bytes differ. Never prints the deploy path.
set -euo pipefail

: "${SSH_HOST:?}" "${SSH_USER:?}" "${SSH_PRIVATE_KEY:?}" "${SSH_KNOWN_HOSTS:?}" "${DEPLOY_PATH:?}"
DRY_RUN="${DRY_RUN:-0}"

# Paths that never deploy: tooling, docs, tests, logs, SQL and this .github folder itself.
excluded() {
  case "$1" in
    .git/*|.github/*|.vscode/*|node_modules/*|dist/*|.claude/*|claude/*|.codex/*|.agent/*) return 0 ;;
    tests/*|docs/*|documentation/*|logs/*|reports/*|tasks/*|"md files"/*) return 0 ;;
    *.md|*.sql|*.txt|.ftpquota|*/.ftpquota|.gitkeep|*/.gitkeep) return 0 ;;
    _archive/*|*/_archive/*|_ServerBuilder_unpatched_probe.php|*/_ServerBuilder_unpatched_probe.php) return 0 ;;
    api/handlers/.htaccess) return 0 ;;
  esac
  return 1
}

stage="$(mktemp -d)"
mkdir -p "$stage/files"
: > "$stage/manifest"
while IFS= read -r -d '' f; do
  excluded "$f" && continue
  [ -f "$f" ] || continue
  printf '%s\n' "$f" >> "$stage/manifest"
done < <(git ls-files -z)
tar -cf - -T "$stage/manifest" | tar -xf - -C "$stage/files"

cp .github/deploy/apply.sh "$stage/apply.sh"
printf '%s' "$DEPLOY_PATH" > "$stage/target"
printf '%s' "${GITHUB_REPOSITORY//\//_}" > "$stage/state-name"
printf '%s' "$DRY_RUN" > "$stage/dry-run"
printf '%s' "${GITHUB_SHA:-unknown}" > "$stage/commit"
echo "bundle: $(wc -l < "$stage/manifest") files, dry run: $DRY_RUN"

mkdir -p ~/.ssh
chmod 700 ~/.ssh
printf '%s\n' "$SSH_PRIVATE_KEY" > ~/.ssh/deploy_key
chmod 600 ~/.ssh/deploy_key
printf '%s\n' "$SSH_KNOWN_HOSTS" > ~/.ssh/known_hosts

bundle="$(mktemp)"
tar -czf "$bundle" -C "$stage" .

# The server resets new SSH connections for a while after recent ones ("kex_exchange_identification:
# Connection reset"). ssh exits 255 only for connection failures, and apply.sh is safe to rerun
# because it compares bytes, so retry those with growing waits.
for attempt in 1 2 3 4 5; do
  rc=0
  ssh -i ~/.ssh/deploy_key \
    -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes \
    -o ConnectTimeout=30 -o ServerAliveInterval=15 \
    "$SSH_USER@$SSH_HOST" \
    'd=$(mktemp -d "$HOME/.deploy-tmp.XXXXXX") || exit 1; tar -xzf - -C "$d" && bash "$d/apply.sh" "$d"; rc=$?; rm -rf "$d"; exit $rc' \
    < "$bundle" || rc=$?
  [ "$rc" -ne 255 ] && exit "$rc"
  [ "$attempt" -eq 5 ] && break
  wait=$((attempt * 30))
  echo "ssh connection failed (attempt $attempt of 5), retrying in ${wait}s"
  sleep "$wait"
done
echo "could not connect to the server after 5 attempts"
exit 255
