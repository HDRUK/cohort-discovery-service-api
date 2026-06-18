#!/usr/bin/env bash
#
# test-jira-fix-version.sh
#
# Local stand-in for the "Jira Fix Version" reusable workflow. Runs the same
# logic (extract version, resolve range, extract keys, create versions, tag
# issues) against the current repo, so you can test before pushing a release/*
# branch. Drop this file into any repo and run it.
#
# Modes:
#   (default)  Dry run: extract keys and print a summary. No Jira calls.
#   --mock     Run through every step but PRINT the Jira calls instead of making
#              them. No jq / curl / creds needed. Best for checking key finding.
#   --apply    Actually call Jira. Needs jq, curl and JIRA_* env vars.
#
# Usage:
#   bash test-jira-fix-version.sh --projects DP --ref dev --version 1.12.0
#   bash test-jira-fix-version.sh --projects "DP,GAT" --ref dev --version 1.12.0 --mock
#   RELEASE_JIRA_BASE_URL=https://hdruk.atlassian.net RELEASE_JIRA_USER=you@hdruk.ac.uk \
#   RELEASE_JIRA_TOKEN=*** bash test-jira-fix-version.sh --projects DP --apply
#
# Args:
#   --projects  Comma-separated Jira project keys (required), e.g. DP or DP,GAT
#   --version   Fix-version name. If omitted, derived from a release/* branch.
#   --ref       Git ref to treat as the release HEAD (default: HEAD).
#   --mock      Simulate Jira calls (print, don't send).
#   --apply     Write to Jira for real.
#
set -eo pipefail

PROJECTS=""
VERSION=""
REF="HEAD"
APPLY="false"
MOCK="false"

while [ $# -gt 0 ]; do
  case "$1" in
    --projects) PROJECTS="$2"; shift 2 ;;
    --version)  VERSION="$2"; shift 2 ;;
    --ref)      REF="$2"; shift 2 ;;
    --mock)     MOCK="true"; shift ;;
    --apply)    APPLY="true"; shift ;;
    -h|--help)  grep '^#' "$0" | grep -v '^#!' | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown arg: $1" >&2; exit 1 ;;
  esac
done

if [ -z "$PROJECTS" ]; then
  echo "ERROR: --projects is required (e.g. --projects DP or --projects DP,GAT)" >&2
  exit 1
fi

# Jira creds. Prefer the RELEASE_-prefixed names (matching the org secrets),
# falling back to the plain names for convenience.
JIRA_SERVER="${RELEASE_JIRA_BASE_URL:-${JIRA_BASE_URL:-${JIRA_SERVER:-}}}"
JIRA_USER="${RELEASE_JIRA_USER:-${JIRA_USER:-}}"
JIRA_TOKEN="${RELEASE_JIRA_TOKEN:-${JIRA_TOKEN:-}}"

