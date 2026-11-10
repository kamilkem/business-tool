#!/usr/bin/env bash
#
# Hygiene of the adr/ directory, per adr/0000-use-adrs.md and the adr/README.md header.
#
# Checks:
#   1. ADR numbers are continuous (no gaps) and unique,
#   2. no ADR was deleted or renamed/renumbered compared to the base branch,
#   3. every ADR has a "Status:" line,
#   4. every ADR has a row in adr/README.md,
#   5. ADRs ADDED on this branch have the template headings: ## Context, ## Decision, ## Consequences.
#
# Checks 2 and 5 need a base; without BASE_REF (e.g. a push to main) they are skipped.
#
# Run locally:
#   BASE_REF=origin/main .github/scripts/check-adr-hygiene.sh
#   .github/scripts/check-adr-hygiene.sh          # only the checks that need no base
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

ADR_DIR="adr"
README="${ADR_DIR}/README.md"
BASE_REF="${BASE_REF:-}"

ERRORS=()

if [[ ! -d "$ADR_DIR" ]]; then
    echo "ERROR: no ${ADR_DIR}/ directory."
    exit 1
fi

# ADR files: adr/NNNN-*.md (README.md and template.md have no number, so they are left out).
ADR_FILES=()
while IFS= read -r f; do
    ADR_FILES+=("$f")
done < <(find "$ADR_DIR" -maxdepth 1 -type f -name '[0-9][0-9][0-9][0-9]-*.md' | sort)

if ((${#ADR_FILES[@]} == 0)); then
    echo "ERROR: no ADR files in ${ADR_DIR}/ (pattern NNNN-title.md)."
    exit 1
fi

# --- 1. Continuous, unique numbering ------------------------------------------------------
EXPECTED=0
PREVIOUS=""
PREVIOUS_FILE=""
for f in "${ADR_FILES[@]}"; do
    base="$(basename "$f")"
    num="${base:0:4}"
    if [[ "$num" == "$PREVIOUS" ]]; then
        ERRORS+=("Duplicate ADR number ${num}: ${PREVIOUS_FILE} and ${f}
      → One number = one file. Give the new ADR the next free number.")
        continue
    fi
    actual=$((10#$num))
    if ((actual != EXPECTED)); then
        printf -v want '%04d' "$EXPECTED"
        ERRORS+=("Gap in ADR numbering: ${want}-*.md is missing (next found is ${num}-*.md)
      → Numbering must be continuous. Don't skip numbers or delete files;
        a new ADR takes the first free number at the end.")
        EXPECTED=$actual
    fi
    EXPECTED=$((EXPECTED + 1))
    PREVIOUS="$num"
    PREVIOUS_FILE="$f"
done

# --- 3. Status: line ------------------------------------------------------------------------
for f in "${ADR_FILES[@]}"; do
    if ! grep -q -E '^Status:[[:space:]]*[^[:space:]]' "$f"; then
        ERRORS+=("No \"Status:\" line in ${f}
      → Every ADR has a status (Proposed | Accepted | Deprecated | Superseded by [NNNN](...)).
        See ${ADR_DIR}/template.md")
    fi
done

# --- 4. Row in adr/README.md ----------------------------------------------------------------
if [[ ! -f "$README" ]]; then
    ERRORS+=("No ${README}: the ADR index is mandatory.")
else
    for f in "${ADR_FILES[@]}"; do
        base="$(basename "$f")"
        num="${base:0:4}"
        # The table row must link to the file, e.g. | [0001](0001-....md) | Title | Status |
        if ! grep -q -F "(${base})" "$README"; then
            title="$(head -1 "$f" | sed -E 's/^#[[:space:]]*//')"
            ERRORS+=("ADR ${num} has no row in ${README}: ${base}
      → Add to the table:  | [${num}](${base}) | <title> | <status> |
        Title from the file: ${title}")
        fi
    done
fi

# --- 2 and 5. Checks against the base branch ------------------------------------------------
if [[ -n "$BASE_REF" ]]; then
    if ! git rev-parse --verify --quiet "${BASE_REF}^{commit}" >/dev/null; then
        echo "ERROR: base ref '${BASE_REF}' not found."
        exit 1
    fi

    # 2. Deleted or renamed ADRs
    while IFS=$'\t' read -r status path newpath; do
        [[ -z "${status:-}" ]] && continue
        case "$status" in
            D*) ERRORS+=("ADR deleted: ${path}
      → ADRs are never deleted. A decision that no longer holds gets Status: Superseded by [NNNN](...).") ;;
            R*) ERRORS+=("ADR renamed/renumbered: ${path} → ${newpath}
      → An ADR number is a permanent identifier (linked from CLAUDE.md, code and other ADRs).
        Instead of renumbering: a new ADR, and Superseded by on the old one.") ;;
        esac
    done < <(git diff --name-status --find-renames "${BASE_REF}...HEAD" -- "${ADR_DIR}/[0-9][0-9][0-9][0-9]-*.md")

    # 5. Template headings in new ADRs
    while IFS= read -r path; do
        [[ -z "$path" ]] && continue
        [[ -f "$path" ]] || continue
        missing=()
        for heading in '## Context' '## Decision' '## Consequences'; do
            grep -q -F -x "$heading" "$path" || missing+=("$heading")
        done
        if ((${#missing[@]} > 0)); then
            ERRORS+=("New ADR ${path} lacks template headings: ${missing[*]}
      → Copy the structure from ${ADR_DIR}/template.md.")
        fi
    done < <(git diff --name-only --diff-filter=A "${BASE_REF}...HEAD" -- "${ADR_DIR}/[0-9][0-9][0-9][0-9]-*.md")
else
    echo "NOTE: BASE_REF not set; skipping the deleted/renumbered check and the new-ADR headings check."
fi

# --- Result -----------------------------------------------------------------------------------
if ((${#ERRORS[@]} > 0)); then
    echo "ADR hygiene: ${#ERRORS[@]} problem(s) found."
    echo
    for e in "${ERRORS[@]}"; do
        echo "  ✗ ${e}"
        echo
    done
    echo "Rules: ${ADR_DIR}/0000-use-adrs.md, ${ADR_DIR}/README.md and ${ADR_DIR}/template.md."
    exit 1
fi

echo "OK: ${#ADR_FILES[@]} ADRs, continuous numbering, each has a Status and a row in ${README}."
