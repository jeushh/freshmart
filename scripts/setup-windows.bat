@echo off
setlocal
set "ROOT=%~dp0.."
set "API=%ROOT%\apps\api"
set "WEB=%ROOT%\apps\web"
set "SEED_DATABASE=0"
if /I "%~1"=="--seed" set "SEED_DATABASE=1"
if not "%~1"=="" if /I not "%~1"=="--seed" (
  echo Unknown option: %~1
  echo Usage: scripts\setup-windows.bat [--seed]
  exit /b 2
)

cd /d "%API%"
if not exist bootstrap\cache mkdir bootstrap\cache
if not exist database mkdir database
if not exist storage\framework\cache\data mkdir storage\framework\cache\data
if not exist storage\framework\sessions mkdir storage\framework\sessions
if not exist storage\framework\views mkdir storage\framework\views
if not exist storage\logs mkdir storage\logs
if not exist .env (
  copy .env.example .env
  echo Created apps\api\.env from .env.example.
) else (
  echo Using existing apps\api\.env.
)
call composer install
if errorlevel 1 exit /b %errorlevel%
findstr /R /C:"^APP_KEY=base64:" .env >nul
if errorlevel 1 (
  php artisan key:generate
  if errorlevel 1 exit /b %errorlevel%
  echo Generated a new application key because none was configured.
) else (
  echo Kept the existing application key.
)

set "DB_CONNECTION_VALUE=sqlite"
for /F "tokens=1,* delims==" %%A in ('findstr /B "DB_CONNECTION=" .env') do set "DB_CONNECTION_VALUE=%%B"
set "DB_CONNECTION_VALUE=%DB_CONNECTION_VALUE:"=%"
if /I not "%DB_CONNECTION_VALUE%"=="sqlite" (
  echo This setup helper only creates local SQLite databases.
  echo Configured connection: %DB_CONNECTION_VALUE%
  exit /b 1
)

set "DB_DATABASE_VALUE=database/database.sqlite"
for /F "tokens=1,* delims==" %%A in ('findstr /B "DB_DATABASE=" .env') do set "DB_DATABASE_VALUE=%%B"
set "DB_DATABASE_VALUE=%DB_DATABASE_VALUE:"=%"
set "DB_DATABASE_VALUE=%DB_DATABASE_VALUE:/=\%"
if "%DB_DATABASE_VALUE:~1,1%"==":" (
  set "DATABASE_PATH=%DB_DATABASE_VALUE%"
) else (
  set "DATABASE_PATH=%API%\%DB_DATABASE_VALUE%"
)
for %%I in ("%DATABASE_PATH%") do set "DATABASE_PATH=%%~fI"
for %%I in ("%ROOT%\database\freshmart.sqlite") do set "LEGACY_DATABASE_PATH=%%~fI"

if /I "%DATABASE_PATH%"=="%LEGACY_DATABASE_PATH%" (
  echo Setup stopped: apps\api\.env points to the preserved legacy database:
  echo   %LEGACY_DATABASE_PATH%
  echo Set DB_DATABASE=database/database.sqlite, then run setup again.
  exit /b 1
)

for %%I in ("%DATABASE_PATH%") do if not exist "%%~dpI" mkdir "%%~dpI"
if not exist "%DATABASE_PATH%" (
  type nul > "%DATABASE_PATH%"
  echo Created SQLite database: %DATABASE_PATH%
) else (
  echo Using existing SQLite database: %DATABASE_PATH%
)

php artisan config:clear
set CACHE_STORE=file
php artisan cache:clear
if errorlevel 1 exit /b %errorlevel%
set CACHE_STORE=
php artisan migrate --force
if errorlevel 1 exit /b %errorlevel%

if "%FRESHMART_SEED_DATABASE%"=="1" set "SEED_DATABASE=1"
if "%SEED_DATABASE%"=="1" (
  php artisan db:seed --force
  if errorlevel 1 exit /b %errorlevel%
  echo Seeded roles, local accounts, settings, and configured demo data.
) else (
  echo Skipped seed data. Re-run with --seed when demo/local data is wanted.
)

cd /d "%WEB%"
if not exist .env copy .env.example .env
call npm install
if errorlevel 1 exit /b %errorlevel%

echo Setup complete. Run scripts\start-windows.bat
