#!/usr/bin/env bash
set -euo pipefail

XAMPP_ROOT="/opt/lampp"
APP_NAME="Arbeitszeit"
MYSQL_USER="root"
MYSQL_PASSWORD=""
SKIP_DATABASE=0
SKIP_DATA=0
TARGET_PATH=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --xampp-root)
      XAMPP_ROOT="$2"
      shift 2
      ;;
    --app-name)
      APP_NAME="$2"
      shift 2
      ;;
    --mysql-user)
      MYSQL_USER="$2"
      shift 2
      ;;
    --mysql-password)
      MYSQL_PASSWORD="$2"
      shift 2
      ;;
    --skip-database)
      SKIP_DATABASE=1
      shift
      ;;
    --skip-data)
      SKIP_DATA=1
      shift
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
MYSQL_BIN="$XAMPP_ROOT/bin/mysql"

if [[ ! -d "$XAMPP_ROOT/htdocs" ]]; then
  echo "Could not find XAMPP htdocs at $XAMPP_ROOT/htdocs." >&2
  echo "Pass --xampp-root if XAMPP is installed somewhere else." >&2
  exit 1
fi

if [[ ! -x "$MYSQL_BIN" && "$SKIP_DATABASE" -eq 0 ]]; then
  echo "Could not find XAMPP MySQL client at $MYSQL_BIN." >&2
  exit 1
fi

if [[ -z "$TARGET_PATH" ]]; then
  if [[ -d "$XAMPP_ROOT/htdocs/editable" && -w "$XAMPP_ROOT/htdocs/editable" ]]; then
    TARGET_PATH="$XAMPP_ROOT/htdocs/editable/$APP_NAME"
  else
    TARGET_PATH="$XAMPP_ROOT/htdocs/$APP_NAME"
  fi
fi

if [[ "$SKIP_DATABASE" -eq 0 && "$SKIP_DATA" -eq 0 ]]; then
  python3 "$PROJECT_ROOT/scripts/private import script"
fi

"$PROJECT_ROOT/scripts/sync_xampp.sh" --xampp-root "$XAMPP_ROOT" --target-path "$TARGET_PATH"

mysql_args=("-u$MYSQL_USER" "--protocol=tcp" "--default-character-set=utf8mb4")
if [[ -n "$MYSQL_PASSWORD" ]]; then
  mysql_args+=("-p$MYSQL_PASSWORD")
fi

if [[ "$SKIP_DATABASE" -eq 0 ]]; then
  "$MYSQL_BIN" "${mysql_args[@]}" < "$PROJECT_ROOT/database/schema.sql"
  echo "Imported schema into XAMPP MySQL."

  if [[ "$SKIP_DATA" -eq 0 ]]; then
    "$MYSQL_BIN" "${mysql_args[@]}" < "$PROJECT_ROOT/database/private import SQL"
    echo "Imported workbook data from private workbook export."
  fi
fi

relative_url="${TARGET_PATH#"$XAMPP_ROOT/htdocs/"}"
echo "Open http://localhost/$relative_url/"
