#!/usr/bin/env bash
#
# A migration merged into the base branch has already run on every database that pulled it:
# editing it changes nothing there and only makes schemas drift apart.
#
# Fails if the branch modifies, deletes or renames an api/migrations/Version*.php file that exists
# on the base branch. Adding new migrations is fine.
#
# Run locally:
#   BASE_REF=origin/main .github/scripts/check-merged-migrations.sh
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

BASE_REF="${BASE_REF:-origin/main}"
MIGRATIONS_DIR="api/migrations"

if ! git rev-parse --verify --quiet "${BASE_REF}^{commit}" >/dev/null; then
    echo "ERROR: base ref '${BASE_REF}' not found."
    echo "       In CI: 'git fetch origin <branch>'. Locally: BASE_REF=origin/main $0"
    exit 1
fi

CHANGED=()
while IFS=$'\t' read -r status path newpath; do
    [[ -z "${status:-}" ]] && continue
    case "$status" in
        M*) CHANGED+=("modified: ${path}") ;;
        D*) CHANGED+=("deleted:  ${path}") ;;
        R*) CHANGED+=("renamed:  ${path} -> ${newpath}") ;;
    esac
done < <(git diff --name-status --find-renames "${BASE_REF}...HEAD" -- "${MIGRATIONS_DIR}/Version*.php")

if ((${#CHANGED[@]} == 0)); then
    echo "OK: no migration that exists on ${BASE_REF} was changed."
    exit 0
fi

echo "Migrations guard: this branch changes migrations that already exist on ${BASE_REF}:"
echo
for line in "${CHANGED[@]}"; do
    echo "  x ${line}"
done
cat <<MSG

Instead:
  1. Restore the original file:   git checkout ${BASE_REF} -- <file>
  2. Generate a new migration:    make migration
  3. Read the generated SQL, then: make migrate
MSG
exit 1
