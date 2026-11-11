#!/usr/bin/env bash
#
# Blocks "green CI by silencing the check" (CLAUDE.md: "Fix the types; don't add ignores").
# Looks only at the diff against the merge base (BASE...HEAD), so suppressions that already exist
# need no grandfathering: only NEW ones count.
#
# Fails if the branch:
#   1. adds a PHPStan baseline file, or new entries under `ignoreErrors:` in phpstan.dist.neon,
#   2. adds @phpstan-ignore, @psalm-suppress, markTestSkipped, markTestIncomplete,
#      eslint-disable / oxlint-disable, @ts-ignore / @ts-expect-error / @ts-nocheck,
#      it.skip( / describe.skip( / test.skip(,
#   3. adds an entry under Deptrac `skip_violations:` in api/deptrac.yaml,
#   4. lowers the number of test methods (net).
#
# Escape hatch: ALLOW_SUPPRESSIONS=1 (the workflow sets it when the PR has the
# `intentional-exception` label). The reason goes in a comment next to the suppression and in the
# PR description.
#
# Run locally:
#   BASE_REF=origin/main .github/scripts/check-suppressions.sh
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

# A missing tool would make every check below pass silently (empty output looks like "nothing
# found"), so fail closed.
for tool in awk git grep sort comm; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "ERROR: '${tool}' is not available; the guard cannot check the diff." >&2
        exit 1
    fi
done

BASE_REF="${BASE_REF:-origin/main}"

if ! git rev-parse --verify --quiet "${BASE_REF}^{commit}" >/dev/null; then
    echo "ERROR: base ref '${BASE_REF}' not found."
    echo "       In CI: 'git fetch origin <branch>'. Locally: BASE_REF=origin/main $0"
    exit 1
fi

if [[ "${ALLOW_SUPPRESSIONS:-0}" == "1" ]]; then
    echo "SKIPPED: the PR has the 'intentional-exception' label."
    echo "Each suppression needs a comment with its reason, and the PR description must list it."
    exit 0
fi

FINDINGS=()

