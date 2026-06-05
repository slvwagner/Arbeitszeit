# Arbeitszeit

Local XAMPP/PHP application for recording working hours and monthly administration.

## Local setup

1. Start Apache and MySQL in XAMPP.
2. Sync the project into this computer's XAMPP folder and import the database, including `private workbook export` data:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\setup_xampp.ps1
   ```

3. Open `http://localhost/Arbeitszeit/`.

The app uses the XAMPP default MariaDB user `root` without a password. Change `config/database.php` if your local database credentials differ.

On this computer the scripts auto-detect `D:\xampp`. You can override it when needed:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\setup_xampp.ps1 -XamppRoot "D:\xampp"
```

To only copy the PHP app into XAMPP htdocs:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\sync_xampp.ps1
```

## Import workbook data

Generate an import SQL file from `private workbook export`:

```powershell
python .\scripts\private import script
```

If your current terminal still points `python` at the Microsoft Store alias after installation, open a new terminal or use:

```powershell
& "$env:LOCALAPPDATA\Programs\Python\Python313\python.exe" .\scripts\private import script
```

Apply the generated SQL:

```powershell
D:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --execute="SOURCE D:/Arbeitszeit/database/private import SQL"
```

The import uses upserts. Existing rows with the same employee, project, and date are updated; unrelated manual rows are preserved.