# --- Step: Extract version from branch -------------------------------------
echo "== Extract version =="
if [ -z "$VERSION" ]; then
  BRANCH_NAME="$REF"
  [ "$REF" = "HEAD" ] && BRANCH_NAME="$(git rev-parse --abbrev-ref HEAD)"
  case "$BRANCH_NAME" in
    release/*)
      VERSION="${BRANCH_NAME#release/}"
      VERSION="${VERSION#v}"
      ;;
    *)
      echo "ERROR: not on a release/* branch ($BRANCH_NAME); pass --version X.Y.Z" >&2
      exit 1
      ;;
  esac
fi
echo "Version: $VERSION"

# --- Step: Resolve release range -------------------------------------------
echo "== Resolve release range =="
BASE=$(git tag -l | grep -E '^v?[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -1 || true)
if [ -n "$BASE" ]; then
  RANGE="$BASE..$REF"
else
  RANGE="$REF"
fi
echo "Base release tag: ${BASE:-<none>}"
echo "Commit range    : $RANGE"

# --- Step: Extract Jira issue keys -----------------------------------------
echo "== Extract Jira issue keys =="
MESSAGES=$(git log $RANGE --format='%s%n%b')
ALL=""
IFS=',' read -ra PROJ <<< "$PROJECTS"
for raw in "${PROJ[@]}"; do
  P=$(echo "$raw" | tr -d '[:space:]' | tr '[:lower:]' '[:upper:]')
  [ -z "$P" ] && continue
  FOUND=$(printf '%s\n' "$MESSAGES" | grep -oE "\b${P}-[0-9]+\b" || true)
  [ -n "$FOUND" ] && ALL="${ALL}${FOUND}"$'\n'
done
KEYS=$(printf '%s' "$ALL" | grep -E '.' | sort -u | tr '\n' ' ' | sed 's/ *$//' || true)
if [ -z "$KEYS" ]; then
  echo "No matching Jira issues in $RANGE for projects: $PROJECTS"
else
  echo "Issues to tag: $KEYS"
fi

# --- Dry run stops here; mock and apply continue ---------------------------
if [ "$APPLY" != "true" ] && [ "$MOCK" != "true" ]; then
  echo "== Dry-run summary =="
  echo "DRY RUN — no changes made. Add --mock (simulate) or --apply (real)."
  echo "Would set fix version '$VERSION' on: ${KEYS:-(none)}"
  exit 0
fi

if [ -z "$KEYS" ]; then
  echo "Nothing to tag; exiting."
  exit 0
fi

if [ "$MOCK" = "true" ]; then
  echo "== MOCK MODE — simulating Jira calls (no network, no jq) =="
  API="${JIRA_SERVER:-https://your-jira.example}"
  API="${API%/}/rest/api/3"
else
  if [ -z "$JIRA_SERVER" ] || [ -z "$JIRA_USER" ] || [ -z "$JIRA_TOKEN" ]; then
    echo "ERROR: set RELEASE_JIRA_BASE_URL, RELEASE_JIRA_USER and RELEASE_JIRA_TOKEN to apply." >&2
    exit 1
  fi
  command -v jq >/dev/null 2>&1 || { echo "ERROR: jq is required for --apply." >&2; exit 1; }
  JIRA_SERVER="${JIRA_SERVER%/}"
  API="$JIRA_SERVER/rest/api/3"
  AUTH=(-u "$JIRA_USER:$JIRA_TOKEN")
fi

# --- Step: Create Jira fix versions ----------------------------------------
echo "== Create Jira fix versions =="
PROJECTS_FOUND=$(printf '%s\n' $KEYS | sed -E 's/-[0-9]+$//' | sort -u)
for P in $PROJECTS_FOUND; do
  if [ "$MOCK" = "true" ]; then
    echo "[$P] MOCK GET  $API/project/$P"
    echo "[$P] MOCK POST $API/version  {\"name\":\"$VERSION\",\"projectId\":\"<resolved-id>\",\"released\":false}"
    echo "[$P] -> would ensure fix version '$VERSION'"
    continue
  fi

  PID=$(curl -fsS "${AUTH[@]}" -H "Accept: application/json" "$API/project/$P" | jq -r '.id')
  if [ -z "$PID" ] || [ "$PID" = "null" ]; then
    echo "[$P] ERROR: could not resolve Jira project" >&2
    exit 1
  fi
  EXISTS=$(curl -fsS "${AUTH[@]}" -H "Accept: application/json" "$API/project/$P/versions" \
    | jq -r --arg V "$VERSION" 'any(.name == $V)')
  if [ "$EXISTS" = "true" ]; then
    echo "[$P] fix version '$VERSION' already exists"
  else
    BODY=$(jq -n --arg n "$VERSION" --argjson pid "$PID" '{name:$n, projectId:$pid, released:false}')
    curl -fsS "${AUTH[@]}" -X POST \
      -H "Accept: application/json" -H "Content-Type: application/json" \
      -d "$BODY" "$API/version" > /dev/null
    echo "[$P] created fix version '$VERSION'"
  fi
done

# --- Step: Add fix version to issues ---------------------------------------
echo "== Add fix version to issues =="
[ "$MOCK" = "true" ] || BODY=$(jq -n --arg v "$VERSION" '{update:{fixVersions:[{add:{name:$v}}]}}')
FAILED=0
for KEY in $KEYS; do
  if [ "$MOCK" = "true" ]; then
    echo "[MOCK] PUT $API/issue/$KEY  {\"update\":{\"fixVersions\":[{\"add\":{\"name\":\"$VERSION\"}}]}}"
    echo "       -> would tag $KEY -> $VERSION"
    continue
  fi

  CODE=$(curl -sS -o /dev/null -w '%{http_code}' "${AUTH[@]}" -X PUT \
    -H "Accept: application/json" -H "Content-Type: application/json" \
    -d "$BODY" "$API/issue/$KEY")
  if [ "$CODE" = "204" ]; then
    echo "tagged $KEY -> $VERSION"
  else
    echo "WARNING: failed to tag $KEY (HTTP $CODE)"
    FAILED=1
  fi
done

if [ "$MOCK" = "true" ]; then
  echo "== Mock run complete — no changes were made =="
  exit 0
fi

if [ "$FAILED" -ne 0 ]; then
  echo "One or more issues could not be tagged." >&2
  exit 1
fi
echo "Done."
