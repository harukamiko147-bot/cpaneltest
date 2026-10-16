@echo off
setlocal

set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"
set "MYSQLD_EXE=C:\xampp\mysql\bin\mysqld.exe"
set "MYSQL_INI=C:\xampp\mysql\bin\my.ini"
set "SCHEMA_FILE=%~dp0schema.sql"
set "SCHEMA_FILE_SQL=%~dp0schema.sql"

if not exist "%MYSQL_EXE%" (
  echo MySQL was not found at:
  echo %MYSQL_EXE%
  echo Start or install XAMPP, then run this file again.
  pause
  exit /b 1
)

if not exist "%MYSQLD_EXE%" (
  echo MySQL server executable was not found:
  echo %MYSQLD_EXE%
  pause
  exit /b 1
)

if not exist "%SCHEMA_FILE%" (
  echo Schema file not found:
  echo %SCHEMA_FILE%
  pause
  exit /b 1
)

"%MYSQL_EXE%" -u root --password="%BBHC_DB_PASS%" -e "SELECT 1"
if errorlevel 1 (
  echo Starting the local XAMPP MySQL server...
  start "XAMPP MySQL" /b "%MYSQLD_EXE%" --defaults-file="%MYSQL_INI%"
  timeout /t 5 /nobreak
)

"%MYSQL_EXE%" -u root --password="%BBHC_DB_PASS%" -e "SELECT 1"
if errorlevel 1 (
  echo MySQL could not be started. Check whether port 3306 is already in use.
  pause
  exit /b 1
)

echo Creating or updating the Barangay Balagan Health Center database...
"%MYSQL_EXE%" -u root --password="%BBHC_DB_PASS%" -e "source %SCHEMA_FILE_SQL%"

if errorlevel 1 (
  echo Database setup failed. Make sure MySQL is running in XAMPP.
  pause
  exit /b 1
)

echo Database setup complete.

