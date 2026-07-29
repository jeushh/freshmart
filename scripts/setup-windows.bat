@echo off
setlocal
cd /d "%~dp0\..\apps\api"
if not exist bootstrap\cache mkdir bootstrap\cache
if not exist storage\framework\cache\data mkdir storage\framework\cache\data
if not exist storage\framework\sessions mkdir storage\framework\sessions
if not exist storage\framework\views mkdir storage\framework\views
if not exist storage\logs mkdir storage\logs
if not exist .env copy .env.example .env
call composer install
if errorlevel 1 exit /b %errorlevel%
findstr /R /C:"^APP_KEY=base64:" .env >nul
if errorlevel 1 (
  php artisan key:generate
  if errorlevel 1 exit /b %errorlevel%
)
php artisan config:clear
set CACHE_STORE=file
php artisan cache:clear
if errorlevel 1 exit /b %errorlevel%
set CACHE_STORE=

cd /d "%~dp0\..\apps\web"
if not exist .env copy .env.example .env
call npm install
if errorlevel 1 exit /b %errorlevel%

echo Setup complete. Run scripts\start-windows.bat
