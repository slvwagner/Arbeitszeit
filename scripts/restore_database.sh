#!/usr/bin/env bash
set -euo pipefail

XAMPP_ROOT="/opt/lampp"
DATABASE="arbeitszeit"
MYSQL_USER="root"
MYSQL_PASSWORD=""
BACKUP_FILE=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --xampp-root)
      XAMPP_ROOT="$2"
      shift 2
      ;;
    --database)
      DATABASE="$2"
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
    --backup-file)
      BACKUP_FILE="$2"
      shift 2
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
done

MYSQL_BIN="$XAMPP_ROOT/bin/mysql"

if [[ -z "$BACKUP_FILE" ]]; then
  echo "Pass --backup-file /path/to/backup.sql." >&2
  exit 1
fi

if [[ ! -f "$BACKUP_FILE" ]]; then
  echo "Backup file not found: $BACKUP_FILE" >&2
  exit 1
fi

if [[ ! -x "$MYSQL_BIN" ]]; then
  echo "Could not find XAMPP mysql at $MYSQL_BIN." >&2
  exit 1
fi

mysql_args=("-u$MYSQL_USER" "--protocol=tcp" "--default-character-set=utf8mb4")
if [[ -n "$MYSQL_PASSWORD" ]]; then
  mysql_args+=("-p$MYSQL_PASSWORD")
fi

"$MYSQL_BIN" "${mysql_args[@]}" --execute="CREATE DATABASE IF NOT EXISTS \`$DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"$MYSQL_BIN" "${mysql_args[@]}" "$DATABASE" < "$BACKUP_FILE"

echo "Restored database '$DATABASE' from $BACKUP_FILE"
