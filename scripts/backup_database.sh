#!/usr/bin/env bash
set -euo pipefail

XAMPP_ROOT="/opt/lampp"
DATABASE="arbeitszeit"
MYSQL_USER="root"
MYSQL_PASSWORD=""
BACKUP_DIR="/home/slvwagner/slvwagner/DB-Backup"

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
    --backup-dir)
      BACKUP_DIR="$2"
      shift 2
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
done

MYSQLDUMP_BIN="$XAMPP_ROOT/bin/mysqldump"

if [[ ! -x "$MYSQLDUMP_BIN" ]]; then
  echo "Could not find XAMPP mysqldump at $MYSQLDUMP_BIN." >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"

timestamp="$(date +%Y-%m-%d_%H-%M-%S)"
backup_file="$BACKUP_DIR/${DATABASE}-${timestamp}.sql"

mysql_args=("-u$MYSQL_USER" "--protocol=tcp" "--default-character-set=utf8mb4")
if [[ -n "$MYSQL_PASSWORD" ]]; then
  mysql_args+=("-p$MYSQL_PASSWORD")
fi

"$MYSQLDUMP_BIN" "${mysql_args[@]}" \
  --single-transaction \
  --triggers \
  "$DATABASE" > "$backup_file"

echo "Created database backup: $backup_file"
