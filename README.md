# Vender Online Foundation

Composer-free PHP foundation for shared hosting. Private runtime code stays under `api/`; only `public_html/` is public.

## Commands

```powershell
D:\xampp\php\php.exe tools/run-tests.php
D:\xampp\php\php.exe tools/run-migrations.php
```

The test suite uses the committed pure-PHP harness and does not require Composer.

## Migration integration check

MariaDB/MySQL must be running. Local verification uses database `vo_test` on `127.0.0.1:3306`, user `root`, empty password. Create a private test config directory containing `app.php` and `database.php`, then run:

```powershell
D:\xampp\php\php.exe tools/run-migrations.php --config=C:\path\to\config --path=C:\path\to\migrations
```
