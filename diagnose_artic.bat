@echo off
setlocal EnableExtensions
cd /d "%~dp0"

set "PHP_EXE=C:\xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=php"

echo ==========================================
echo ARTIC / MSSQL DIAGNOSTIC
 echo ==========================================

echo.
echo === PHP ===
%PHP_EXE% -v

echo.
echo === PHP SQL Server driver ===
%PHP_EXE% -r "echo 'pdo_sqlsrv='.(extension_loaded('pdo_sqlsrv')?'YES':'NO').PHP_EOL; echo 'sqlsrv='.(extension_loaded('sqlsrv')?'YES':'NO').PHP_EOL; echo 'drivers='; print_r(PDO::getAvailableDrivers());"

echo.
echo === SQL Server Express services ===
echo MSSQL$SQLEXPRESS03:
sc query "MSSQL$SQLEXPRESS03" | findstr /I "STATE" 2>nul
if errorlevel 1 echo Service not found.
echo SQLBrowser:
sc query "SQLBrowser" | findstr /I "STATE" 2>nul
if errorlevel 1 echo SQL Browser service not found or unavailable.

echo.
echo === Config ===
if exist "config.local.php" (
  echo config.local.php: FOUND
) else (
  echo config.local.php: MISSING - use setup.php
)

echo.
echo === HTTP ===
where curl >nul 2>nul
if errorlevel 1 (
  echo curl unavailable. Start the site and open health.php manually.
) else (
  curl -s http://localhost:8000/health.php
)

echo.
echo === Optional sqlcmd test ===
where sqlcmd >nul 2>nul
if errorlevel 1 (
  echo sqlcmd is not installed/in PATH. This is optional.
) else (
  echo Run this interactively with the correct credentials:
  echo sqlcmd -S localhost\SQLEXPRESS03 -d artic -U sa -P "YOUR_PASSWORD" -Q "SELECT @@SERVERNAME, DB_NAME();"
)

echo.
echo Open setup.php for the exact PHP-to-MSSQL error:
echo http://localhost:8000/setup.php
pause
