@echo off
setlocal EnableExtensions EnableDelayedExpansion
cd /d "%~dp0"
title Artic - Localhost Server

echo ======================================
echo Artic PHP + Microsoft SQL Server
echo ======================================
echo.

set "PHP_EXE="
where php >nul 2>nul
if not errorlevel 1 set "PHP_EXE=php"
if not defined PHP_EXE if exist "%~dp0..\..\php\php.exe" set "PHP_EXE=%~dp0..\..\php\php.exe"
if not defined PHP_EXE if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"

if not defined PHP_EXE (
  echo ERROR: PHP was not found.
  echo Add php.exe to PATH or install PHP/XAMPP.
  pause
  exit /b 1
)

echo PHP: %PHP_EXE%
%PHP_EXE% -v
%PHP_EXE% -m | findstr /I /C:"pdo_sqlsrv" >nul
if errorlevel 1 (
  echo.
  echo ERROR: PDO_SQLSRV is not loaded by this PHP installation.
  echo Use the same PHP installation that successfully ran V6.
  pause
  exit /b 1
)

echo PDO_SQLSRV: loaded
if not exist "%~dp0config.local.php" (
  echo ERROR: config.local.php is missing.
  echo The supplied build should contain it.
  pause
  exit /b 1
)

set "ARTIC_DB_SERVER=GABIRU\SQLEXPRESS03"
set "ARTIC_DB_NAME=artic"
set "ARTIC_DB_USER=sa"
set "ARTIC_DB_TRUST_SERVER_CERTIFICATE=1"
set "ARTIC_DB_ENCRYPT=1"
set "ARTIC_PORT=3000"

for /L %%P in (3000,1,3010) do (
  powershell -NoProfile -Command "$c=Get-NetTCPConnection -LocalPort %%P -State Listen -ErrorAction SilentlyContinue; if($null -eq $c){exit 0}else{exit 1}" >nul 2>nul
  if not errorlevel 1 (
    set "ARTIC_PORT=%%P"
    goto :port_found
  )
)

:port_found
echo.
echo SQL target: GABIRU\SQLEXPRESS03 / artic
echo Local port: %ARTIC_PORT%
echo.
echo Health: http://localhost:%ARTIC_PORT%/health.php
echo Setup:  http://localhost:%ARTIC_PORT%/setup.php
echo App:    http://localhost:%ARTIC_PORT%/index.php
echo.
echo Starting server. Keep this window open.
echo.

start "Artic Browser" http://localhost:%ARTIC_PORT%/index.php
%PHP_EXE% -S localhost:%ARTIC_PORT% router.php

if errorlevel 1 (
  echo.
  echo ======================================
  echo Artic server stopped with an error.
  echo ======================================
  pause
)
