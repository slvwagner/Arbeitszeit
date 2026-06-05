# Arbeitszeit

Local XAMPP/PHP application for recording working hours and monthly administration.

## Local setup

1. Start Apache and MySQL in XAMPP.
2. Import the schema:

   ```powershell
   E:\Software\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --execute="SOURCE E:/Software/xampp/htdocs/Arbeitszeit/database/schema.sql"
   ```

3. Open `http://localhost/Arbeitszeit/`.

The app uses the XAMPP default MariaDB user `root` without a password. Change `config/database.php` if your local database credentials differ.

## Import workbook data

Generate an import SQL file from `private workbook export`:

```powershell
python .\scripts\private import script
```

Apply the generated SQL:

```powershell
E:\Software\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --execute="SOURCE D:/Projects/Arbeitszeit/database/private import SQL"
```

The import uses upserts. Existing rows with the same employee, project, and date are updated; unrelated manual rows are preserved.
