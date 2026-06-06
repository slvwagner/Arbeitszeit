# Arbeitszeit

Local XAMPP/PHP application for recording working hours and monthly administration.

## Local setup on Linux/XAMPP

The Linux helper scripts default to a common XAMPP installation at `/opt/lampp`.
If `/opt/lampp/htdocs/editable` exists and is writable, the app is installed
there; otherwise it uses the standard `htdocs` directory.

1. Start Apache and MySQL in XAMPP:

   ```bash
   sudo /opt/lampp/lampp start
   ```

2. Sync the project into XAMPP and import the demo database schema:

   ```bash
   bash scripts/setup_xampp.sh
   ```

3. Open the printed local URL, for example `http://localhost/editable/Arbeitszeit/`.

The app uses the XAMPP default MariaDB user `root` without a password. Change
`config/database.php` or pass `--mysql-user` / `--mysql-password` to
`scripts/setup_xampp.sh` if your local database credentials differ.

To only copy the PHP app into XAMPP htdocs:

```bash
bash scripts/sync_xampp.sh
```

You can override paths for your environment:

```bash
bash scripts/setup_xampp.sh --xampp-root /opt/lampp --target-path /opt/lampp/htdocs/editable/Arbeitszeit
```

## Local setup on Windows/XAMPP

1. Start Apache and MySQL in XAMPP.
2. Sync the project into the XAMPP folder and import the demo database schema:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\setup_xampp.ps1
   ```

3. Open `http://localhost/Arbeitszeit/`.

The app uses the XAMPP default MariaDB user `root` without a password. Change `config/database.php` if your local database credentials differ.

The Windows helper scripts try common XAMPP paths such as `D:\xampp` and
`C:\xampp`. You can override the path when needed:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\setup_xampp.ps1 -XamppRoot "D:\xampp"
```

To only copy the PHP app into XAMPP htdocs:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\sync_xampp.ps1
```

## Database setup manually

Linux/XAMPP:

```bash
/opt/lampp/bin/mysql -uroot --protocol=tcp --default-character-set=utf8mb4 < database/schema.sql
```

Windows:

```powershell
D:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --execute="SOURCE D:/Arbeitszeit/database/schema.sql"
```

The repository intentionally does not include private workbook exports, generated
time-entry imports, or production database backups. Keep those outside Git and
import them only into your local/private database.

## Database backup

Create a local private backup of the XAMPP database:

```bash
bash scripts/backup_database.sh
```

By default this writes timestamped SQL dumps to:

```text
/home/slvwagner/slvwagner/DB-Backup/
```

Example output:

```text
/home/slvwagner/slvwagner/DB-Backup/arbeitszeit-2026-06-06_04-40-11.sql
```

Use a different backup folder or database name when needed:

```bash
bash scripts/backup_database.sh --backup-dir /path/to/private/backups --database arbeitszeit
```

Restore a backup with:

```bash
bash scripts/restore_database.sh --backup-file /home/slvwagner/slvwagner/DB-Backup/arbeitszeit-YYYY-MM-DD_HH-MM-SS.sql
```

Database backups contain private working-time data. Keep them outside this Git
repository and do not push them to GitHub.

## License

This project is open source under the [MIT License](LICENSE).