# --- 1. PHPStan baseline and ignoreErrors ---------------------------------------------------
while IFS= read -r file; do
    [[ -z "$file" ]] && continue
    FINDINGS+=("Added a PHPStan baseline: ${file}
      -> Fix the reported errors instead of freezing them.")
done < <(git diff --name-only --diff-filter=A "${BASE_REF}...HEAD" -- '*phpstan-baseline*')

# Prints the non-comment lines under a top-level-ish YAML/NEON key ($2) of file $1 at revision $3,
# normalised, one per line. Works for both `ignoreErrors:` (phpstan) and `skip_violations:` (deptrac).
section_entries() {
    local file="$1" key="$2" rev="$3"
    git show "${rev}:${file}" 2>/dev/null | awk -v key="$key" '
        $0 ~ "^[[:space:]]*" key ":[[:space:]]*$" { inside = 1; indent = match($0, /[^ ]/); next }
        inside {
            if ($0 ~ /^[[:space:]]*$/ || $0 ~ /^[[:space:]]*#/) { next }
            if (match($0, /[^ ]/) <= indent) { inside = 0; next }
            line = $0
            gsub(/[[:space:]]+/, " ", line); sub(/^ /, "", line); sub(/ $/, "", line)
            print line
        }
    ' | sort
}

new_section_entries() {
    comm -13 <(section_entries "$1" "$2" "$BASE_REF") <(section_entries "$1" "$2" HEAD) || true
}

NEW_IGNORES="$(new_section_entries api/phpstan.dist.neon ignoreErrors)"
if [[ -n "${NEW_IGNORES//[[:space:]]/}" ]]; then
    FINDINGS+=("Added entries under ignoreErrors in api/phpstan.dist.neon:
$(printf '%s\n' "$NEW_IGNORES" | sed 's/^/         /')
      -> Fix the types instead.")
fi

# --- 2. Inline suppressions in added lines -------------------------------------------------
# Only added lines, without the guard scripts and workflows (they name the patterns), Markdown
# (docs quote the patterns to forbid them) and generated files (routeTree.gen.ts, schema.d.ts).
ADDED_LINES="$(git diff --unified=0 "${BASE_REF}...HEAD" -- . \
        ':(exclude).github/scripts' ':(exclude).github/workflows' ':(exclude)*.md' \
        ':(exclude)*.gen.ts' ':(exclude)frontend/packages/api-client/src/schema.d.ts' \
    | grep -E '^\+' | grep -v -E '^\+\+\+' || true)"

check_added() {
    local label="$1" regex="$2" hint="$3" hits
    hits="$(printf '%s\n' "$ADDED_LINES" | grep -E -- "$regex" || true)"
    if [[ -n "${hits//[[:space:]]/}" ]]; then
        FINDINGS+=("Added ${label}
      -> ${hint}
$(printf '%s\n' "$hits" | head -20 | sed 's/^/         /')")
    fi
}

check_added "@phpstan-ignore" '@phpstan-ignore' \
    "Fix the types; don't silence PHPStan inline."
check_added "@psalm-suppress" '@psalm-suppress' \
    "Fix the types; don't silence static analysis inline."
check_added "a skipped or incomplete PHPUnit test" 'markTest(Skipped|Incomplete)|#\[(Skip|RequiresPhp)' \
    "A skipped test is a missing test. Fix it or remove the feature."
check_added "a lint suppression" '(eslint|oxlint)-disable' \
    "Fix the code instead of turning the rule off."
check_added "a TypeScript suppression" '@ts-(ignore|expect-error|nocheck)' \
    "Fix the types instead."
check_added "a skipped JS test" '(^|[^A-Za-z0-9_$])(it|describe|test)\.skip\(|(^|[^A-Za-z0-9_$>-])x(it|describe)[[:space:]]*\(' \
    "Don't commit skipped tests; fix them."

# --- 3. Deptrac skip_violations ------------------------------------------------------------
NEW_SKIPS="$(new_section_entries api/deptrac.yaml skip_violations)"
if [[ -n "${NEW_SKIPS//[[:space:]]/}" ]]; then
    FINDINGS+=("Added entries under skip_violations in api/deptrac.yaml:
$(printf '%s\n' "$NEW_SKIPS" | sed 's/^/         /')
      -> A layer violation is fixed by moving the code (CLAUDE.md, dependency rules).")
fi

# --- 4. Net drop in test methods -----------------------------------------------------------
test_count() {
    git grep -h -I -E 'function test_?[A-Za-z0-9_]*\(|#\[Test\]' "$1" -- api/tests 2>/dev/null \
        | wc -l | tr -d ' '
}

BASE_TESTS="$(test_count "$BASE_REF")"
HEAD_TESTS="$(test_count HEAD)"
if ((HEAD_TESTS < BASE_TESTS)); then
    FINDINGS+=("Test methods went down: ${BASE_TESTS} -> ${HEAD_TESTS} (net -$((BASE_TESTS - HEAD_TESTS))).
      -> Deleting a test to get CI green is not allowed. If the tested feature is gone,
         say so in the PR description and add the 'intentional-exception' label.")
fi

# --- Result --------------------------------------------------------------------------------
if ((${#FINDINGS[@]} > 0)); then
    echo "Suppressions guard: ${#FINDINGS[@]} finding(s) in ${BASE_REF}...HEAD"
    echo
    for finding in "${FINDINGS[@]}"; do
        echo "  x ${finding}"
        echo
    done
    echo "If this is a deliberate, justified exception: add the 'intentional-exception' label to the"
    echo "PR, explain it in a comment next to the suppression and list it in the PR description."
    exit 1
fi

echo "OK: no new suppressions and no fewer tests (base ${BASE_REF}, tests ${BASE_TESTS} -> ${HEAD_TESTS})."
