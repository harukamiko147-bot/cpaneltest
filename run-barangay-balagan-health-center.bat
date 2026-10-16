@echo off
setlocal

set "APP_DIR=%~dp0"
set "HOST=127.0.0.1"
set "PORT=8000"
set "PHP_EXE=C:\xampp\php\php.exe"

if not exist "%APP_DIR%\index.php" (
  echo App folder not found:
  echo %APP_DIR%
  pause
  exit /b 1
)

if not exist "%PHP_EXE%" (
  echo PHP was not found in PATH.
  echo Expected PHP at:
  echo %PHP_EXE%
  echo Install XAMPP or adjust this launcher to your PHP path.
  pause
  exit /b 1
)

echo Starting Barangay Balagan Health Center on http://%HOST%:%PORT%
echo Document root: %APP_DIR%
start "Barangay Balagan PHP Server" "%PHP_EXE%" -S %HOST%:%PORT% -t "%APP_DIR%"
timeout /t 2 /nobreak
start "" "http://%HOST%:%PORT%"
echo Server started in a separate window.
echo If the browser still shows an old error page, press Reload.

