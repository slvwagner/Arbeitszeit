#!/usr/bin/env bash
set -euo pipefail

XAMPP_ROOT="/opt/lampp"
TARGET_PATH=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --xampp-root)
      XAMPP_ROOT="$2"
      shift 2
      ;;
    --target-path)
      TARGET_PATH="$2"
      shift 2
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
done

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ ! -d "$XAMPP_ROOT/htdocs" ]]; then
  echo "Could not find XAMPP htdocs at $XAMPP_ROOT/htdocs." >&2
  echo "Pass --xampp-root if XAMPP is installed somewhere else." >&2
  exit 1
fi

if [[ -z "$TARGET_PATH" ]]; then
  if [[ -d "$XAMPP_ROOT/htdocs/editable" && -w "$XAMPP_ROOT/htdocs/editable" ]]; then
    TARGET_PATH="$XAMPP_ROOT/htdocs/editable/Arbeitszeit"
  else
    TARGET_PATH="$XAMPP_ROOT/htdocs/Arbeitszeit"
  fi
fi

mkdir -p "$TARGET_PATH"

directories=(
  assets
  config
  database
  scripts
  src
)

root_files=(
  index.php
  projekte.php
  monat_bearbeiten.php
  monthly.php
  report.php
  private workbook export
  README.md
)

for directory in "${directories[@]}"; do
  source="$PROJECT_ROOT/$directory"
  destination="$TARGET_PATH/$directory"

  if [[ -d "$source" ]]; then
    mkdir -p "$destination"
    cp -a "$source"/. "$destination"/
  fi
done

for file in "${root_files[@]}"; do
  source="$PROJECT_ROOT/$file"
  if [[ -f "$source" ]]; then
    cp -f "$source" "$TARGET_PATH/$file"
  fi
done

echo "Synced Arbeitszeit source to $TARGET_PATH"
echo "XAMPP root: $XAMPP_ROOT"
